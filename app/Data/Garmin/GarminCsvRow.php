<?php

namespace App\Data\Garmin;

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
        public ?int $averagePaceSecondsPerKm = null,
        public ?int $totalAscentMeters = null,
        public ?int $averageHeartRate = null,
    ) {}

    /**
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
    public function toAttributes(): array
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
     * Only the metrics the file actually recorded, keyed by column.
     *
     * An empty metric is left out rather than carried as null: the attributes are
     * written onto a stored run on Overwrite, and a "--" in the file must keep what
     * is already stored, never blank it. They are also not part of the run's
     * identity (isIdenticalTo() / identityKey()), so re-importing a file whose
     * metrics changed still recognises its runs.
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
     * Scalars only, so the session never holds a serialized object.
     *
     * @return array{sport_type: string, name: string, distance_meters: int, moving_time_seconds: int, start_date_local: string, average_pace_seconds_per_km?: int|null, total_ascent_meters?: int|null, average_heart_rate?: int|null}
     */
    public function toSessionArray(): array
    {
        return [
            'sport_type' => $this->sportType->value,
            'name' => $this->name,
            'distance_meters' => $this->distanceMeters,
            'moving_time_seconds' => $this->movingTimeSeconds,
            'start_date_local' => $this->startDateLocal->format(self::START_FORMAT),
            'average_pace_seconds_per_km' => $this->averagePaceSecondsPerKm,
            'total_ascent_meters' => $this->totalAscentMeters,
            'average_heart_rate' => $this->averageHeartRate,
        ];
    }

    /**
     * @param  array{sport_type: string, name: string, distance_meters: int, moving_time_seconds: int, start_date_local: string, average_pace_seconds_per_km?: int|null, total_ascent_meters?: int|null, average_heart_rate?: int|null}  $row
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
            /*
             * A reviewed-but-unconfirmed upload parked in the session before the metrics
             * existed has no such keys. Reading them as null lets it still be confirmed
             * after a deploy instead of failing to rebuild and sending the owner back to
             * upload the file again.
             */
            averagePaceSecondsPerKm: $row['average_pace_seconds_per_km'] ?? null,
            totalAscentMeters: $row['total_ascent_meters'] ?? null,
            averageHeartRate: $row['average_heart_rate'] ?? null,
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
     * Built in UTC with no conversion: the value is already the runner's
     * wall-clock time.
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
