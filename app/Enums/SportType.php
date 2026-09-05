<?php

namespace App\Enums;

/**
 * The Strava sport types this product stores.
 *
 * Everything else Strava returns is discarded at sync time (FR-003): the heatmap
 * is a running heatmap, so rides, swims and walks never reach the database.
 */
enum SportType: string
{
    case Run = 'Run';
    case TrailRun = 'TrailRun';
    case VirtualRun = 'VirtualRun';

    /**
     * The backing values, for filtering a Strava activity payload at sync time.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
