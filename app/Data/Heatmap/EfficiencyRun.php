<?php

namespace App\Data\Heatmap;

use App\Models\Activity;
use Carbon\CarbonImmutable;

/**
 * One stored run as the aerobic-efficiency calculation reads it: scalars only, so the
 * measurement never touches an Eloquent model.
 *
 * A metric that was not recorded stays null. FR-023 qualifies a run on having a pace and
 * a heart rate, so a null turned into 0 would read as a run at 0 bpm or a 0:00 pace.
 */
final readonly class EfficiencyRun
{
    public function __construct(
        public CarbonImmutable $date,
        public int $distanceMeters,
        public ?int $paceSecondsPerKm,
        public ?int $heartRate,
        public ?int $ascentMeters,
    ) {}

    public static function fromActivity(Activity $activity): self
    {
        return new self(
            date: CarbonImmutable::parse($activity->activity_date->toDateString())->startOfDay(),
            distanceMeters: $activity->distance_meters,
            paceSecondsPerKm: $activity->average_pace_seconds_per_km,
            heartRate: $activity->average_heart_rate,
            ascentMeters: $activity->total_ascent_meters,
        );
    }

    /**
     * The day of the week, Monday = 0, matching the grid's Monday-first rows.
     */
    public function weekday(): int
    {
        return $this->date->dayOfWeekIso - 1;
    }
}
