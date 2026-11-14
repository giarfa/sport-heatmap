<?php

namespace App\Actions\Garmin;

use App\Data\Garmin\GarminApiActivity;
use App\Data\Garmin\GarminSyncCounts;
use App\Data\Garmin\GarminSyncResult;
use App\Enums\ActivitySource;
use App\Enums\GarminSyncFailure;
use App\Enums\GarminSyncOutcome;
use App\Enums\GarminSyncTrigger;
use App\Exceptions\Garmin\GarminApiException;
use App\Exceptions\Garmin\GarminSyncAlreadyRunningException;
use App\Models\Activity;
use App\Models\GarminSync;
use App\Models\User;
use App\Support\Garmin\GarminPersonalApiClient;
use App\Support\Garmin\GarminRunningTypes;
use App\Support\Garmin\StartTimeMatcher;
use App\Support\Heatmap\HeatmapGridCache;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reads the owner's runs from Garmin Connect and writes them into `activities`: one
 * lock, one recorded attempt, a window, pages, and for each running activity a match,
 * a link to a CSV run, or a new run.
 */
final readonly class SyncGarminActivities
{
    private const LOCK_KEY = 'garmin:sync';

    /**
     * Longer than any sync should take (20 s per call, 100 runs a page), so the lock only
     * outlives a process that died without releasing it.
     */
    private const LOCK_SECONDS = 1_800;

    /**
     * 20,000 activities. A page that is full this many times in a row is an API that
     * ignores `start`, not a longer history, and looping on it would never end.
     */
    private const MAX_PAGES = 200;

    public function __construct(
        private GarminPersonalApiClient $client,
        private StartTimeMatcher $startTimeMatcher,
        private HeatmapGridCache $heatmapGridCache,
    ) {}

    /**
     * @throws GarminSyncAlreadyRunningException when another sync holds the lock; nothing is recorded or called
     */
    public function __invoke(User $owner, GarminSyncTrigger $trigger): GarminSyncResult
    {
        /*
         * The default store on purpose: the lock holds no secret, and the database cache
         * already has its `cache_locks` table. The token is the thing that must stay out
         * of the default store, not this. Released in `finally`, so a crash in the
         * middle of a page cannot block the next sync until the lock's own timeout.
         */
        $lock = Cache::lock(self::LOCK_KEY, self::LOCK_SECONDS);

        if (! $lock->get()) {
            throw GarminSyncAlreadyRunningException::make();
        }

        try {
            return $this->sync($owner, $trigger);
        } finally {
            $lock->release();
        }
    }

    private function sync(User $owner, GarminSyncTrigger $trigger): GarminSyncResult
    {
        /*
         * A process killed mid-sync (a PHP timeout in a web request is a fatal error, so
         * `finally` never runs) leaves its row `running` for good. The lock has expired by
         * the time a row is this old, so nobody can still be working on it: it is closed as
         * an unexpected failure, which is what it was.
         */
        GarminSync::query()
            ->where('outcome', GarminSyncOutcome::Running->value)
            ->where('started_at', '<', now()->subSeconds(self::LOCK_SECONDS))
            ->update([
                'outcome' => GarminSyncOutcome::Failed->value,
                'failure_cause' => GarminSyncFailure::Unexpected->value,
                'finished_at' => now(),
            ]);

        $fullHistory = $this->needsFullHistory($owner);

        $attempt = GarminSync::query()->create([
            'trigger' => $trigger,
            'started_at' => now(),
            'outcome' => GarminSyncOutcome::Running,
            'full_history' => $fullHistory,
        ]);

        $counts = new GarminSyncCounts;
        $failure = null;

        try {
            $startDate = $fullHistory ? null : $this->windowStart($owner);
            /*
             * "Today" is the owner's day, not the server's: `startTimeLocal` is the runner's
             * wall clock, so a run done at 00:10 in Rome while UTC is still yesterday would
             * otherwise look like tomorrow and be skipped as future-dated.
             */
            $today = now()->setTimezone((string) config('heatmap.display_timezone'))->format('Y-m-d');
            $limit = (int) config('services.garmin.page_size');

            for ($page = 0; ; $page++) {
                if ($page >= self::MAX_PAGES) {
                    throw GarminApiException::unexpected('activity search');
                }

                $activities = $this->client->activities($page * $limit, $limit, $startDate);
                $counts = $counts->plus($this->storePage($owner, $activities, $today));

                if (count($activities) < $limit) {
                    break;
                }
            }
        } catch (GarminApiException $exception) {
            $failure = $exception->cause;

            /*
             * The cause and the status are the whole context: the exception's own message
             * is fixed text, but nothing else about the call is logged either, so a
             * request URL or a body can never reach a log line through this path.
             */
            Log::warning('Garmin sync failed.', ['cause' => $failure->value, 'status' => $exception->status]);
        } catch (Throwable $exception) {
            /*
             * A database or programming error, not Garmin's: recorded as `unexpected` and
             * reported, so the owner sees a failed attempt instead of a stuck "running"
             * row, and the scheduler is not handed a stack trace.
             */
            $failure = GarminSyncFailure::Unexpected;
            report($exception);
        }

        /*
         * Forgotten after a failure too: pages before the one that failed were committed,
         * so every cached window, the intensity scale and the trend would otherwise show
         * the data from before them.
         */
        if ($counts->wroteRuns()) {
            $this->heatmapGridCache->forgetAthlete($owner);
        }

        $outcome = $failure === null ? GarminSyncOutcome::Succeeded : GarminSyncOutcome::Failed;

        $attempt->fill([
            'finished_at' => now(),
            'outcome' => $outcome,
            'failure_cause' => $failure,
            ...$counts->toColumns(),
        ])->save();

        return new GarminSyncResult($outcome, $counts, $fullHistory, $failure);
    }

    /**
     * Whether this attempt reads the whole history.
     *
     * Not only "no run has a Garmin id": a first sync that failed half-way stored some
     * runs with an id, and shrinking to the last 7 days of them would leave the older
     * history unread for good. Until one full-history attempt has succeeded, every
     * attempt reads everything.
     */
    private function needsFullHistory(User $owner): bool
    {
        $hasSyncedRun = $owner->activities()->whereNotNull('garmin_activity_id')->exists();

        return ! $hasSyncedRun
            || ! GarminSync::query()->succeeded()->where('full_history', true)->exists();
    }

    /**
     * The first day a later sync reads: seven days before the newest run that came from
     * Garmin.
     *
     * Anchoring on the newest stored run rather than on "7 days ago" is what bridges a
     * run of failed nightly syncs: after a week of failures the window still starts
     * where the last good data ends. It always covers at least the last 7 days, which is
     * the span in which a run renamed or edited on Garmin is updated here.
     *
     * No end date is sent. The API publishes neither a maximum window nor whether
     * `endDate` is inclusive, and an exclusive end would hide a run done today; the
     * activity search answers newest first, so an open end costs nothing.
     */
    private function windowStart(User $owner): CarbonImmutable
    {
        $newest = $owner->activities()->whereNotNull('garmin_activity_id')->max('activity_date');

        return CarbonImmutable::parse((string) $newest)->subDays((int) config('services.garmin.resync_days'));
    }

    /**
     * Classify one page, then write its runs in a single transaction.
     *
     * @param  array<int, array<string, mixed>>  $payloads
     */
    private function storePage(User $owner, array $payloads, string $today): GarminSyncCounts
    {
        $ignored = 0;
        $futureDated = 0;
        $invalid = 0;
        $incoming = [];

        foreach ($payloads as $payload) {
            /*
             * The type is decided before the fields: a strength session has no distance,
             * and asking for a run out of it would count it invalid instead of ignored.
             */
            if (GarminRunningTypes::sportTypeForTypeKey(GarminApiActivity::typeKeyOf($payload)) === null) {
                $ignored++;

                continue;
            }

            $activity = GarminApiActivity::fromApiPayload($payload);

            if ($activity === null) {
                $invalid++;

                continue;
            }

            if ($activity->startDateLocal->format('Y-m-d') > $today) {
                $futureDated++;

                continue;
            }

            $incoming[] = $activity;
        }

        /*
         * One transaction per page, not per sync: a failure on page N keeps pages 1..N-1,
         * which is what lets a cut-off first sync resume instead of starting over. The
         * counts are added after the commit, so a page that rolled back never inflates
         * the totals of the attempt.
         */
        $written = $incoming === []
            ? new GarminSyncCounts
            : DB::transaction(fn (): GarminSyncCounts => $this->writeRuns($owner, $incoming));

        return $written->plus(new GarminSyncCounts(ignored: $ignored, futureDated: $futureDated, invalid: $invalid));
    }

    /**
     * Match, link or create each run of a page.
     *
     * @param  array<int, GarminApiActivity>  $incoming
     */
    private function writeRuns(User $owner, array $incoming): GarminSyncCounts
    {
        $dates = array_map(static fn (GarminApiActivity $activity): CarbonImmutable => $activity->activityDate, $incoming);
        $ids = array_map(static fn (GarminApiActivity $activity): int => $activity->activityId, $incoming);

        /*
         * One query for the whole page, never one per activity. The owner's runs on the
         * page's dates are the twin candidates (the (user_id, activity_date) index serves
         * them); the OR on the id finds a run Garmin has since moved to another date, which
         * the date range alone would miss and then try to create again against the unique
         * index.
         */
        $stored = $owner->activities()
            ->where(function (Builder $query) use ($dates, $ids): void {
                $query->onDaysBetween(min($dates), max($dates))
                    ->orWhereIn('garmin_activity_id', $ids);
            })
            ->get();

        $byGarminId = [];
        $unlinked = [];

        foreach ($stored as $run) {
            if ($run->garmin_activity_id !== null) {
                $byGarminId[$run->garmin_activity_id] = $run;
            } else {
                $unlinked[$run->id] = $run;
            }
        }

        $new = 0;
        $updated = 0;
        $linked = 0;

        foreach ($incoming as $activity) {
            $existing = $byGarminId[$activity->activityId] ?? null;

            if ($existing !== null) {
                /*
                 * The API is authoritative for a run that carries its id: every column it
                 * owns is compared, and the row is written only when one differs, so a
                 * second sync moves no `updated_at`. A metric the API leaves empty — one of
                 * the three run metrics or one of the extended ones — is not in the
                 * attributes, so a value backfilled from the CSV is never blanked. The
                 * extended values are rounded at the DTO boundary, so a second sync sees
                 * no difference and counts nothing `updated`.
                 */
                $existing->fill($activity->toRunAttributes())->forceFill(['source' => ActivitySource::GarminApi]);

                if ($existing->isDirty()) {
                    $existing->save();
                    $updated++;
                }

                continue;
            }

            $twin = $this->startTimeMatcher->closest($activity->startDateLocal, $unlinked);

            if ($twin !== null) {
                /*
                 * The same run, imported from the CSV before the API knew it: it gains the
                 * id and Garmin's values, and no review is shown. A metric the API does not
                 * carry, the CSV-only Training Stress Score above all, is not in the
                 * attributes and so is kept. Taken out of the
                 * candidates so a second activity in the same minute cannot claim it too.
                 */
                $twin->fill($activity->toRunAttributes())->forceFill($this->identity($activity))->save();
                unset($unlinked[$twin->id]);
                $byGarminId[$activity->activityId] = $twin;
                $linked++;

                continue;
            }

            $run = $owner->activities()->make($activity->toRunAttributes());
            $run->forceFill($this->identity($activity))->save();
            $byGarminId[$activity->activityId] = $run;
            $new++;
        }

        return new GarminSyncCounts(new: $new, updated: $updated, linked: $linked);
    }

    /**
     * The two columns no fill() may set: where a run came from and which Garmin activity
     * it is.
     *
     * @return array{source: ActivitySource, garmin_activity_id: int}
     */
    private function identity(GarminApiActivity $activity): array
    {
        return ['source' => ActivitySource::GarminApi, 'garmin_activity_id' => $activity->activityId];
    }
}
