<?php

namespace Tests\Unit\Support\Heatmap;

use App\Data\Heatmap\AerobicEfficiency;
use App\Data\Heatmap\DayCell;
use App\Data\Heatmap\EfficiencyMeasurement;
use App\Data\Heatmap\WeekRow;
use App\Enums\AerobicEfficiencyState;
use App\Enums\DayCellState;
use App\Support\Heatmap\BuildAerobicEfficiency;
use App\Support\Heatmap\HeatmapWindow;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class BuildAerobicEfficiencyTest extends TestCase
{
    public function test_the_headline_and_note_read_the_first_and_latest_points(): void
    {
        $points = array_fill(0, 16, null);
        $points[0] = 414.0;
        $points[15] = 389.0;

        $card = $this->build($this->measurement(linePoints: $points));

        $this->assertSame(AerobicEfficiencyState::Measured, $card->state);
        $this->assertSame('6:29', $card->headlinePace);
        $this->assertSame('at 148 bpm', $card->headlineAt);
        $this->assertSame("25 s/km faster than 16 weeks ago (6:54 /km) · 148 bpm is this window's median heart rate", $card->note);
    }

    /**
     * The window of Tuesday 13 Oct 2026: Monday 29 Jun to Sunday 18 Oct 2026.
     */
    private function build(EfficiencyMeasurement $measurement): AerobicEfficiency
    {
        $window = HeatmapWindow::current(CarbonImmutable::parse('2026-10-13'));
        $weeks = [];

        foreach (range(0, 15) as $position) {
            $weekStart = $window->startsOn->addWeeks($position);
            $days = array_map(
                fn (int $day): DayCell => new DayCell($weekStart->addDays($day), 0, DayCellState::Rest, 0),
                range(0, 6),
            );
            $weeks[] = new WeekRow($weekStart, $days, 0, false, false);
        }

        return (new BuildAerobicEfficiency)($measurement, $weeks, '29 Jun – 18 Oct 2026');
    }

    /**
     * @param  array<int, float|null>  $linePoints
     */
    private function measurement(array $linePoints): EfficiencyMeasurement
    {
        return new EfficiencyMeasurement(
            referenceHeartRate: 148,
            qualifyingCount: 20,
            hasRuns: true,
            runs: [],
            linePoints: $linePoints,
            lastYearPoints: array_fill(0, 16, null),
            runsPerWeek: array_fill(0, 16, 0),
            hasLastYear: false,
        );
    }
}
