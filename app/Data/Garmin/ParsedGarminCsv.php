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
        public int $outsideWindow,
        public array $invalid,
    ) {}

    /**
     * Scalars only, so the session never holds a serialized object.
     *
     * @return array{
     *     rows: array<int, array{sport_type: string, name: string, distance_meters: int, moving_time_seconds: int, start_date_local: string}>,
     *     ignored_by_type: array<string, int>,
     *     outside_window: int,
     *     invalid: array<int, array{line: int, reason: string}>,
     * }
     */
    public function toSessionArray(): array
    {
        return [
            'rows' => array_map(static fn (GarminCsvRow $row): array => $row->toSessionArray(), $this->rows),
            'ignored_by_type' => $this->ignoredByType,
            'outside_window' => $this->outsideWindow,
            'invalid' => $this->invalid,
        ];
    }

    /**
     * @param  array{
     *     rows: array<int, array{sport_type: string, name: string, distance_meters: int, moving_time_seconds: int, start_date_local: string}>,
     *     ignored_by_type: array<string, int>,
     *     outside_window: int,
     *     invalid: array<int, array{line: int, reason: string}>,
     * }  $parsed
     */
    public static function fromSessionArray(array $parsed): self
    {
        return new self(
            rows: array_values(array_map(GarminCsvRow::fromSessionArray(...), $parsed['rows'])),
            ignoredByType: $parsed['ignored_by_type'],
            outsideWindow: $parsed['outside_window'],
            invalid: $parsed['invalid'],
        );
    }

    public function ignoredTotal(): int
    {
        return array_sum($this->ignoredByType);
    }
}
