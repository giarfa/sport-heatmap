<?php

namespace App\Data;

use App\Enums\ActivitySource;
use App\Enums\SportType;
use Carbon\CarbonImmutable;

/**
 * A Strava activity, carried across the API boundary so no raw response array
 * reaches ReconcileStravaWindow or Eloquent.
 */
final readonly class StravaActivity
{
    /*
     * Plausibility bounds on the optional run metrics: a value outside them is a
     * broken reading, shown as "not recorded" rather than published or allowed to
     * overflow the column.
     */
    private const MAX_PACE_SECONDS_PER_KM = 36_000;

    private const MAX_ASCENT_METERS = 20_000;

    private const MAX_HEART_RATE = 250;

    public function __construct(
        public int $stravaActivityId,
        public SportType $sportType,
        public string $name,
        public int $distanceMeters,
        public int $movingTimeSeconds,
        public CarbonImmutable $startDateLocal,
        public CarbonImmutable $activityDate,
        public ?int $averagePaceSecondsPerKm,
        public ?int $totalAscentMeters,
        public ?int $averageHeartRate,
    ) {}

    /**
     * Build from one item of Strava's activities response, or return null when
     * the payload is not a storable running activity.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fromApiPayload(array $payload): ?self
    {
        $sportType = self::sportType($payload);

        if ($sportType === null) {
            return null;
        }

        if (! isset($payload['id']) || ! is_numeric($payload['id'])) {
            return null;
        }

        $rawStartDateLocal = $payload['start_date_local'] ?? null;

        if (! is_string($rawStartDateLocal) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $rawStartDateLocal)) {
            return null;
        }

        /*
         * Strava labels start_date_local with a trailing "Z" although it is
         * already the athlete's wall-clock time, not UTC. Parsing it with
         * Carbon::parse() and letting the app timezone touch it is exactly how a
         * 22:30 run silently lands on the wrong day. Building it with an explicit
         * createFromFormat(..., 'UTC') and taking activity_date from the raw
         * string's own date component means no conversion is ever applied.
         */
        $startDateLocal = CarbonImmutable::createFromFormat('Y-m-d\TH:i:s\Z', $rawStartDateLocal, 'UTC');
        $activityDate = CarbonImmutable::createFromFormat('Y-m-d', substr($rawStartDateLocal, 0, 10), 'UTC');

        if (! $startDateLocal instanceof CarbonImmutable || ! $activityDate instanceof CarbonImmutable) {
            return null;
        }

        $distanceMeters = is_numeric($payload['distance'] ?? null) ? (int) round((float) $payload['distance']) : 0;
        $movingTimeSeconds = is_numeric($payload['moving_time'] ?? null) ? (int) $payload['moving_time'] : 0;

        $name = is_string($payload['name'] ?? null) ? trim($payload['name']) : '';

        return new self(
            stravaActivityId: (int) $payload['id'],
            sportType: $sportType,
            name: $name === '' ? 'Run' : $name,
            distanceMeters: $distanceMeters,
            movingTimeSeconds: $movingTimeSeconds,
            startDateLocal: $startDateLocal,
            activityDate: $activityDate->startOfDay(),
            averagePaceSecondsPerKm: self::paceSecondsPerKm($payload['average_speed'] ?? null),
            totalAscentMeters: self::wholeNumber($payload['total_elevation_gain'] ?? null, allowZero: true, max: self::MAX_ASCENT_METERS),
            averageHeartRate: self::wholeNumber($payload['average_heartrate'] ?? null, allowZero: false, max: self::MAX_HEART_RATE),
        );
    }

    /**
     * @return array{
     *     strava_activity_id: int,
     *     sport_type: SportType,
     *     name: string,
     *     distance_meters: int,
     *     moving_time_seconds: int,
     *     start_date_local: CarbonImmutable,
     *     activity_date: CarbonImmutable,
     *     source: ActivitySource,
     *     average_pace_seconds_per_km: int|null,
     *     total_ascent_meters: int|null,
     *     average_heart_rate: int|null,
     * }
     */
    public function toAttributes(): array
    {
        return [
            'strava_activity_id' => $this->stravaActivityId,
            'sport_type' => $this->sportType,
            'name' => $this->name,
            'distance_meters' => $this->distanceMeters,
            'moving_time_seconds' => $this->movingTimeSeconds,
            'start_date_local' => $this->startDateLocal,
            'activity_date' => $this->activityDate,
            'source' => ActivitySource::Strava,
            'average_pace_seconds_per_km' => $this->averagePaceSecondsPerKm,
            'total_ascent_meters' => $this->totalAscentMeters,
            'average_heart_rate' => $this->averageHeartRate,
        ];
    }

    /**
     * Whole seconds per kilometre from Strava's average speed in metres per second,
     * or null when no usable speed was reported.
     */
    private static function paceSecondsPerKm(mixed $averageSpeed): ?int
    {
        /*
         * A zero speed is Strava's "no movement recorded" (a stopped watch, a treadmill
         * session without a footpod), not an infinitely slow run. Dividing by it would
         * error, and storing any pace for it would put a number on the screen that was
         * never measured; null is shown as "not recorded".
         */
        if (! is_numeric($averageSpeed) || (float) $averageSpeed <= 0) {
            return null;
        }

        $pace = round(1_000 / (float) $averageSpeed);

        /*
         * A speed near zero divides into a pace beyond the column's range, and a bound
         * keeps one odd activity from throwing in the upsert and aborting the whole
         * nightly reconcile. Ten hours per kilometre is not a run's pace.
         */
        return $pace > self::MAX_PACE_SECONDS_PER_KM ? null : (int) $pace;
    }

    /**
     * A whole-number metric from a Strava field, or null when the field is absent or
     * not a number. Zero is only kept where it is a real reading (ascent on a flat run);
     * a zero heart rate means the sensor was off.
     */
    private static function wholeNumber(mixed $value, bool $allowZero, int $max): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $rounded = round((float) $value);

        if ($rounded < 0 || $rounded > $max || ($rounded === 0.0 && ! $allowZero)) {
            return null;
        }

        return (int) $rounded;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function sportType(array $payload): ?SportType
    {
        /*
         * Strava's older API surfaces returned only "type"; "sport_type" is the
         * current field and takes precedence. Falling back to "type" is only
         * attempted when "sport_type" is entirely absent from the payload, so a
         * present-but-invalid sport_type is never silently overridden by a stale
         * type value.
         */
        $rawSportType = $payload['sport_type'] ?? $payload['type'] ?? null;

        if (! is_string($rawSportType) || ! in_array($rawSportType, SportType::values(), strict: true)) {
            return null;
        }

        return SportType::from($rawSportType);
    }
}
