<?php

namespace App\Support\Sync;

use App\Data\SyncStatus;
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
        $runsInWindow = $athlete->activities()
            ->whereBetween('activity_date', [
                $window->startsOn->toDateString(),
                $window->endsOn->toDateString(),
            ])
            ->count();

        return SyncStatus::from($lastRun, $lastSuccessfulRun, $runsInWindow, $now);
    }
}
