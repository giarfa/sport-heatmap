<?php

namespace App\Data\Garmin;

use App\Support\Garmin\GarminCsvValues;

/**
 * The extended metrics of one run (FR-022), read from a Garmin CSV row or from a Garmin
 * Connect API activity.
 *
 * Every value is nullable and every parser here answers null for what it cannot trust, so
 * "not recorded" is never stored as 0 and a bad cell never rejects the run it sits on.
 * Decimal values are rounded to one decimal before they get here, so a second import or
 * sync compares equal to what is stored.
 *
 * It sits outside a run's identity (the start, distance and moving time), and
 * toAttributes() leaves a null value out entirely, so writing the attributes onto a stored
 * run fills what the source recorded and keeps what it did not — a "--" never blanks a
 * value that is already stored.
 */
final readonly class ExtendedRunMetrics
{
    /**
     * Column => [property, type]: the one list that maps the DTO to the `activities`
     * table, to the compact session form, and back.
     *
     * @var array<string, array{0: string, 1: 'int'|'float'|'string'}>
     */
    private const COLUMNS = [
        'calories' => ['calories', 'int'],
        'max_heart_rate' => ['maxHeartRate', 'int'],
        'aerobic_training_effect' => ['aerobicTrainingEffect', 'float'],
        'average_running_cadence' => ['averageRunningCadence', 'int'],
        'max_running_cadence' => ['maxRunningCadence', 'int'],
        'best_pace_seconds_per_km' => ['bestPaceSecondsPerKm', 'int'],
        'total_descent_meters' => ['totalDescentMeters', 'int'],
        'average_stride_length_cm' => ['averageStrideLengthCm', 'float'],
        'average_vertical_ratio_percent' => ['averageVerticalRatioPercent', 'float'],
        'average_vertical_oscillation_cm' => ['averageVerticalOscillationCm', 'float'],
        'average_ground_contact_time_ms' => ['averageGroundContactTimeMs', 'int'],
        'average_ground_contact_balance_left_percent' => ['averageGroundContactBalanceLeftPercent', 'float'],
        'steps' => ['steps', 'int'],
        'lap_count' => ['lapCount', 'int'],
        'elapsed_time_seconds' => ['elapsedTimeSeconds', 'int'],
        'min_elevation_meters' => ['minElevationMeters', 'int'],
        'max_elevation_meters' => ['maxElevationMeters', 'int'],
        'training_stress_score' => ['trainingStressScore', 'float'],
        'hr_zone_1_seconds' => ['hrZone1Seconds', 'int'],
        'hr_zone_2_seconds' => ['hrZone2Seconds', 'int'],
        'hr_zone_3_seconds' => ['hrZone3Seconds', 'int'],
        'hr_zone_4_seconds' => ['hrZone4Seconds', 'int'],
        'hr_zone_5_seconds' => ['hrZone5Seconds', 'int'],
        'fastest_1k_seconds' => ['fastest1kSeconds', 'int'],
        'fastest_mile_seconds' => ['fastestMileSeconds', 'int'],
        'fastest_5k_seconds' => ['fastest5kSeconds', 'int'],
        'location_name' => ['locationName', 'string'],
    ];

    /**
     * Metres to centimetres, for the stride length the CSV writes in metres.
     */
    private const CENTIMETRES_PER_METRE = 100;

    /**
     * No stride is longer than five metres.
     */
    private const MAX_STRIDE_METRES = 5.0;

    /**
     * No pace slower than ten hours a kilometre is a real run.
     */
    private const MAX_PACE_SECONDS_PER_KM = 36_000;

    /**
     * The column is a varchar(255).
     */
    private const LOCATION_NAME_MAX_LENGTH = 255;

    public function __construct(
        public ?int $calories = null,
        public ?int $maxHeartRate = null,
        public ?float $aerobicTrainingEffect = null,
        public ?int $averageRunningCadence = null,
        public ?int $maxRunningCadence = null,
        public ?int $bestPaceSecondsPerKm = null,
        public ?int $totalDescentMeters = null,
        public ?float $averageStrideLengthCm = null,
        public ?float $averageVerticalRatioPercent = null,
        public ?float $averageVerticalOscillationCm = null,
        public ?int $averageGroundContactTimeMs = null,
        public ?float $averageGroundContactBalanceLeftPercent = null,
        public ?int $steps = null,
        public ?int $lapCount = null,
        public ?int $elapsedTimeSeconds = null,
        public ?int $minElevationMeters = null,
        public ?int $maxElevationMeters = null,
        public ?float $trainingStressScore = null,
        public ?int $hrZone1Seconds = null,
        public ?int $hrZone2Seconds = null,
        public ?int $hrZone3Seconds = null,
        public ?int $hrZone4Seconds = null,
        public ?int $hrZone5Seconds = null,
        public ?int $fastest1kSeconds = null,
        public ?int $fastestMileSeconds = null,
        public ?int $fastest5kSeconds = null,
        public ?string $locationName = null,
    ) {}

    /**
     * Read the metrics a CSV row carries. The CSV has no heart-rate zones, no fastest
     * splits and no place name: those stay null.
     *
     * @param  array<string, string>  $raw  canonical column key (GarminCsvColumns::EXTENDED_KEYS) => raw cell text; a key the file lacks is absent
     */
    public static function fromCsvValues(array $raw): self
    {
        $cell = static fn (string $key): string => trim($raw[$key] ?? '');

        /*
         * Garmin writes the stride in metres ("1.00") and the API in centimetres, while
         * the column is centimetres for both: one unit per metric, whatever the source.
         * The metres are read at three decimals before the conversion, so 1.04 m becomes
         * 104.0 cm and not 100.0 from a value rounded to one decimal too early.
         */
        $strideMetres = GarminCsvValues::decimalNumber($cell('stride_length'), 0.0, self::MAX_STRIDE_METRES, true, 3);

        return new self(
            calories: GarminCsvValues::wholeNumber($cell('calories'), 0, 20_000, true),
            maxHeartRate: GarminCsvValues::wholeNumber($cell('max_hr'), 0, 250, true),
            aerobicTrainingEffect: GarminCsvValues::oneDecimal($cell('aerobic_te'), 0.0, 5.0, false),
            averageRunningCadence: GarminCsvValues::wholeNumber($cell('avg_cadence'), 0, 400, true),
            maxRunningCadence: GarminCsvValues::wholeNumber($cell('max_cadence'), 0, 400, true),
            bestPaceSecondsPerKm: GarminCsvValues::paceSecondsPerKm($cell('best_pace')),
            /* A flat or uphill run has a real descent of 0, so it is kept. */
            totalDescentMeters: GarminCsvValues::wholeNumber($cell('total_descent'), 0, 20_000, false),
            averageStrideLengthCm: $strideMetres === null ? null : round($strideMetres * self::CENTIMETRES_PER_METRE, 1),
            averageVerticalRatioPercent: GarminCsvValues::oneDecimal($cell('vertical_ratio'), 0.0, 100.0, true),
            averageVerticalOscillationCm: GarminCsvValues::oneDecimal($cell('vertical_oscillation'), 0.0, 50.0, true),
            averageGroundContactTimeMs: GarminCsvValues::wholeNumber($cell('ground_contact_time'), 0, 2_000, true),
            averageGroundContactBalanceLeftPercent: GarminCsvValues::leftBalancePercent($cell('gct_balance')),
            steps: GarminCsvValues::wholeNumber($cell('steps'), 0, 1_000_000, true),
            lapCount: GarminCsvValues::wholeNumber($cell('laps'), 0, 10_000, true),
            elapsedTimeSeconds: GarminCsvValues::positiveDurationSeconds($cell('elapsed_time')),
            /* An elevation of 0 m, or below sea level, is a real reading, so zero and negatives are kept. */
            minElevationMeters: GarminCsvValues::wholeNumber($cell('min_elevation'), -500, 9_000, false),
            maxElevationMeters: GarminCsvValues::wholeNumber($cell('max_elevation'), -500, 9_000, false),
            /*
             * Every real local export writes 0.0 for a run with no power data: it is the
             * absence of a score, not a score of zero, so it is not recorded.
             */
            trainingStressScore: GarminCsvValues::oneDecimal($cell('training_stress_score'), 0.0, 10_000.0, true),
        );
    }

    /**
     * Read the metrics a Garmin Connect API activity carries. The API has no Training
     * Stress Score — it is a CSV-only column, so it stays null here and a sync never
     * touches the one a linked CSV run already holds.
     *
     * Every numeric field must be a JSON number: a string, a boolean or an array is
     * unreadable and the metric is empty. A null or missing field is empty too, and
     * neither ever makes the activity invalid. The start and end coordinates the payload
     * also carries are never read: no position is stored from either source.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fromApiPayload(array $payload): self
    {
        $field = static fn (string $key): mixed => $payload[$key] ?? null;

        /*
         * `maxSpeed` is metres per second and the column is seconds per kilometre, the unit
         * the CSV's best pace is in: 1000 / v, under the same ten-hours-a-kilometre bound as
         * the average pace. A null, zero or negative speed has no pace.
         */
        $maxSpeed = self::number($field('maxSpeed'));
        $paceSeconds = $maxSpeed !== null && $maxSpeed > 0 ? round(1_000 / $maxSpeed) : null;
        /* Bounded as a float before the int cast: a tiny speed would overflow it and wrap to a bogus pace. */
        $bestPace = $paceSeconds !== null && $paceSeconds >= 1 && $paceSeconds <= self::MAX_PACE_SECONDS_PER_KM ? (int) $paceSeconds : null;

        return new self(
            calories: self::whole($field('calories'), 0, 20_000, true),
            maxHeartRate: self::whole($field('maxHR'), 0, 250, true),
            aerobicTrainingEffect: self::decimal($field('aerobicTrainingEffect'), 0.0, 5.0, false),
            averageRunningCadence: self::whole($field('averageRunningCadenceInStepsPerMinute'), 0, 400, true),
            maxRunningCadence: self::whole($field('maxRunningCadenceInStepsPerMinute'), 0, 400, true),
            bestPaceSecondsPerKm: $bestPace,
            totalDescentMeters: self::whole($field('elevationLoss'), 0, 20_000, false),
            /* Already centimetres, the unit of the column: the CSV is the one that is converted. */
            averageStrideLengthCm: self::decimal($field('avgStrideLength'), 0.0, 500.0, true),
            averageVerticalRatioPercent: self::decimal($field('avgVerticalRatio'), 0.0, 100.0, true),
            averageVerticalOscillationCm: self::decimal($field('avgVerticalOscillation'), 0.0, 50.0, true),
            averageGroundContactTimeMs: self::whole($field('avgGroundContactTime'), 0, 2_000, true),
            averageGroundContactBalanceLeftPercent: self::decimal($field('avgGroundContactBalance'), 0.0, 100.0, true),
            steps: self::whole($field('steps'), 0, 1_000_000, true),
            lapCount: self::whole($field('lapCount'), 0, 10_000, true),
            elapsedTimeSeconds: self::whole($field('elapsedDuration'), 0, GarminCsvValues::MAX_DURATION_SECONDS, true),
            minElevationMeters: self::whole($field('minElevation'), -500, 9_000, false),
            maxElevationMeters: self::whole($field('maxElevation'), -500, 9_000, false),
            /* Time spent in a zone is a count of seconds, and 0 is a real reading: the run never reached it. */
            hrZone1Seconds: self::whole($field('hrTimeInZone_1'), 0, GarminCsvValues::MAX_DURATION_SECONDS, false),
            hrZone2Seconds: self::whole($field('hrTimeInZone_2'), 0, GarminCsvValues::MAX_DURATION_SECONDS, false),
            hrZone3Seconds: self::whole($field('hrTimeInZone_3'), 0, GarminCsvValues::MAX_DURATION_SECONDS, false),
            hrZone4Seconds: self::whole($field('hrTimeInZone_4'), 0, GarminCsvValues::MAX_DURATION_SECONDS, false),
            hrZone5Seconds: self::whole($field('hrTimeInZone_5'), 0, GarminCsvValues::MAX_DURATION_SECONDS, false),
            fastest1kSeconds: self::whole($field('fastestSplit_1000'), 0, GarminCsvValues::MAX_DURATION_SECONDS, true),
            fastestMileSeconds: self::whole($field('fastestSplit_1609'), 0, GarminCsvValues::MAX_DURATION_SECONDS, true),
            fastest5kSeconds: self::whole($field('fastestSplit_5000'), 0, GarminCsvValues::MAX_DURATION_SECONDS, true),
            locationName: self::placeName($field('locationName')),
        );
    }

    /**
     * Only the values that were recorded, keyed by `activities` column.
     *
     * A null is left out rather than carried: these attributes are written onto a stored
     * run, and a "--" in the source must keep what is already stored.
     *
     * @return array<string, int|float|string>
     */
    public function toAttributes(): array
    {
        $attributes = [];

        foreach (self::COLUMNS as $column => [$property]) {
            $value = $this->{$property};

            if ($value !== null) {
                $attributes[$column] = $value;
            }
        }

        return $attributes;
    }

    /**
     * Whether the source recorded no extended metric at all.
     */
    public function isEmpty(): bool
    {
        return $this->toAttributes() === [];
    }

    /**
     * The compact form parked in the session: only the recorded values, so a 5,000-row
     * upload stays small and a run with no extended metric adds nothing.
     *
     * @return array<string, int|float|string>
     */
    public function toSessionArray(): array
    {
        return $this->toAttributes();
    }

    /**
     * Rebuild from the compact session form. A key it lacks reads as null, so an upload
     * parked before a column existed still confirms after a deploy instead of failing
     * to rebuild and sending the owner back to upload the file again.
     *
     * @param  array<string, mixed>  $session
     */
    public static function fromSessionArray(array $session): self
    {
        $values = [];

        foreach (self::COLUMNS as $column => [$property, $type]) {
            $value = $session[$column] ?? null;

            $values[$property] = match (true) {
                $value === null => null,
                $type === 'int' && is_numeric($value) => (int) $value,
                $type === 'float' && is_numeric($value) => (float) $value,
                $type === 'string' && is_string($value) => $value,
                default => null,
            };
        }

        return new self(...$values);
    }

    /**
     * A JSON number as a float, or null for anything else (a numeric string is not a number
     * here: the payload is untrusted and a field of the wrong type is unreadable).
     */
    private static function number(mixed $value): ?float
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value) ? (float) $value : null;
    }

    /**
     * A number rounded to a whole value and kept only inside `[$min, $max]`; `$zeroIsEmpty`
     * turns a 0 into "not recorded".
     */
    private static function whole(mixed $value, int $min, int $max, bool $zeroIsEmpty): ?int
    {
        $decimal = self::decimal($value, (float) $min, (float) $max, $zeroIsEmpty, 0);

        return $decimal === null ? null : (int) $decimal;
    }

    /**
     * A number rounded to `$decimals` places (one by default, the precision of the
     * decimal columns) and kept only inside `[$min, $max]`.
     */
    private static function decimal(mixed $value, float $min, float $max, bool $zeroIsEmpty, int $decimals = 1): ?float
    {
        $number = self::number($value);

        if ($number === null) {
            return null;
        }

        $rounded = round($number, $decimals);

        if ($rounded < $min || $rounded > $max || ($zeroIsEmpty && $rounded === 0.0)) {
            return null;
        }

        return $rounded + 0.0;
    }

    /**
     * The place name Garmin resolved for the run: trimmed, cut at the column's width, and
     * "not recorded" when blank or not text.
     */
    private static function placeName(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return mb_substr(trim($value), 0, self::LOCATION_NAME_MAX_LENGTH);
    }
}
