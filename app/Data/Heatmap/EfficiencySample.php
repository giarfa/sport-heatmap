<?php

namespace App\Data\Heatmap;

/**
 * The runs the efficiency card reads: the shown window with its three lead-in weeks, and
 * the same nineteen weeks exactly 52 weeks earlier.
 */
final readonly class EfficiencySample
{
    /**
     * @param  array<int, EfficiencyRun>  $runs  the window and its lead-in weeks, in start order
     * @param  array<int, EfficiencyRun>  $lastYearRuns  the same weeks 52 weeks earlier, in start order
     */
    public function __construct(
        public array $runs = [],
        public array $lastYearRuns = [],
    ) {}
}
