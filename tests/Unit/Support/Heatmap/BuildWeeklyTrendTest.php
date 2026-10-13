<?php

namespace Tests\Unit\Support\Heatmap;

use App\Data\Heatmap\WeeklyTrend;
use App\Support\Heatmap\BuildWeeklyTrend;
use App\Support\Heatmap\CalibrateIntensityScale;
use App\Support\Heatmap\HeatmapBuilder;
use App\Support\Heatmap\HeatmapWindow;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * Pins the weekly trend to the US-014 mockup's sample data (its README), the way
 * CalibrateIntensityScaleTest pins the legend to the heatmap mockup's.
 */
class BuildWeeklyTrendTest extends TestCase
{
    /** Saturday of the mockup's current week. */
    private const TODAY = '2026-09-05';

    /** The 16 weekly totals of the mockup's current window, in kilometres, the last one in progress. */
    private const CURRENT_WINDOW_KM = [38.8, 40.8, 50.1, 27.5, 55.2, 61.7, 63.9, 38.8, 5.0, 24.5, 56.9, 69.8, 70.5, 86.8, 43.5, 44.1];

    /** The three weeks before the current window (27 Apr, 4 May, 11 May), in metres. */
    private const LEAD_IN_METERS = [22_000, 0, 18_600];

    private function currentTrend(?string $oldestRun = '2026-03-31', array $weeklyKm = self::CURRENT_WINDOW_KM): WeeklyTrend
    {
        $today = CarbonImmutable::parse(self::TODAY);
        $window = HeatmapWindow::current($today);

        return $this->trendFor($window, $today, $weeklyKm, self::LEAD_IN_METERS, $oldestRun);
    }

    /**
     * @param  array<int, float>  $weeklyKm
     * @param  array<int, int>  $leadInMeters
     */
    private function trendFor(HeatmapWindow $window, CarbonImmutable $today, array $weeklyKm, array $leadInMeters, ?string $oldestRun): WeeklyTrend
    {
        /*
         * The rows come from HeatmapBuilder rather than being built by hand, so the bars
         * under test are the totals the grid itself would print. Each week's distance sits
         * on its Monday: the week total is all the trend reads.
         */
        $dailyMeters = [];
        foreach ($weeklyKm as $position => $kilometres) {
            $dailyMeters[$window->startsOn->addWeeks($position)->toDateString()] = (int) round($kilometres * 1000);
        }

        $grid = (new HeatmapBuilder(new CalibrateIntensityScale))($window, $dailyMeters, $today);

        return (new BuildWeeklyTrend)(
            $grid->weeks,
            $leadInMeters,
            $oldestRun === null ? null : CarbonImmutable::parse($oldestRun),
            $grid->label(),
        );
    }

    public function test_every_bar_and_average_of_the_mockup_sample_table_is_reproduced(): void
    {
        $trend = $this->currentTrend();

        /*
         * The mockup README prints 24.5, 33.0 and 39.0 for the 25 May, 20 Jul and 3 Aug weeks.
         * Those are the exact means 24.55, 33.05 and 39.05 km, which the mockup's generator
         * rounded down through float noise (98.19999999999999 / 4). The rule is half-up on
         * integer metres, so they read 24.6, 33.1 and 39.1 here; every other row is the mockup's.
         */
        $expected = [
            ['18 – 24 May', '38.8', '19.9'],
            ['25 – 31 May', '40.8', '24.6'],
            ['1 – 7 Jun', '50.1', '37.1'],
            ['8 – 14 Jun', '27.5', '39.3'],
            ['15 – 21 Jun', '55.2', '43.4'],
            ['22 – 28 Jun', '61.7', '48.6'],
            ['29 Jun – 5 Jul', '63.9', '52.1'],
            ['6 – 12 Jul', '38.8', '54.9'],
            ['13 – 19 Jul', '5.0', '42.4'],
            ['20 – 26 Jul', '24.5', '33.1'],
            ['27 Jul – 2 Aug', '56.9', '31.3'],
            ['3 – 9 Aug', '69.8', '39.1'],
            ['10 – 16 Aug', '70.5', '55.4'],
            ['17 – 23 Aug', '86.8', '71.0'],
            ['24 – 30 Aug', '43.5', '67.7'],
            ['31 Aug – 6 Sep', '44.1', null],
        ];

        $this->assertCount(16, $trend->bars);

        foreach ($expected as $position => [$range, $kilometres, $average]) {
            $this->assertSame($range, $trend->bars[$position]->rangeLabel, "range of week $position");
            $this->assertSame($kilometres, $trend->bars[$position]->kilometres, "total of week $position");
            $this->assertSame($average, $trend->bars[$position]->averageKilometres, "average of week $position");
        }
    }

    public function test_the_average_rounds_half_up_to_a_tenth_of_a_kilometre(): void
    {
        // (22.0 + 0.0 + 18.6 + 38.8) / 4 = 19.85 km: a float round() can answer 19.8.
        $this->assertSame('19.9', $this->currentTrend()->bars[0]->averageKilometres);
    }

    public function test_the_axis_and_the_line_match_the_mockup_markup_exactly(): void
    {
        $trend = $this->currentTrend();

        $this->assertSame(['0', '30', '60', '90 km'], array_map(fn ($tick) => $tick->label, $trend->ticks));
        $this->assertSame(['0.0', '33.33', '66.67', '100.0'], array_map(fn ($tick) => $tick->bottomPercent, $trend->ticks));
        $this->assertSame(['100.0', '66.67', '33.33', '0.0'], array_map(fn ($tick) => $tick->y, $trend->ticks));

        $this->assertSame(
            ['5,77.89 15,72.67 25,58.78 35,56.33 45,51.78 55,46.0 65,42.11 75,39.0 85,52.89 95,63.22 105,65.22 115,56.56 125,38.44 135,21.11 145,24.78'],
            $trend->lineSegments,
        );

        $this->assertSame('2', $trend->bars[0]->x);
        $this->assertSame('56.89', $trend->bars[0]->y);
        $this->assertSame('43.11', $trend->bars[0]->height);
        $this->assertSame('152', $trend->bars[15]->x);
        $this->assertSame('51.0', $trend->bars[15]->y);
        $this->assertSame('49.0', $trend->bars[15]->heightPercent);
    }

    public function test_the_partial_week_has_no_point_and_the_note_names_the_last_completed_week(): void
    {
        $trend = $this->currentTrend();

        $this->assertTrue($trend->bars[15]->isPartial);
        $this->assertTrue($trend->hasPartial);
        $this->assertSame('4-week average 67.7 km · week of 24 Aug', $trend->note());
        $this->assertSame('67.7 km', $trend->noteStrong);
    }

    public function test_the_x_labels_sit_on_weeks_one_five_nine_and_thirteen(): void
    {
        $this->assertSame(['18 May', '15 Jun', '13 Jul', '10 Aug'], $this->currentTrend()->xLabels);
    }

    public function test_the_card_texts_name_the_window_and_every_bar(): void
    {
        $trend = $this->currentTrend('2026-06-17');

        $this->assertSame('Weekly running distance, 18 May – 6 Sep 2026, oldest week first', $trend->title);
        $this->assertSame('18 – 24 May 2026: 38.8 km; no 4-week average yet', $trend->bars[0]->accessibleName);
        $this->assertSame('31 Aug – 6 Sep 2026: 44.1 km so far, partial week', $trend->bars[15]->accessibleName);
        $this->assertSame('24 – 30 Aug 2026: 43.5 km; 4-week average 67.7 km', $trend->bars[14]->accessibleName);
    }
}
