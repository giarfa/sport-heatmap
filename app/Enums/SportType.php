<?php

namespace App\Enums;

/**
 * The sport types this product stores.
 *
 * Everything else a Garmin export lists is ignored at import time (FR-003): the
 * heatmap is a running heatmap, so rides, swims and walks never reach the database.
 */
enum SportType: string
{
    case Run = 'Run';
    case TrailRun = 'TrailRun';
    case VirtualRun = 'VirtualRun';

    /**
     * The backing values.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
