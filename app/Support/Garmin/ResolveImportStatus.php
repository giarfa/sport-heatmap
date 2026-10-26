<?php

namespace App\Support\Garmin;

use App\Data\ImportStatus;
use App\Enums\ActivitySource;
use App\Models\User;
use App\Support\Heatmap\HeatmapWindow;
use Illuminate\Support\Carbon;

/**
 * The only place the dashboard learns when the owner's data last changed. Two
 * small queries in, one {@see ImportStatus} out.
 */
final readonly class ResolveImportStatus
{
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
         * The source filter is only here because Strava rows still exist until
         * the migration that deletes them; that migration removes this line.
         */
        $lastImportedAt = $athlete->activities()
            ->where('source', ActivitySource::GarminCsv)
            ->max('updated_at');

        $runsInWindow = $athlete->activities()
            ->onDaysBetween($window->startsOn, $window->endsOn)
            ->count();

        return new ImportStatus(
            is_string($lastImportedAt) ? Carbon::parse($lastImportedAt) : null,
            $runsInWindow,
        );
    }
}
