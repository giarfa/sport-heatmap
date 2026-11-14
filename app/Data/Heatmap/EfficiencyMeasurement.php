<?php

namespace App\Data\Heatmap;

/**
 * What FR-023 measures over one window: the reference heart rate, every drawn run, and the
 * rolling 4-week median per week for the window and for the same weeks a year earlier.
 *
 * A week with no point is null, never 0 and never interpolated: the line breaks there.
 */
final readonly class EfficiencyMeasurement
{
    /**
     * @param  int|null  $referenceHeartRate  the median heart rate of the window's qualifying runs, whole bpm; null when none qualify
     * @param  int  $qualifyingCount  the window's qualifying runs, before the outlier guard
     * @param  bool  $hasRuns  whether the window holds any stored run at all
     * @param  array<int, MeasuredRun>  $runs  the window's drawn runs only; lead-in runs are never drawn
     * @param  array<int, float|null>  $linePoints  seconds per km at the reference heart rate, by window week position
     * @param  array<int, float|null>  $lastYearPoints  the same, for the weeks 52 weeks earlier
     * @param  array<int, int>  $runsPerWeek  drawn runs (flat, hilly and steep) by window week position
     * @param  bool  $hasLastYear  whether any run is stored in the 16 weeks 52 weeks earlier
     */
    public function __construct(
        public ?int $referenceHeartRate,
        public int $qualifyingCount,
        public bool $hasRuns,
        public array $runs,
        public array $linePoints,
        public array $lastYearPoints,
        public array $runsPerWeek,
        public bool $hasLastYear,
    ) {}
}
