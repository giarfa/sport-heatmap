<?php

namespace App\Actions\Strava;

use App\Data\StravaActivity;
use App\Data\SyncOutcome;
use App\Models\User;
use App\Support\Heatmap\HeatmapWindow;
use Illuminate\Support\Facades\DB;

/**
 * Applies one fetched window to the database: upsert on `strava_activity_id`,
 * delete local activities inside the window Strava no longer returns.
 *
 * No authorization check here — the caller (the sync command) has already
 * resolved which athletes may sync.
 */
class ReconcileStravaWindow
{
    /**
     * @param  array<int, StravaActivity>  $activities
     */
    public function __invoke(User $user, HeatmapWindow $window, array $activities): SyncOutcome
    {
        return DB::transaction(function () use ($user, $window, $activities): SyncOutcome {
            $upserted = 0;
            $seenIds = [];

            foreach ($activities as $activity) {
                $seenIds[] = $activity->stravaActivityId;

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
             */
            $deleted = $user->activities()
                ->whereBetween('activity_date', [$window->startsOn, $window->endsOn])
                ->whereNotIn('strava_activity_id', $seenIds)
                ->delete();

            return new SyncOutcome($upserted, $deleted);
        });
    }
}
