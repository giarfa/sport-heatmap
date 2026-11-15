<?php

namespace App\Support\Heatmap;

use App\Data\Heatmap\AerobicEfficiency;
use App\Data\Heatmap\EfficiencyDot;
use App\Data\Heatmap\EfficiencyMeasurement;
use App\Data\Heatmap\EfficiencyWeek;
use App\Data\Heatmap\TrendTick;
use App\Data\Heatmap\WeekRow;
use App\Enums\AerobicEfficiencyState;
use App\Enums\EfficiencyRunKind;

/**
 * Turns an EfficiencyMeasurement into the aerobic-efficiency card: state, headline, note,
 * y axis, dots, the two lines, the weekly figures, and every string the card and its text
 * alternative print.
 *
 * Paces stay floats until the one rounding at the edge, to whole seconds, half-up. The
 * note's change is computed on those rounded seconds, so the figures it quotes always
 * agree with the headline and the table.
 */
final readonly class BuildAerobicEfficiency
{
    /** Slots in the SVG: each week owns 10 units of its 160-unit width. */
    private const SLOT_WIDTH = 10;

    private const POINT_OFFSET = 5;

    /** Where a run's dot starts inside its slot, before the weekday offset. */
    private const DOT_OFFSET = 1.5;

    /** The slot's usable width over its seven weekdays, so Sunday still lands inside the slot. */
    private const DOT_WEEKDAY_STEP = 7 / 6;

    private const PLOT_WIDTH = 160;

    /** Gridlines every this many seconds per km. */
    private const AXIS_STEP_SECONDS = 15;

    /** The week positions (0-based) that carry an x label: the Monday of each 4-week block. */
    private const X_LABEL_POSITIONS = [0, 4, 8, 12];

    private const EMPTY_SENTENCE = 'No runs in this window, so there is nothing to chart.';

    private const NO_MEDIAN_SENTENCE = 'No 4-week median yet: no 4 weeks hold 3 usable runs.';

    /**
     * @param  array<int, WeekRow>  $weeks  the window's rows, oldest first
     * @param  string  $windowLabel  the grid's date range, e.g. "6 Jul – 25 Oct 2026"
     */
    public function __invoke(EfficiencyMeasurement $measurement, array $weeks, string $windowLabel): AerobicEfficiency
    {
        if (! $measurement->hasRuns) {
            return AerobicEfficiency::sentence(AerobicEfficiencyState::Empty, self::EMPTY_SENTENCE);
        }

        if ($measurement->qualifyingCount < MeasureAerobicEfficiency::MIN_QUALIFYING_RUNS) {
            return AerobicEfficiency::sentence(
                AerobicEfficiencyState::NotEnoughRuns,
                'Not enough runs to measure efficiency: '.$measurement->qualifyingCount.' in this window, '
                .MeasureAerobicEfficiency::MIN_QUALIFYING_RUNS.' needed. A run counts when it is at least 3 km with pace and heart rate recorded.',
            );
        }

        $referenceHeartRate = (int) $measurement->referenceHeartRate;
        $weeks = array_values($weeks);

        $paces = array_map(static fn ($run): float => $run->paceAtReference, $measurement->runs);
        $paces = [...$paces, ...array_filter($measurement->linePoints, static fn (?float $point): bool => $point !== null)];

        if ($measurement->hasLastYear) {
            $paces = [...$paces, ...array_filter($measurement->lastYearPoints, static fn (?float $point): bool => $point !== null)];
        }

        if ($paces === []) {
            return AerobicEfficiency::sentence(AerobicEfficiencyState::NoMedian, self::NO_MEDIAN_SENTENCE);
        }

        /*
         * The axis must cover the dots and both lines: a steep run or last year's line can
         * sit outside the window's own medians, and a range sized to the line alone would
         * draw them outside the plot. Equal extremes widen by one step so the division
         * below never meets a zero range.
         */
        $top = (int) (floor(min($paces) / self::AXIS_STEP_SECONDS) * self::AXIS_STEP_SECONDS);
        $bottom = (int) (ceil(max($paces) / self::AXIS_STEP_SECONDS) * self::AXIS_STEP_SECONDS);

        if ($bottom === $top) {
            $bottom = $top + self::AXIS_STEP_SECONDS;
        }

        $median = $this->points($measurement->linePoints, $top, $bottom);
        $lastYear = $measurement->hasLastYear ? $this->points($measurement->lastYearPoints, $top, $bottom) : [];

        $medianSegments = $this->segments($median);
        $state = $medianSegments === [] ? AerobicEfficiencyState::NoMedian : AerobicEfficiencyState::Measured;

        $dots = [];

        foreach ($measurement->runs as $run) {
            $dots[] = new EfficiencyDot(
                kind: $run->kind,
                left: $this->decimal((self::SLOT_WIDTH * $run->weekPosition + self::DOT_OFFSET + $run->weekday * self::DOT_WEEKDAY_STEP) / self::PLOT_WIDTH * 100),
                top: $this->decimal($this->yFor($run->paceAtReference, $top, $bottom)),
            );
        }

        [$headlinePace, $headlineAt, $note] = $this->headlineAndNote($measurement, $referenceHeartRate);

        return new AerobicEfficiency(
            state: $state,
            message: $state === AerobicEfficiencyState::NoMedian ? self::NO_MEDIAN_SENTENCE : '',
            headlinePace: $state === AerobicEfficiencyState::Measured ? $headlinePace : '',
            headlineAt: $state === AerobicEfficiencyState::Measured ? $headlineAt : '',
            note: $note,
            ticks: $this->ticks($top, $bottom),
            xLabels: $this->xLabels($weeks),
            dots: $dots,
            medianSegments: $medianSegments,
            lastYearSegments: $this->segments($lastYear),
            weeks: $this->weekRows($measurement, $weeks),
            title: $this->title($measurement, $referenceHeartRate, $windowLabel),
            captionLabel: 'Pace at '.$referenceHeartRate.' bpm by week, '.$windowLabel,
            hasHilly: array_any($measurement->runs, static fn ($run): bool => $run->kind === EfficiencyRunKind::Hilly),
            hasSteep: array_any($measurement->runs, static fn ($run): bool => $run->kind === EfficiencyRunKind::Steep),
            hasLastYear: $measurement->hasLastYear,
        );
    }

    /**
     * @return array{0: string, 1: string, 2: string} the headline pace, "at N bpm", and the note
     */
    private function headlineAndNote(EfficiencyMeasurement $measurement, int $referenceHeartRate): array
    {
        $heartRateNote = $referenceHeartRate.' bpm is this window\'s median heart rate';
        $positions = array_keys(array_filter($measurement->linePoints, static fn (?float $point): bool => $point !== null));

        if ($positions === []) {
            return ['', 'at '.$referenceHeartRate.' bpm', $heartRateNote];
        }

        $first = $positions[0];
        $latest = $positions[array_key_last($positions)];
        $firstSeconds = $this->seconds((float) $measurement->linePoints[$first]);
        $latestSeconds = $this->seconds((float) $measurement->linePoints[$latest]);
        $headline = [$this->clock($latestSeconds), 'at '.$referenceHeartRate.' bpm'];

        if ($first === $latest) {
            return [...$headline, $heartRateNote];
        }

        $weeksAgo = ($latest - $first + 1).' weeks ago';
        $change = $firstSeconds - $latestSeconds;

        $comparison = match (true) {
            $change > 0 => $change.' s/km faster than '.$weeksAgo.' ('.$this->clock($firstSeconds).' /km)',
            $change < 0 => (-$change).' s/km slower than '.$weeksAgo.' ('.$this->clock($firstSeconds).' /km)',
            default => 'same pace as '.$weeksAgo,
        };

        return [...$headline, $comparison.' · '.$heartRateNote];
    }

    private function title(EfficiencyMeasurement $measurement, int $referenceHeartRate, string $windowLabel): string
    {
        $positions = array_keys(array_filter($measurement->linePoints, static fn (?float $point): bool => $point !== null));
        $title = 'Pace at '.$referenceHeartRate.' bpm per run, '.$windowLabel.', faster at the top';

        if ($positions === []) {
            return $title.'. Weekly figures below.';
        }

        $first = $this->clock($this->seconds((float) $measurement->linePoints[$positions[0]]));
        $latest = $this->clock($this->seconds((float) $measurement->linePoints[$positions[array_key_last($positions)]]));

        $median = count($positions) === 1 ? $latest : $first.' to '.$latest;

        return $title.'; 4-week median '.$median.' per km. Weekly figures below.';
    }

    /**
     * @param  array<int, WeekRow>  $weeks
     * @return array<int, EfficiencyWeek>
     */
    private function weekRows(EfficiencyMeasurement $measurement, array $weeks): array
    {
        $rows = [];

        foreach ($weeks as $position => $week) {
            $median = $measurement->linePoints[$position] ?? null;
            $lastYear = $measurement->hasLastYear ? ($measurement->lastYearPoints[$position] ?? null) : null;

            $rows[] = new EfficiencyWeek(
                rangeLabel: $week->rangeLabel(),
                runs: $measurement->runsPerWeek[$position] ?? 0,
                median: $median === null ? null : $this->clock($this->seconds($median)).' /km',
                lastYear: $lastYear === null ? null : $this->clock($this->seconds($lastYear)).' /km',
            );
        }

        return $rows;
    }

    /**
     * The y axis, fastest first: one tick every 15 s, the unit on the top tick only.
     *
     * @return array<int, TrendTick>
     */
    private function ticks(int $top, int $bottom): array
    {
        $ticks = [];

        for ($value = $top; $value <= $bottom; $value += self::AXIS_STEP_SECONDS) {
            $y = $this->yFor((float) $value, $top, $bottom);

            $ticks[] = new TrendTick(
                label: $this->clock($value).($value === $top ? ' /km' : ''),
                bottomPercent: $this->decimal(100 - $y),
                y: $this->decimal($y),
            );
        }

        return $ticks;
    }

    /**
     * @param  array<int, WeekRow>  $weeks
     * @return array<int, string>
     */
    private function xLabels(array $weeks): array
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
     * @param  array<int, float|null>  $values  seconds per km by week position
     * @return array<int, array{0: int, 1: float}|null> the x, y of each week's point, by position
     */
    private function points(array $values, int $top, int $bottom): array
    {
        $points = [];

        foreach ($values as $position => $value) {
            $points[$position] = $value === null
                ? null
                : [$position * self::SLOT_WIDTH + self::POINT_OFFSET, $this->yFor($value, $top, $bottom)];
        }

        return $points;
    }

    /**
     * @param  array<int, array{0: int, 1: float}|null>  $points
     * @return array<int, string>
     */
    private function segments(array $points): array
    {
        /*
         * Segments rather than one polyline, split at every missing point: a polyline
         * joined across a gap would draw a straight line through weeks with no median,
         * which is exactly the interpolation FR-023 forbids.
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
         * A one-point polyline draws nothing, so a lone point becomes a short horizontal
         * tick centred on its week instead of vanishing — the weekly trend's rule.
         */
        if (count($segment) === 1) {
            [$x, $y] = $segment[0];
            $segment = [[$x - 2, $y], [$x + 2, $y]];
        }

        $segments[] = implode(' ', array_map(fn (array $point): string => $point[0].','.$this->decimal($point[1]), $segment));
    }

    /**
     * Faster paces sit higher: the smallest pace is y = 0, the top of the plot.
     */
    private function yFor(float $pace, int $top, int $bottom): float
    {
        return ($pace - $top) / ($bottom - $top) * 100;
    }

    /**
     * Whole seconds, half-up. The one place a pace is rounded.
     */
    private function seconds(float $pace): int
    {
        return (int) floor($pace + 0.5);
    }

    /**
     * "6:29".
     */
    private function clock(int $seconds): string
    {
        return intdiv($seconds, 60).':'.str_pad((string) ($seconds % 60), 2, '0', STR_PAD_LEFT);
    }

    /**
     * Two decimals, the form the mockup's markup uses.
     */
    private function decimal(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
