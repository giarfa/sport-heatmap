<?php

namespace App\Support\Heatmap;

use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * The date of the athlete's oldest stored run, which is how far back Previous may go.
 *
 * Every stored activity is a run (the Garmin import ignores other sports), so no sport filter is needed.
 */
final readonly class OldestRunDate
{
    /**
     * One MIN() served by the (user_id, activity_date) index. It is read fresh on every
     * request and is not part of the cached grid: the grid is cached per window, and the
     * oldest run is a property of the athlete — an import can move it without
     * the window the reader is looking at changing.
     */
    public function __invoke(User $athlete): ?CarbonImmutable
    {
        $oldest = $athlete->activities()->min('activity_date');

        return is_string($oldest) ? CarbonImmutable::parse($oldest)->startOfDay() : null;
    }
}
