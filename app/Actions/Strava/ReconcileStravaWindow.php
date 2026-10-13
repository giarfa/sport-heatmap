<?php

namespace App\Actions\Strava;

use App\Data\StravaActivity;
use App\Data\SyncOutcome;
use App\Enums\ActivitySource;
use App\Models\Activity;
use App\Models\User;
use App\Support\Garmin\StartTimeMatcher;
use App\Support\Heatmap\HeatmapWindow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Applies one fetched window to the database: upsert on `strava_activity_id`,
 * delete Strava-sourced activities inside the window Strava no longer returns,
 * and leave every Garmin-sourced activity alone.
 *
 * No authorization check here — the caller (the sync command) has already
 * resolved which athletes may sync.
 */
class ReconcileStravaWindow
{
    public function __construct(private readonly StartTimeMatcher $startTimeMatcher) {}

    /**
     * @param  array<int, StravaActivity>  $activities
     */
    public function __invoke(User $user, HeatmapWindow $window, array $activities): SyncOutcome
    {
        return DB::transaction(function () use ($user, $window, $activities): SyncOutcome {
            $upserted = 0;
            $seenIds = [];

            $fetchedIds = array_map(static fn (StravaActivity $activity): int => $activity->stravaActivityId, $activities);

            /*
             * One query for every stored row the decisions below can touch: the
             * window (for late-twin linking) plus any row already carrying a
             * fetched id (a twin whose date moved out of the window). Loading them
             * up front keeps the loop free of per-activity queries.
             */
            $storedRows = $user->activities()
                ->select(['id', 'source', 'strava_activity_id', 'start_date_local', 'activity_date'])
                ->where(function (Builder $query) use ($window, $fetchedIds): void {
                    $query->whereBetween('activity_date', [$window->startsOn, $window->endsOn])
                        ->orWhereIn('strava_activity_id', $fetchedIds);
                })
                ->get();

            $garminOwnedIds = $storedRows
                ->filter(fn (Activity $row): bool => $row->isGarminSourced() && $row->strava_activity_id !== null)
                ->mapWithKeys(fn (Activity $row): array => [$row->strava_activity_id => true])
                ->all();

            $storedStravaIds = $storedRows
                ->whereNotNull('strava_activity_id')
                ->mapWithKeys(fn (Activity $row): array => [$row->strava_activity_id => true])
                ->all();

            $unlinkedGarmin = $storedRows
                ->filter(fn (Activity $row): bool => $row->isGarminSourced() && $row->strava_activity_id === null)
                ->keyBy('id');

            foreach ($activities as $activity) {
                $seenIds[] = $activity->stravaActivityId;

                /*
                 * Garmin wins permanently. The owner chose Garmin's values for this
                 * twin; upserting here — even as a no-op — would write Strava's
                 * values and source back over that choice every night. That includes
                 * its pace, ascent and heart rate: they came with the CSV or stay as
                 * stored, and no later step could restore them (FR-002).
                 */
                if (isset($garminOwnedIds[$activity->stravaActivityId])) {
                    continue;
                }

                /*
                 * A Strava activity that turns up after its Garmin twin was imported
                 * as new is linked, not added (decision 7baf70c6656a936f): creating
                 * it would count the same run twice on the map. Only the Strava id
                 * is stored — Garmin's values stay — and the row is claimed so a
                 * second Strava activity cannot link to it too. Only that one
                 * column is filled, so nothing else on the row can change — the
                 * run metrics Strava reports for this activity are not copied in,
                 * and a metric the CSV left empty stays empty.
                 */
                if (! isset($storedStravaIds[$activity->stravaActivityId])) {
                    $twin = $this->startTimeMatcher->closest($activity->startDateLocal, $unlinkedGarmin);

                    if ($twin !== null) {
                        $twin->fill(['strava_activity_id' => $activity->stravaActivityId])->save();
                        $unlinkedGarmin->forget($twin->id);

                        continue;
                    }
                }

                $record = $user->activities()->updateOrCreate(
                    ['strava_activity_id' => $activity->stravaActivityId],
                    $activity->toAttributes(),
                );

                /*
                 * updateOrCreate on an unchanged row issues no UPDATE, so
                 * wasChanged() is false. Counting it anyway would report daily
                 * churn that never happened and make the idempotency criterion
                 * untestable from sync_runs.
                 */
                if ($record->wasRecentlyCreated || $record->wasChanged()) {
                    $upserted++;
                }
            }

            /*
             * The window bounds are what keep this from deleting the athlete's
             * history outside the visible range. $seenIds must come from the
             * complete fetch — reconciling page by page would delete every
             * activity that happened to be on another page. An empty $seenIds is
             * a legitimate "no runs in the window" and correctly deletes the
             * whole window: whereNotIn with an empty array matches every row.
             *
             * Only Strava-sourced rows are the sync's to delete (FR-002). A
             * Garmin-new run never existed on Strava, and a Garmin-won twin
             * survives Strava deleting its original: both belong to the import.
             */
            $deleted = $user->activities()
                ->where('source', ActivitySource::Strava)
                ->whereBetween('activity_date', [$window->startsOn, $window->endsOn])
                ->whereNotIn('strava_activity_id', $seenIds)
                ->delete();

            return new SyncOutcome($upserted, $deleted);
        });
    }
}
