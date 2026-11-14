<?php

namespace App\Console\Commands\Garmin;

use App\Actions\Garmin\SyncGarminActivities;
use App\Data\Garmin\GarminSyncResult;
use App\Enums\GarminSyncTrigger;
use App\Exceptions\Garmin\GarminSyncAlreadyRunningException;
use App\Support\Garmin\GarminPersonalApiClient;
use App\Support\Garmin\ResolveSyncOwner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('garmin:sync {--scheduled : Mark the run as the scheduler\'s}')]
#[Description('Read the owner\'s runs from Garmin Connect')]
class GarminSyncCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(
        GarminPersonalApiClient $client,
        ResolveSyncOwner $resolveSyncOwner,
        SyncGarminActivities $syncGarminActivities,
    ): int {
        /*
         * No key is the CSV-only setup, not a failure: nothing is called and nothing is
         * recorded, so the scheduler's daily run is silent on an instance that never
         * configured the sync, and the dashboard behaves exactly as it did before it.
         */
        if (! $client->isConfigured()) {
            $this->info('GARMIN_API_KEY is not set — nothing to sync.');

            return self::SUCCESS;
        }

        $owner = $resolveSyncOwner();

        if ($owner === null) {
            $this->error('There is no owner account to sync into.');

            return self::FAILURE;
        }

        $trigger = $this->option('scheduled') ? GarminSyncTrigger::Scheduled : GarminSyncTrigger::Manual;

        try {
            $result = $syncGarminActivities($owner, $trigger);
        } catch (GarminSyncAlreadyRunningException $exception) {
            $this->warn($exception->getMessage());

            return self::FAILURE;
        }

        $this->report($result);

        return $result->succeeded() ? self::SUCCESS : self::FAILURE;
    }

    private function report(GarminSyncResult $result): void
    {
        if ($result->failure !== null) {
            $this->error($result->failure->warning());
        }

        $this->table(
            ['New', 'Updated', 'Linked', 'Ignored', 'Future-dated', 'Invalid'],
            [[
                $result->counts->new,
                $result->counts->updated,
                $result->counts->linked,
                $result->counts->ignored,
                $result->counts->futureDated,
                $result->counts->invalid,
            ]],
        );
    }
}
