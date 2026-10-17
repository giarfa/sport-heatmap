<?php

namespace App\Support\Garmin;

use Carbon\CarbonInterface;

/**
 * How the import screens print a run, per the US-008 mockup contract: the
 * same three formats on the stored side and the CSV side, so a comparison
 * never differs only in presentation.
 */
final class GarminImportFormat
{
    /**
     * Kilometres to one decimal: 8290 → "8.3 km".
     */
    public static function distance(int $meters): string
    {
        return number_format($meters / 1_000, 1).' km';
    }

    /**
     * Whole seconds as H:MM:SS: 3263 → "0:54:23".
     */
    public static function duration(int $seconds): string
    {
        return sprintf('%d:%02d:%02d', intdiv($seconds, 3_600), intdiv($seconds % 3_600, 60), $seconds % 60);
    }

    /**
     * The run's local start time of day: "09:33:27".
     */
    public static function start(CarbonInterface $start): string
    {
        return $start->format('H:i:s');
    }

    /**
     * "Sat 10 Oct 2026".
     */
    public static function date(CarbonInterface $date): string
    {
        return $date->format('D j M Y');
    }

    /**
     * Average pace as minutes and seconds per kilometre: 323 → "5:23 /km". Null when
     * the pace was not recorded; the view prints that as an em dash.
     */
    public static function pace(?int $secondsPerKm): ?string
    {
        return $secondsPerKm === null ? null : sprintf('%d:%02d /km', intdiv($secondsPerKm, 60), $secondsPerKm % 60);
    }

    /**
     * Total ascent in whole metres: 45 → "45 m".
     */
    public static function ascent(?int $meters): ?string
    {
        return $meters === null ? null : "{$meters} m";
    }

    /**
     * Average heart rate in whole beats per minute: 152 → "152 bpm".
     */
    public static function heartRate(?int $bpm): ?string
    {
        return $bpm === null ? null : "{$bpm} bpm";
    }
}
