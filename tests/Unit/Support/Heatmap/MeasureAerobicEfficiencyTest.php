<?php

namespace Tests\Unit\Support\Heatmap;

use App\Data\Heatmap\EfficiencyRun;
use App\Data\Heatmap\EfficiencySample;
use App\Enums\EfficiencyRunKind;
use App\Support\Heatmap\HeatmapWindow;
use App\Support\Heatmap\MeasureAerobicEfficiency;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class MeasureAerobicEfficiencyTest extends TestCase
{
    /** The window of Tuesday 13 Oct 2026: Monday 29 Jun to Sunday 18 Oct 2026. */
    private function window(): HeatmapWindow
    {
        return HeatmapWindow::current(CarbonImmutable::parse('2026-10-13'));
    }

    public function test_it_measures_a_window_of_flat_runs_at_one_reference_heart_rate(): void
    {
        $window = $this->window();
        $runs = [];

        foreach (range(8, 15) as $week) {
            $runs[] = new EfficiencyRun($window->startsOn->addWeeks($week), 8_000, 330, 148, 0);
        }

        $measurement = (new MeasureAerobicEfficiency)($window, new EfficiencySample($runs));

        $this->assertSame(148, $measurement->referenceHeartRate);
        $this->assertSame(8, $measurement->qualifyingCount);
        $this->assertSame(330.0, $measurement->linePoints[15]);
        $this->assertNull($measurement->linePoints[0]);
        $this->assertCount(8, $measurement->runs);
    }

    public function test_a_run_that_climbs_is_converted_to_its_flat_equivalent_distance(): void
    {
        $window = $this->window();
        $runs = [new EfficiencyRun($window->startsOn->addWeeks(15), 5_000, 360, 150, 40)];

        $measurement = (new MeasureAerobicEfficiency)($window, new EfficiencySample($runs));

        $this->assertSame(EfficiencyRunKind::Hilly, $measurement->runs[0]->kind);
        $this->assertEqualsWithDelta(360 * 5_000 / 5_320, $measurement->runs[0]->paceAtReference, 0.001);
    }
}
