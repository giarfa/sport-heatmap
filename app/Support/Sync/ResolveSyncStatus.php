<?php

namespace App\Support\Sync;

use App\Data\SyncStatus;
use App\Enums\ActivitySource;
use App\Enums\SyncRunStatus;
use App\Models\SyncRun;
use App\Models\User;
use App\Support\Heatmap\HeatmapWindow;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * The only place the dashboard learns about `sync_runs`. Three small, trivially
 * indexed queries in, one {@see SyncStatus} out.
 */
final readonly class ResolveSyncStatus
{
    public function __invoke(User $athlete, HeatmapWindow $window, ?CarbonInterface $now = null): SyncStatus
    {
        $now = $now !== null ? Carbon::instance($now) : Carbon::now();

        if ($athlete->isCsvOnly()) {
            return $this->forCsvOnly($athlete, $window);
        }

        /*
         * sync_runs carries no user_id: this is a single-athlete instance and
         * the sync is instance-wide, so the most recent run and the most
         * recent successful run are read independently of which athlete is
         * viewing. The athlete argument scopes query 3 only.
         */
        $lastRun = SyncRun::query()->latest('started_at')->first();

        $lastSuccessfulRun = SyncRun::query()
            ->where('status', SyncRunStatus::Succeeded)
            ->latest('started_at')
            ->first();

        /*
         * activities_upserted is a churn counter, not a count of what is
         * visible now — the sync command deliberately does not count rows it
         * left unchanged, so an idempotent re-run of an unchanged window would
         * report 0 runs here. The count the athlete needs is read from
         * activities inside the window instead.
         */
        $runsInWindow = $this->runsInWindow($athlete, $window);

        return SyncStatus::from($lastRun, $lastSuccessfulRun, $runsInWindow, $now);
    }

    private function forCsvOnly(User $athlete, HeatmapWindow $window): SyncStatus
    {
        /*
         * No sync_runs query at all: they record the instance-wide Strava
         * sync, which never selects an athlete without a Strava connection. A
         * failed or stale sync of someone else must not put a warning over a
         * map that only imports change.
         *
         * The latest import is derived from the newest updated_at among the
         * athlete's garmin_csv activities rather than stored in a new column,
         * so US-011 needs no migration. Trade-off: a re-import that changes
         * nothing touches no row and does not move the stamp — the label
         * means "data last changed by an import". Served by the
         * (user_id, activity_date) index prefix on user_id.
         */
        $lastImportedAt = $athlete->activities()
            ->where('source', ActivitySource::GarminCsv)
            ->max('updated_at');

        return SyncStatus::forCsvOnly(
            is_string($lastImportedAt) ? Carbon::parse($lastImportedAt) : null,
            $this->runsInWindow($athlete, $window),
        );
    }

    private function runsInWindow(User $athlete, HeatmapWindow $window): int
    {
        return $athlete->activities()
            ->whereBetween('activity_date', [
                $window->startsOn->toDateString(),
                $window->endsOn->toDateString(),
            ])
            ->count();
    }
}
