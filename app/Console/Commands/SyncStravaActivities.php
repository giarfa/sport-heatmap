<?php

namespace App\Console\Commands;

use App\Actions\Strava\ReconcileStravaWindow;
use App\Data\SyncOutcome;
use App\Enums\SyncRunStatus;
use App\Exceptions\Strava\StravaApiException;
use App\Exceptions\Strava\StravaReconnectRequiredException;
use App\Models\SyncRun;
use App\Models\User;
use App\Services\Strava\StravaClient;
use App\Support\Heatmap\HeatmapWindow;
use App\Support\Strava\AthleteAllowlist;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

#[Signature('strava:sync')]
#[Description('Reconcile the 112-day activity window for every connected, allowlisted athlete')]
class SyncStravaActivities extends Command
{
    public function __construct(
        private readonly StravaClient $stravaClient,
        private readonly ReconcileStravaWindow $reconcileStravaWindow,
    ) {
        parent::__construct();
    }

    public function handle(AthleteAllowlist $allowlist): int
    {
        $athletes = $this->connectedAthletes($allowlist);

        if ($athletes->isEmpty()) {
            /*
             * No sync_runs row is written here: a fabricated "succeeded, 0/0" row
             * would tell US-006's dashboard indicator that a sync happened when
             * none did. "Never synced" is a first-run state the dashboard renders
             * deliberately, and this is the only way to leave it in place.
             */
            $this->components->info('No connected, allowlisted athlete to sync.');

            return self::SUCCESS;
        }

        $syncRun = SyncRun::create([
            'started_at' => now(),
            'status' => SyncRunStatus::Running,
        ]);

        $lastAttemptedAthleteId = null;

        try {
            $outcome = new SyncOutcome(0, 0);

            foreach ($athletes as $athlete) {
                $lastAttemptedAthleteId = $athlete->strava_athlete_id;

                $window = HeatmapWindow::current();
                $activities = $this->stravaClient->activitiesInWindow($athlete, $window);
                $outcome = $outcome->plus(($this->reconcileStravaWindow)($athlete, $window, $activities));
            }

            $syncRun->update([
                'finished_at' => now(),
                'status' => SyncRunStatus::Succeeded,
                'activities_upserted' => $outcome->upserted,
                'activities_deleted' => $outcome->deleted,
                'error_message' => null,
            ]);

            $this->components->info(sprintf(
                'Synced %d athlete(s): %d upserted, %d deleted.',
                $athletes->count(), $outcome->upserted, $outcome->deleted,
            ));

            // The seam where US-004 invalidates the cached grid once the sync
            // succeeds — no cache key is invented here.
            return self::SUCCESS;
        } catch (StravaApiException|StravaReconnectRequiredException $exception) {
            $syncRun->update([
                'finished_at' => now(),
                'status' => SyncRunStatus::Failed,
                'activities_upserted' => 0,
                'activities_deleted' => 0,
                'error_message' => $exception->getMessage(),
            ]);

            Log::warning('The Strava activity sync failed.', [
                'athlete_id' => $lastAttemptedAthleteId,
                'reason' => $exception->getMessage(),
            ]);

            $this->components->error('Strava sync failed: '.$exception->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * Athletes eligible to sync: connected (Strava identity and tokens present)
     * and still on the allowlist. An athlete removed from the allowlist must
     * stop being synced, the same rule EnsureFreshStravaToken enforces on the
     * web.
     *
     * @return Collection<int, User>
     */
    private function connectedAthletes(AthleteAllowlist $allowlist): Collection
    {
        return User::query()
            ->whereNotNull('strava_athlete_id')
            ->whereNotNull('strava_access_token')
            ->whereNotNull('strava_refresh_token')
            ->get()
            ->filter(fn (User $user): bool => $allowlist->allows((string) $user->strava_athlete_id))
            ->values();
    }
}
