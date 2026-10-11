<?php

namespace App\Data\Garmin;

use App\Enums\ActivitySource;
use App\Enums\SportType;
use App\Models\Activity;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * One running activity read from a Garmin Connect CSV, carried across the
 * upload boundary so no raw CSV cell reaches the planner, the session, or
 * Eloquent.
 */
final readonly class GarminCsvRow
{
    private const START_FORMAT = 'Y-m-d H:i:s';

    public function __construct(
        public SportType $sportType,
        public string $name,
        public int $distanceMeters,
        public int $movingTimeSeconds,
        public CarbonImmutable $startDateLocal,
        public CarbonImmutable $activityDate,
    ) {}

    /**
     * @return array{
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
            'sport_type' => $this->sportType,
            'name' => $this->name,
            'distance_meters' => $this->distanceMeters,
            'moving_time_seconds' => $this->movingTimeSeconds,
            'start_date_local' => $this->startDateLocal,
            'activity_date' => $this->activityDate,
            'source' => ActivitySource::GarminCsv,
        ];
    }

    /**
     * Scalars only, so the session never holds a serialized object.
     *
     * @return array{sport_type: string, name: string, distance_meters: int, moving_time_seconds: int, start_date_local: string}
     */
    public function toSessionArray(): array
    {
        return [
            'sport_type' => $this->sportType->value,
            'name' => $this->name,
            'distance_meters' => $this->distanceMeters,
            'moving_time_seconds' => $this->movingTimeSeconds,
            'start_date_local' => $this->startDateLocal->format(self::START_FORMAT),
        ];
    }

    /**
     * @param  array{sport_type: string, name: string, distance_meters: int, moving_time_seconds: int, start_date_local: string}  $row
     */
    public static function fromSessionArray(array $row): self
    {
        $startDateLocal = self::wallClock($row['start_date_local']);

        return new self(
            sportType: SportType::from($row['sport_type']),
            name: $row['name'],
            distanceMeters: (int) $row['distance_meters'],
            movingTimeSeconds: (int) $row['moving_time_seconds'],
            startDateLocal: $startDateLocal,
            activityDate: $startDateLocal->startOfDay(),
        );
    }

    /**
     * Whether a stored activity already holds exactly this run: same local start
     * to the second, same distance, same moving time. That is the identity a
     * re-import of the same file is recognised by — the export carries no
     * activity id.
     */
    public function isIdenticalTo(Activity $activity): bool
    {
        return $activity->start_date_local->format(self::START_FORMAT) === $this->startDateLocal->format(self::START_FORMAT)
            && $activity->distance_meters === $this->distanceMeters
            && $activity->moving_time_seconds === $this->movingTimeSeconds;
    }

    /**
     * The same identity between two CSV rows, used to collapse duplicates
     * within one file.
     */
    public function identityKey(): string
    {
        return "{$this->startDateLocal->format(self::START_FORMAT)}|{$this->distanceMeters}|{$this->movingTimeSeconds}";
    }

    /**
     * Built in UTC with no conversion: like Strava's start_date_local, the value
     * is already the runner's wall-clock time.
     */
    private static function wallClock(string $value): CarbonImmutable
    {
        $moment = CarbonImmutable::createFromFormat(self::START_FORMAT, $value, 'UTC');

        if (! $moment instanceof CarbonImmutable) {
            throw new InvalidArgumentException('A pending Garmin row carries an unreadable start.');
        }

        return $moment;
    }
}
