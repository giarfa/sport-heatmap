<?php

namespace App\Data\Garmin;

use App\Enums\SportType;
use App\Support\Garmin\GarminRunningTypes;
use Carbon\CarbonImmutable;

/**
 * One running activity read from the Garmin Connect API, carried across the API
 * boundary so no raw payload reaches the sync action or Eloquent.
 */
final readonly class GarminApiActivity
{
    private const START_FORMAT = 'Y-m-d H:i:s';

    /**
     * 999,999 km and 10,000 hours: the bounds the CSV parser holds, so an absurd value
     * cannot overflow the int cast into a negative total.
     */
    private const MAX_DISTANCE_METERS = 999_999_000;

    private const MAX_DURATION_SECONDS = 36_000_000;

    public function __construct(
        public int $activityId,
        public SportType $sportType,
        public string $name,
        public int $distanceMeters,
        public int $movingTimeSeconds,
        public CarbonImmutable $startDateLocal,
        public CarbonImmutable $activityDate,
        public ?int $averagePaceSecondsPerKm = null,
        public ?int $totalAscentMeters = null,
        public ?int $averageHeartRate = null,
    ) {}

    /**
     * The activity type key of a payload, or '' when it has none.
     *
     * The caller classifies by type before it builds a run: a strength session has no
     * distance, so asking for a run out of it would count it invalid instead of ignored.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function typeKeyOf(array $payload): string
    {
        $type = $payload['activityType'] ?? null;
        $typeKey = is_array($type) ? ($type['typeKey'] ?? null) : null;

        return is_string($typeKey) ? $typeKey : '';
    }

    /**
     * The run an API payload describes, or null when it is not a running activity or a
     * required field — id, local start, distance, moving time — is missing or unreadable.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fromApiPayload(array $payload): ?self
    {
        $sportType = GarminRunningTypes::sportTypeForTypeKey(self::typeKeyOf($payload));
        $activityId = $payload['activityId'] ?? null;
        $startTimeLocal = $payload['startTimeLocal'] ?? null;
        $distance = $payload['distance'] ?? null;
        $movingDuration = $payload['movingDuration'] ?? null;

        if (
            $sportType === null
            || ! is_int($activityId) || $activityId <= 0
            || ! is_string($startTimeLocal) || ! CarbonImmutable::hasFormat($startTimeLocal, self::START_FORMAT)
            || ! is_int($distance) && ! is_float($distance)
            || ! is_int($movingDuration) && ! is_float($movingDuration)
            || $distance < 0 || $distance > self::MAX_DISTANCE_METERS
            || $movingDuration < 0 || $movingDuration > self::MAX_DURATION_SECONDS
        ) {
            return null;
        }

        /*
         * Built in UTC with no conversion: `startTimeLocal` is already the runner's
         * wall-clock time, the same value GarminCsvRow stores, so the two sources match
         * on the same instant.
         */
        $startDateLocal = CarbonImmutable::createFromFormat(self::START_FORMAT, $startTimeLocal, 'UTC');

        if (! $startDateLocal instanceof CarbonImmutable) {
            return null;
        }

        $name = $payload['activityName'] ?? null;

        return new self(
            activityId: $activityId,
            sportType: $sportType,
            name: is_string($name) && trim($name) !== '' ? trim($name) : 'Garmin activity',
            distanceMeters: (int) round($distance),
            movingTimeSeconds: (int) round($movingDuration),
            startDateLocal: $startDateLocal,
            activityDate: $startDateLocal->startOfDay(),
            averagePaceSecondsPerKm: self::paceFor($payload['averageSpeed'] ?? null),
            totalAscentMeters: self::wholeNumber($payload['elevationGain'] ?? null, allowZero: true),
            averageHeartRate: self::wholeNumber($payload['averageHR'] ?? null, allowZero: false),
        );
    }

    /**
     * Every column the API owns, for creating a run or comparing it with a stored one.
     *
     * @return array{
     *     sport_type: SportType,
     *     name: string,
     *     distance_meters: int,
     *     moving_time_seconds: int,
     *     start_date_local: CarbonImmutable,
     *     activity_date: CarbonImmutable,
     *     average_pace_seconds_per_km?: int,
     *     total_ascent_meters?: int,
     *     average_heart_rate?: int,
     * }
     */
    public function toRunAttributes(): array
    {
        return [
            'sport_type' => $this->sportType,
            'name' => $this->name,
            'distance_meters' => $this->distanceMeters,
            'moving_time_seconds' => $this->movingTimeSeconds,
            'start_date_local' => $this->startDateLocal,
            'activity_date' => $this->activityDate,
            ...$this->metricAttributes(),
        ];
    }

    /**
     * Only the metrics Garmin recorded, keyed by column.
     *
     * A missing metric is left out rather than carried as null: on a link or an update
     * a null must keep what is stored — a metric backfilled from the CSV is never
     * blanked — and a new run simply has no value, never a 0 that reads as a measurement.
     *
     * @return array{average_pace_seconds_per_km?: int, total_ascent_meters?: int, average_heart_rate?: int}
     */
    public function metricAttributes(): array
    {
        return array_filter([
            'average_pace_seconds_per_km' => $this->averagePaceSecondsPerKm,
            'total_ascent_meters' => $this->totalAscentMeters,
            'average_heart_rate' => $this->averageHeartRate,
        ], static fn (?int $value): bool => $value !== null);
    }

    /**
     * Seconds per kilometre from a speed in m/s. A null or zero speed has no pace.
     */
    private static function paceFor(mixed $averageSpeed): ?int
    {
        if ((! is_int($averageSpeed) && ! is_float($averageSpeed)) || $averageSpeed <= 0) {
            return null;
        }

        return (int) round(1_000 / $averageSpeed);
    }

    /**
     * A number rounded to a whole value, or null when absent or unreadable.
     *
     * Heart rate passes `allowZero: false`: 0 bpm is not a measurement, only Garmin's
     * way of saying "no strap". Elevation gain keeps a real 0 (a flat track or a
     * treadmill).
     */
    private static function wholeNumber(mixed $value, bool $allowZero): ?int
    {
        if ((! is_int($value) && ! is_float($value)) || $value < 0 || ($value == 0 && ! $allowZero)) {
            return null;
        }

        return (int) round($value);
    }
}
