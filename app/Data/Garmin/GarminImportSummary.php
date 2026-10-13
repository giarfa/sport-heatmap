<?php

namespace App\Data\Garmin;

/**
 * What one confirmed import did, row by row, for the summary page.
 */
final readonly class GarminImportSummary
{
    /**
     * @param  array<string, int>  $ignoredByType  Garmin type name => rows of it
     * @param  array<int, array{line: int, reason: string}>  $invalid
     */
    public function __construct(
        public int $imported,
        public int $overwritten,
        public int $skipped,
        public int $alreadyImported,
        public array $ignoredByType,
        public int $futureDated,
        public array $invalid,
    ) {}

    public function ignoredTotal(): int
    {
        return array_sum($this->ignoredByType);
    }

    /**
     * @return array{
     *     imported: int,
     *     overwritten: int,
     *     skipped: int,
     *     already_imported: int,
     *     ignored_by_type: array<string, int>,
     *     future_dated: int,
     *     invalid: array<int, array{line: int, reason: string}>,
     * }
     */
    public function toSessionArray(): array
    {
        return [
            'imported' => $this->imported,
            'overwritten' => $this->overwritten,
            'skipped' => $this->skipped,
            'already_imported' => $this->alreadyImported,
            'ignored_by_type' => $this->ignoredByType,
            'future_dated' => $this->futureDated,
            'invalid' => $this->invalid,
        ];
    }

    /**
     * @param  array{
     *     imported: int,
     *     overwritten: int,
     *     skipped: int,
     *     already_imported: int,
     *     ignored_by_type: array<string, int>,
     *     future_dated?: int,
     *     outside_window?: int,
     *     invalid: array<int, array{line: int, reason: string}>,
     * }  $summary
     */
    public static function fromSessionArray(array $summary): self
    {
        /*
         * A summary flashed before US-013 carries `outside_window`; it is read when
         * `future_dated` is absent so a summary in flight across the deploy still renders.
         */
        return new self(
            imported: $summary['imported'],
            overwritten: $summary['overwritten'],
            skipped: $summary['skipped'],
            alreadyImported: $summary['already_imported'],
            ignoredByType: $summary['ignored_by_type'],
            futureDated: $summary['future_dated'] ?? $summary['outside_window'] ?? 0,
            invalid: $summary['invalid'],
        );
    }
}
