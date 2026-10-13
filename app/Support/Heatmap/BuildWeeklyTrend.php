<?php

namespace App\Support\Heatmap;

use App\Data\Heatmap\TrendBar;
use App\Data\Heatmap\TrendTick;
use App\Data\Heatmap\WeeklyTrend;
use App\Data\Heatmap\WeekRow;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Turns a window's weekly totals into the weekly-mileage chart: bars, the 4-week
 * rolling average, the y axis, and every string the card and its text alternative print.
 *
 * Distances stay in integer metres until the one rounding at the edge, and every
 * geometry value is derived from the 0.1-km figure the reader sees, so a bar cannot
 * be drawn at a height its own label disagrees with.
 */
final readonly class BuildWeeklyTrend
{
    /** Weeks averaged into one point, the week itself included. */
    private const AVERAGE_WEEKS = 4;

    /** Slots in the SVG: each week owns 10 units of its 160-unit width. */
    private const SLOT_WIDTH = 10;

    private const BAR_OFFSET = 2;

    private const BAR_WIDTH = 6;

    private const POINT_OFFSET = 5;

    /** The week positions (0-based) that carry an x label: the Monday of each 4-week block. */
    private const X_LABEL_POSITIONS = [0, 4, 8, 12];

    /** The gridline steps in kilometres, smallest first. */
    private const AXIS_STEPS = [5, 10, 20, 25, 30, 40, 50, 75, 100, 150, 200];

    /**
     * @param  array<int, WeekRow>  $weeks  the window's rows, oldest first
     * @param  array<int, int>  $leadInWeekMeters  the three weeks before the window in metres, oldest first
     * @param  CarbonInterface|null  $oldestRun  the athlete's oldest stored run
     * @param  string  $windowLabel  the grid's date range, e.g. "18 May – 6 Sep 2026"
     */
    public function __invoke(array $weeks, array $leadInWeekMeters, ?CarbonInterface $oldestRun, string $windowLabel): WeeklyTrend
    {
        $hasRuns = false;

        foreach ($weeks as $week) {
            if ($week->totalMeters > 0) {
                $hasRuns = true;

                break;
            }
        }

        $title = 'Weekly running distance, '.$windowLabel.', oldest week first';
        $captionLabel = 'Weekly total and 4-week rolling average, '.$windowLabel.', oldest week first';

        if (! $hasRuns) {
            return new WeeklyTrend([], [], [], [], $title, $captionLabel, 'The 4-week average starts once 4 weeks of runs are stored.', '', '', false, false, false);
        }

        $averageTenths = $this->averageTenths($weeks, $leadInWeekMeters, $oldestRun);
        $barTenths = array_map(fn (WeekRow $week): int => $this->tenthsOf($week), $weeks);

        /*
         * The axis must cover the line as well as the bars: a point fed by lead-in weeks
         * can outrun every bar of a window that follows a big block, and a top sized to
         * the bars alone would draw the line outside the plot.
         */
        $maxTenths = max(array_merge($barTenths, array_filter($averageTenths, fn (?int $tenths): bool => $tenths !== null)));
        $stepTenths = $this->axisStepTenths($maxTenths);
        $topTenths = $stepTenths * max(1, intdiv($maxTenths + $stepTenths - 1, $stepTenths));

        $bars = [];
        $points = [];

        foreach ($weeks as $position => $week) {
            $average = $averageTenths[$position];
            $bars[] = $this->buildBar($week, $position, $barTenths[$position], $average, $topTenths);
            $points[$position] = $average === null
                ? null
                : [$position * self::SLOT_WIDTH + self::POINT_OFFSET, $this->yFor($average, $topTenths)];
        }

        [$noteBefore, $noteStrong, $noteAfter] = $this->buildNote($weeks, $averageTenths);

        return new WeeklyTrend(
            bars: $bars,
            ticks: $this->buildTicks($stepTenths, $topTenths),
            xLabels: $this->buildXLabels($weeks),
            lineSegments: $this->buildSegments($points),
            title: $title,
            captionLabel: $captionLabel,
            noteBefore: $noteBefore,
            noteStrong: $noteStrong,
            noteAfter: $noteAfter,
            hasRuns: true,
            hasPartial: array_any($bars, fn (TrendBar $bar): bool => $bar->isPartial),
            hasZeroWeek: array_any($bars, fn (TrendBar $bar): bool => $bar->isZero),
        );
    }

    /**
     * The 4-week average of every week in tenths of a kilometre, or null where no point is drawn.
     *
     * @param  array<int, WeekRow>  $weeks
     * @param  array<int, int>  $leadInWeekMeters
     * @return array<int, int|null>
     */
    private function averageTenths(array $weeks, array $leadInWeekMeters, ?CarbonInterface $oldestRun): array
    {
        $series = array_merge(
            array_map(intval(...), array_values($leadInWeekMeters)),
            array_map(fn (WeekRow $week): int => $week->totalMeters, array_values($weeks)),
        );
        $leadInCount = count($leadInWeekMeters);

        /*
         * A week averages the three weeks before it only once those weeks exist in the
         * athlete's history. Before that they are unknown, not 0 km, so the line starts
         * late instead of dipping to a figure that was never run.
         */
        $firstAveragedWeek = $oldestRun === null
            ? null
            : CarbonImmutable::instance($oldestRun)->startOfWeek()->addWeeks(self::AVERAGE_WEEKS - 1);

        $tenths = [];

        foreach (array_values($weeks) as $position => $week) {
            if ($week->isPartial || $firstAveragedWeek === null || $week->weekStart->lessThan($firstAveragedWeek)) {
                $tenths[$position] = null;

                continue;
            }

            $metres = array_sum(array_slice($series, $leadInCount + $position - (self::AVERAGE_WEEKS - 1), self::AVERAGE_WEEKS));

            /*
             * Integer half-up division to 0.1 km: 79,400 m / 4 = 19.85 km must read 19.9,
             * where a float round() of a binary 19.85 can land on 19.8 and desynchronise
             * the line from the mockup's pinned figures.
             */
            $tenths[$position] = intdiv($metres + 200, 400);
        }

        return $tenths;
    }

    /**
     * The week's total in tenths of a kilometre, read from the string the grid prints
     * so the bar and its row can never differ by a rounding step.
     */
    private function tenthsOf(WeekRow $week): int
    {
        return (int) round((float) str_replace(',', '', $week->kilometres()) * 10);
    }

    private function axisStepTenths(int $maxTenths): int
    {
        foreach (self::AXIS_STEPS as $step) {
            if ($step * 10 * 3 >= $maxTenths) {
                return $step * 10;
            }
        }

        return 2000;
    }

    private function buildBar(WeekRow $week, int $position, int $tenths, ?int $averageTenths, int $topTenths): TrendBar
    {
        $heightPercent = $tenths / $topTenths * 100;
        $x = $position * self::SLOT_WIDTH + self::BAR_OFFSET;
        $kilometres = $week->kilometres();

        return new TrendBar(
            rangeLabel: $this->rangeLabel($week->weekStart, false),
            accessibleName: $this->accessibleName($week, $kilometres, $averageTenths),
            kilometres: $kilometres,
            averageKilometres: $averageTenths === null ? null : $this->kilometresOf($averageTenths),
            x: (string) $x,
            xEnd: (string) ($x + self::BAR_WIDTH),
            y: $this->decimal(100 - $heightPercent),
            height: $this->decimal($heightPercent),
            heightPercent: $this->decimal($heightPercent),
            isPartial: $week->isPartial,
            isZero: $tenths === 0,
        );
    }

    private function accessibleName(WeekRow $week, string $kilometres, ?int $averageTenths): string
    {
        $range = $this->rangeLabel($week->weekStart, true);

        if ($week->isPartial) {
            return $range.': '.$kilometres.' km so far, partial week';
        }

        return $averageTenths === null
            ? $range.': '.$kilometres.' km; no 4-week average yet'
            : $range.': '.$kilometres.' km; 4-week average '.$this->kilometresOf($averageTenths).' km';
    }

    /**
     * A week's Monday-to-Sunday range: "18 – 24 May", "29 Jun – 5 Jul", and with the
     * year "29 Dec 2025 – 4 Jan 2026" when it crosses one.
     */
    private function rangeLabel(CarbonImmutable $weekStart, bool $withYear): string
    {
        $weekEnd = $weekStart->addDays(6);

        if ($weekStart->year !== $weekEnd->year) {
            return $withYear
                ? $weekStart->format('j M Y').' – '.$weekEnd->format('j M Y')
                : $weekStart->format('j M').' – '.$weekEnd->format('j M');
        }

        $range = $weekStart->month === $weekEnd->month
            ? $weekStart->format('j').' – '.$weekEnd->format('j M')
            : $weekStart->format('j M').' – '.$weekEnd->format('j M');

        return $withYear ? $range.' '.$weekEnd->format('Y') : $range;
    }

    /**
     * @return array<int, TrendTick>
     */
    private function buildTicks(int $stepTenths, int $topTenths): array
    {
        $ticks = [];

        for ($value = 0; $value <= $topTenths; $value += $stepTenths) {
            $percent = $value / $topTenths * 100;
            $label = (string) intdiv($value, 10);

            $ticks[] = new TrendTick(
                label: $value === $topTenths ? $label.' km' : $label,
                bottomPercent: $this->decimal($percent),
                y: $this->decimal(100 - $percent),
            );
        }

        return $ticks;
    }

    /**
     * @param  array<int, WeekRow>  $weeks
     * @return array<int, string>
     */
    private function buildXLabels(array $weeks): array
    {
        $labels = [];

        foreach (self::X_LABEL_POSITIONS as $position) {
            if (isset($weeks[$position])) {
                $labels[] = $weeks[$position]->weekLabel();
            }
        }

        return $labels;
    }

    /**
     * @param  array<int, array{0: int, 1: float}|null>  $points  by week position
     * @return array<int, string>
     */
    private function buildSegments(array $points): array
    {
        /*
         * Segments rather than one polyline: the rules that leave a week without a point
         * today only ever cut the start and the end of the line, but a polyline joined
         * across a gap would draw a straight line through weeks with no average. Splitting
         * at every missing point keeps that impossible by construction.
         */
        $segments = [];
        $current = [];

        foreach ($points as $point) {
            if ($point === null) {
                $this->closeSegment($segments, $current);
                $current = [];

                continue;
            }

            $current[] = $point;
        }

        $this->closeSegment($segments, $current);

        return $segments;
    }

    /**
     * @param  array<int, string>  $segments
     * @param  array<int, array{0: int, 1: float}>  $segment
     */
    private function closeSegment(array &$segments, array $segment): void
    {
        if ($segment === []) {
            return;
        }

        /*
         * A one-point polyline draws nothing, so a lone average becomes a short horizontal
         * tick centred on its week instead of vanishing.
         */
        if (count($segment) === 1) {
            [$x, $y] = $segment[0];
            $segment = [[$x - 2, $y], [$x + 2, $y]];
        }

        $segments[] = implode(' ', array_map(fn (array $point): string => $point[0].','.$this->decimal($point[1]), $segment));
    }

    private function yFor(int $tenths, int $topTenths): float
    {
        return 100 - $tenths / $topTenths * 100;
    }

    /**
     * @param  array<int, WeekRow>  $weeks
     * @param  array<int, int|null>  $averageTenths
     * @return array{0: string, 1: string, 2: string}
     */
    private function buildNote(array $weeks, array $averageTenths): array
    {
        $lastPosition = null;

        foreach ($averageTenths as $position => $tenths) {
            if ($tenths !== null) {
                $lastPosition = $position;
            }
        }

        if ($lastPosition === null) {
            return ['The 4-week average starts once 4 weeks of runs are stored.', '', ''];
        }

        return [
            '4-week average ',
            $this->kilometresOf((int) $averageTenths[$lastPosition]).' km',
            ' · week of '.$weeks[$lastPosition]->weekLabel(),
        ];
    }

    private function kilometresOf(int $tenths): string
    {
        return number_format($tenths / 10, 1);
    }

    /**
     * Two decimals with one trailing zero dropped — "29.0", "56.89", "66.67" — the form
     * the mockup's markup uses, so the output can be compared with it character for character.
     */
    private function decimal(float $value): string
    {
        $text = number_format($value, 2, '.', '');

        return str_ends_with($text, '0') ? substr($text, 0, -1) : $text;
    }
}
