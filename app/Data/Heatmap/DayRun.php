<?php

namespace App\Data\Heatmap;

use App\Models\Activity;
use App\Support\Garmin\GarminImportFormat;

/**
 * One run of a heatmap day, as the day detail lists it: scalars only, so the cached
 * grid that carries it never holds an Eloquent model, and every string the detail
 * prints comes from here rather than from Blade.
 *
 * A metric that was not recorded is null and prints as an em dash (via the
 * `run-metric` component) — never as 0.
 */
final readonly class DayRun
{
    public function __construct(
        public string $name,
        public int $distanceMeters,
        public int $movingTimeSeconds,
        public ?int $averagePaceSecondsPerKm,
        public ?int $totalAscentMeters,
        public ?int $averageHeartRate,
        public ?string $stravaUrl,
    ) {}

    /**
     * The Strava link follows Activity::stravaUrl(), so a Garmin-sourced run never
     * links to a Strava page that shows other values (FR-008).
     */
    public static function fromActivity(Activity $activity): self
    {
        return new self(
            name: $activity->name,
            distanceMeters: $activity->distance_meters,
            movingTimeSeconds: $activity->moving_time_seconds,
            averagePaceSecondsPerKm: $activity->average_pace_seconds_per_km,
            totalAscentMeters: $activity->total_ascent_meters,
            averageHeartRate: $activity->average_heart_rate,
            stravaUrl: $activity->stravaUrl(),
        );
    }

    /**
     * "8.3 km" — one decimal, unit included, so every value reads with its unit.
     */
    public function kilometres(): string
    {
        return GarminImportFormat::distance($this->distanceMeters);
    }

    /**
     * "0:55:59".
     */
    public function movingTime(): string
    {
        return GarminImportFormat::duration($this->movingTimeSeconds);
    }

    /**
     * "5:23 /km", or null when not recorded.
     */
    public function pace(): ?string
    {
        return GarminImportFormat::pace($this->averagePaceSecondsPerKm);
    }

    /**
     * "45 m", or null when not recorded.
     */
    public function ascent(): ?string
    {
        return GarminImportFormat::ascent($this->totalAscentMeters);
    }

    /**
     * "152 bpm", or null when not recorded.
     */
    public function heartRate(): ?string
    {
        return GarminImportFormat::heartRate($this->averageHeartRate);
    }

    /**
     * "Strava" for a run linked to its Strava page, "Garmin import" otherwise.
     */
    public function sourceLabel(): string
    {
        return $this->stravaUrl === null ? 'Garmin import' : 'Strava';
    }
}
