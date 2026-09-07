<?php

namespace App\Data\Heatmap;

/**
 * The five-level intensity scale a window's daily distances are bucketed
 * against: either calibrated from the window's own percentiles, or one of
 * the two documented fallbacks — sparse and degenerate — that share the
 * same fixed bands but differ in the note the legend prints.
 */
final readonly class IntensityScale
{
    private function __construct(
        private int $p25,
        private int $p50,
        private int $p75,
        private int $runningDayCount,
        private bool $calibrated,
        private bool $degenerate,
    ) {}

    public static function calibrated(int $p25, int $p50, int $p75, int $runningDayCount): self
    {
        return new self($p25, $p50, $p75, $runningDayCount, calibrated: true, degenerate: false);
    }

    public static function fixedBands(int $runningDayCount, bool $degenerate): self
    {
        return new self(5_000, 10_000, 20_000, $runningDayCount, calibrated: false, degenerate: $degenerate);
    }

    public function isCalibrated(): bool
    {
        return $this->calibrated;
    }

    /**
     * The heat level for a day's distance in metres.
     *
     * Upper-inclusive on each band: a distance landing exactly on a
     * threshold belongs to the lower level, which is how the legend reads
     * ("Easy 0.1 – 7.2 km", "Steady 7.2 – 9.9 km"). Comparison happens in
     * metres against the unrounded thresholds, because kilometres are a
     * display concern and rounding at the boundary would move cells
     * between levels.
     */
    public function levelFor(int $metres): int
    {
        return match (true) {
            $metres === 0 => 0,
            $metres <= $this->p25 => 1,
            $metres <= $this->p50 => 2,
            $metres <= $this->p75 => 3,
            default => 4,
        };
    }

    /**
     * @return array<int, LegendRow>
     */
    public function legendRows(): array
    {
        return [
            new LegendRow(0, 'Rest', '0 km'),
            new LegendRow(1, 'Easy', '0.1 – '.$this->kilometres($this->p25).' km'),
            new LegendRow(2, 'Steady', $this->kilometres($this->p25).' – '.$this->kilometres($this->p50).' km'),
            new LegendRow(3, 'Hard', $this->kilometres($this->p50).' – '.$this->kilometres($this->p75).' km'),
            new LegendRow(4, 'Peak', $this->kilometres($this->p75).'+ km'),
        ];
    }

    public function note(): string
    {
        $day = $this->runningDayCount === 1 ? 'day' : 'days';

        return match (true) {
            $this->calibrated => "Calibrated to your {$this->runningDayCount} running days in this window — recomputed after every sync.",
            $this->degenerate => 'The runs in this window are too alike to calibrate a scale — fixed bands are shown instead.',
            default => "Only {$this->runningDayCount} running {$day} in this window — too few to calibrate, so fixed bands are shown instead.",
        };
    }

    private function kilometres(int $metres): string
    {
        return number_format($metres / 1000, 1);
    }
}
