<?php

namespace App\Support\Garmin;

use App\Data\ImportStatus;
use App\Enums\ActivitySource;
use App\Enums\GarminSyncOutcome;
use App\Models\GarminSync;
use App\Models\User;
use App\Support\Heatmap\HeatmapWindow;
use Illuminate\Support\Carbon;

/**
 * The only place the dashboard learns when the owner's data last changed. A few
 * small queries in, one {@see ImportStatus} out.
 */
final readonly class ResolveImportStatus
{
    public function __construct(private GarminPersonalApiClient $client = new GarminPersonalApiClient) {}

    public function __invoke(User $athlete, HeatmapWindow $window): ImportStatus
    {
        /*
         * The latest import is derived from the newest updated_at among the
         * owner's imported activities rather than stored in a new column or an
         * import log, so an import needs no extra write. Trade-off: a re-import
         * that changes nothing touches no row and does not move the stamp — the
         * label means "data last changed by an import". Served by the
         * (user_id, activity_date) index prefix on user_id.
         *
         * Only CSV runs count: a run the sync wrote or linked is the sync's doing, and
         * counting it would make "last import" move on every sync that updates a run.
         */
        $lastImportedAt = $athlete->activities()
            ->where('source', ActivitySource::GarminCsv->value)
            ->max('updated_at');

        $runsInWindow = $athlete->activities()
            ->onDaysBetween($window->startsOn, $window->endsOn)
            ->count();

        $lastImportedAt = is_string($lastImportedAt) ? Carbon::parse($lastImportedAt) : null;

        /*
         * With no key the sync does not exist for this instance: neither query runs, and
         * the status is exactly what it was before the sync did.
         */
        if (! $this->client->isConfigured()) {
            return new ImportStatus($lastImportedAt, $runsInWindow);
        }

        /*
         * "Last sync" is the newest succeeded attempt, even one that wrote nothing. The
         * failure is the newest FINISHED attempt's, if it failed: a running attempt has no
         * verdict yet, and the next success clears the warning because it becomes the
         * newest. Both read `garmin_syncs` through the (outcome, finished_at) index.
         */
        $lastSyncedAt = GarminSync::query()->succeeded()->max('finished_at');
        $latestFinished = GarminSync::query()->finished()->orderByDesc('finished_at')->orderByDesc('id')->first();

        return new ImportStatus(
            $lastImportedAt,
            $runsInWindow,
            is_string($lastSyncedAt) ? Carbon::parse($lastSyncedAt) : null,
            $latestFinished?->outcome === GarminSyncOutcome::Failed ? $latestFinished->failure_cause : null,
            true,
        );
    }
}
