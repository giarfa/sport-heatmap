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
    public function __construct(
        public int $stravaActivityId,
        public SportType $sportType,
        public string $name,
        public int $distanceMeters,
        public int $movingTimeSeconds,
        public CarbonImmutable $startDateLocal,
        public CarbonImmutable $activityDate,
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
        ];
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
