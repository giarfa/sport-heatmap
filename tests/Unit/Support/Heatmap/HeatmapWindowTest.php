<?php

namespace Tests\Unit\Support\Heatmap;

use App\Support\Heatmap\HeatmapWindow;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class HeatmapWindowTest extends TestCase
{
    private CarbonImmutable $today;

    protected function setUp(): void
    {
        parent::setUp();

        // A Tuesday: its week is Mon 12 Oct – Sun 18 Oct 2026.
        $this->today = CarbonImmutable::parse('2026-10-13');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function dayInTheSameWeekProvider(): array
    {
        return [
            'a Monday' => ['2026-08-31'],
            'a mid-week date' => ['2026-09-02'],
            'a Sunday' => ['2026-09-06'],
        ];
    }

    #[DataProvider('dayInTheSameWeekProvider')]
    public function test_any_day_of_a_week_names_the_window_ending_in_that_week(string $requested): void
    {
        $window = HeatmapWindow::fromRequestedEnd($requested, $this->today);

        // Mon 31 Aug ... Sun 6 Sep is the ISO week of every requested day above, so the
        // window is the spec's "18 May – 6 Sep 2026", not one ending on the requested day.
        $this->assertSame('2026-09-06', $window->endsOn->toDateString());
        $this->assertSame('2026-05-18', $window->startsOn->toDateString());
        $this->assertSame(112, $window->days());
    }

    public function test_a_window_across_a_year_boundary_starts_on_a_monday_fifteen_weeks_earlier(): void
    {
        $window = HeatmapWindow::fromRequestedEnd('2027-01-04', CarbonImmutable::parse('2027-02-01'));

        $this->assertSame('2027-01-10', $window->endsOn->toDateString());
        $this->assertSame('2026-09-21', $window->startsOn->toDateString());
        $this->assertTrue($window->startsOn->isMonday());
        $this->assertSame(112, $window->days());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unusableValueProvider(): array
    {
        return [
            'an impossible date' => ['2026-02-31'],
            'an unpadded month and day' => ['2026-8-9'],
            'a day-first date' => ['09-08-2026'],
            'an empty value' => [''],
            'free text' => ['abc'],
            'a trailing time' => ['2026-09-06 10:00'],
            'an out-of-range month' => ['2026-13-01'],
        ];
    }

    #[DataProvider('unusableValueProvider')]
    public function test_a_value_that_is_not_a_real_date_gives_the_current_window(string $requested): void
    {
        $window = HeatmapWindow::fromRequestedEnd($requested, $this->today);

        $this->assertTrue($window->isCurrent($this->today));
        $this->assertSame('2026-10-18', $window->endsOn->toDateString());
    }

    public function test_a_date_in_a_week_after_the_current_one_gives_the_current_window(): void
    {
        $window = HeatmapWindow::fromRequestedEnd('2026-10-19', $this->today);

        $this->assertTrue($window->isCurrent($this->today));
    }

    public function test_the_current_week_names_the_current_window(): void
    {
        $window = HeatmapWindow::fromRequestedEnd('2026-10-12', $this->today);

        $this->assertTrue($window->isCurrent($this->today));
        $this->assertEquals(HeatmapWindow::current($this->today), $window);
    }

    public function test_previous_ends_four_weeks_earlier_and_next_returns_to_the_current_window(): void
    {
        $current = HeatmapWindow::current($this->today);

        $previous = $current->previous();

        $this->assertSame('2026-09-20', $previous->endsOn->toDateString());
        $this->assertSame(112, $previous->days());
        $this->assertFalse($previous->isCurrent($this->today));
        $this->assertEquals($current, $previous->next($this->today));
    }

    public function test_next_from_less_than_a_step_behind_is_clamped_to_the_current_window(): void
    {
        $twoWeeksBehind = HeatmapWindow::endingInWeekOf($this->today->subWeeks(2));

        $this->assertEquals(HeatmapWindow::current($this->today), $twoWeeksBehind->next($this->today));
    }

    public function test_next_of_the_current_window_is_the_current_window(): void
    {
        $current = HeatmapWindow::current($this->today);

        $this->assertEquals($current, $current->next($this->today));
    }
}
