<?php

namespace App\Data\Garmin;

/**
 * Everything one Garmin CSV yielded: the running rows to plan, and the counts
 * and reasons for every row that will not be imported.
 */
final readonly class ParsedGarminCsv
{
    /**
     * @param  array<int, GarminCsvRow>  $rows
     * @param  array<string, int>  $ignoredByType  Garmin type name => rows of it
     * @param  array<int, array{line: int, reason: string}>  $invalid
     */
    public function __construct(
        public array $rows,
        public array $ignoredByType,
        public int $futureDated,
        public array $invalid,
    ) {}

    /**
     * Scalars only, so the session never holds a serialized object.
     *
     * @return array{
     *     rows: array<int, array{sport_type: string, name: string, distance_meters: int, moving_time_seconds: int, start_date_local: string, average_pace_seconds_per_km?: int|null, total_ascent_meters?: int|null, average_heart_rate?: int|null}>,
     *     ignored_by_type: array<string, int>,
     *     future_dated: int,
     *     invalid: array<int, array{line: int, reason: string}>,
     * }
     */
    public function toSessionArray(): array
    {
        return [
            'rows' => array_map(static fn (GarminCsvRow $row): array => $row->toSessionArray(), $this->rows),
            'ignored_by_type' => $this->ignoredByType,
            'future_dated' => $this->futureDated,
            'invalid' => $this->invalid,
        ];
    }

    /**
     * @param  array{
     *     rows: array<int, array{sport_type: string, name: string, distance_meters: int, moving_time_seconds: int, start_date_local: string, average_pace_seconds_per_km?: int|null, total_ascent_meters?: int|null, average_heart_rate?: int|null}>,
     *     ignored_by_type: array<string, int>,
     *     future_dated?: int,
     *     outside_window?: int,
     *     invalid: array<int, array{line: int, reason: string}>,
     * }  $parsed
     */
    public static function fromSessionArray(array $parsed): self
    {
        /*
         * A pending upload stored in a session before US-013 carries `outside_window`, the
         * count of rows the old importer dropped for being outside the 112 days. It must
         * still open, so the legacy key is read when the new one is absent. Those rows were
         * dropped under the old rule and are not in the import: the number is carried over
         * so the review does not silently forget them, even though the label is now the
         * new one. The session lives for one visit, so this branch dies out by itself.
         */
        return new self(
            rows: array_values(array_map(GarminCsvRow::fromSessionArray(...), $parsed['rows'])),
            ignoredByType: $parsed['ignored_by_type'],
            futureDated: $parsed['future_dated'] ?? $parsed['outside_window'] ?? 0,
            invalid: $parsed['invalid'],
        );
    }

    public function ignoredTotal(): int
    {
        return array_sum($this->ignoredByType);
    }
}
