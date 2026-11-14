<?php

namespace App\Support\Garmin;

use App\Enums\SportType;

/**
 * Maps a Garmin Connect activity type name, in Italian or English, to the
 * running sport type stored for it. Anything else is not a run (FR-003).
 */
final class GarminRunningTypes
{
    /**
     * Lower-cased type name => sport type.
     *
     * Only "Corsa" is confirmed by a real export (client-materials/Activities
     * (1).csv); the other names are Garmin's best-known Italian and English
     * running variants (decision 56c5683061907ab8). That is why an unknown type
     * is never dropped silently: the parser counts it by name and the import
     * summary lists it, so a missed running variant is visible to the owner and
     * becomes a one-line addition here.
     *
     * @var array<string, SportType>
     */
    private const TYPES = [
        'corsa' => SportType::Run,
        'running' => SportType::Run,
        'corsa su strada' => SportType::Run,
        'street running' => SportType::Run,
        'corsa su pista' => SportType::Run,
        'track running' => SportType::Run,
        'tapis roulant' => SportType::Run,
        'corsa su tapis roulant' => SportType::Run,
        'treadmill running' => SportType::Run,
        'corsa indoor' => SportType::Run,
        'indoor running' => SportType::Run,
        'trail running' => SportType::TrailRun,
        'corsa su sentiero' => SportType::TrailRun,
        'corsa virtuale' => SportType::VirtualRun,
        'virtual run' => SportType::VirtualRun,
        'virtual running' => SportType::VirtualRun,
    ];

    /**
     * Activity type key of the Connect API => sport type (FR-003). The API names a type
     * with a stable snake_case key, never a localised label, so this map is exact and
     * needs no case folding. Every key outside it is not a run.
     *
     * @var array<string, SportType>
     */
    private const API_TYPE_KEYS = [
        'running' => SportType::Run,
        'street_running' => SportType::Run,
        'track_running' => SportType::Run,
        'indoor_running' => SportType::Run,
        'treadmill_running' => SportType::Run,
        'ultra_run' => SportType::Run,
        'obstacle_run' => SportType::Run,
        'trail_running' => SportType::TrailRun,
        'virtual_run' => SportType::VirtualRun,
    ];

    /**
     * The sport type for a Connect API `activityType.typeKey`, or null when it is not a
     * run.
     */
    public static function sportTypeForTypeKey(string $typeKey): ?SportType
    {
        return self::API_TYPE_KEYS[$typeKey] ?? null;
    }

    /**
     * The sport type for a Garmin type name, matched trimmed and
     * case-insensitively, or null when it is not a run.
     */
    public static function sportTypeFor(string $label): ?SportType
    {
        return self::TYPES[mb_strtolower(trim($label))] ?? null;
    }
}
