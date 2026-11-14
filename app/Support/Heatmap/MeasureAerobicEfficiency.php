<?php

namespace App\Support\Heatmap;

use App\Data\Heatmap\EfficiencyMeasurement;
use App\Data\Heatmap\EfficiencyRun;
use App\Data\Heatmap\EfficiencySample;
use App\Data\Heatmap\MeasuredRun;
use App\Enums\EfficiencyRunKind;
use Carbon\CarbonImmutable;

/**
 * FR-023, as a pure calculation: how fast the athlete runs at their usual heart rate,
 * week by week, and the same weeks a year earlier.
 *
 * No Eloquent and no clock: it reads an EfficiencySample and a window, so every rule is
 * testable with plain values.
 */
final readonly class MeasureAerobicEfficiency
{
    /** Fewer qualifying runs in a window than this and the card states the count instead of a figure. */
    public const MIN_QUALIFYING_RUNS = 8;

    /** A shorter run says too little about aerobic fitness to count. */
    private const MIN_DISTANCE_METERS = 3_000;

    /** Climb in metres per km below which a run is flat. */
    private const FLAT_MAX_CLIMB = 5;

    /** Climb in metres per km above which a run is steep: drawn, never in a median. */
    private const HILLY_MAX_CLIMB = 10;

    /** Metres of flat running one metre of climb costs, in FR-023's flat-equivalent distance. */
    private const HILL_FACTOR = 8;

    /** A run this many bpm above the reference is a hard effort, not an easy one. */
    private const OUTLIER_BPM = 10;

    /** Weeks pooled into one line point, the week itself included. */
    private const MEDIAN_WEEKS = 4;

    /** Fewer flat runs than this in the pooled weeks and the hilly runs join. */
    private const MIN_FLAT_RUNS = 4;

    /** Fewer usable runs than this in the pooled weeks and there is no point. */
    private const MIN_USABLE_RUNS = 3;

    /** The comparison weeks sit exactly this far back so weekdays line up. */
    private const LAST_YEAR_WEEKS = 52;

    public function __invoke(HeatmapWindow $window, EfficiencySample $sample): EfficiencyMeasurement
    {
        $weekCount = (int) config('heatmap.window_weeks');

        $windowRuns = array_values(array_filter(
            $sample->runs,
            static fn (EfficiencyRun $run): bool => $window->contains($run->date),
        ));

        /*
         * The reference heart rate comes from the shown window's qualifying runs only.
         * Lead-in and last-year runs are left out so the value is the athlete's usual
         * heart rate for what is on screen, and so last year's line is read at the same
         * heart rate as this year's: a difference between the two lines is then a
         * difference in pace, not in effort.
         */
        $qualifyingHeartRates = [];

        foreach ($windowRuns as $run) {
            if ($this->qualifies($run)) {
                $qualifyingHeartRates[] = (float) $run->heartRate;
            }
        }

        $referenceHeartRate = $qualifyingHeartRates === []
            ? null
            : (int) floor($this->median($qualifyingHeartRates) + 0.5);

        $lastYearStart = $window->startsOn->subWeeks(self::LAST_YEAR_WEEKS);
        $hasLastYear = false;

        foreach ($sample->lastYearRuns as $run) {
            $position = $this->weekPosition($lastYearStart, $run->date);

            if ($position >= 0 && $position < $weekCount) {
                $hasLastYear = true;

                break;
            }
        }

        $emptyPoints = array_fill(0, $weekCount, null);

        if ($referenceHeartRate === null) {
            return new EfficiencyMeasurement(
                referenceHeartRate: null,
                qualifyingCount: 0,
                hasRuns: $windowRuns !== [],
                runs: [],
                linePoints: $emptyPoints,
                lastYearPoints: $emptyPoints,
                runsPerWeek: array_fill(0, $weekCount, 0),
                hasLastYear: $hasLastYear,
            );
        }

        $measured = $this->measure($sample->runs, $window->startsOn, $referenceHeartRate);
        $measuredLastYear = $this->measure($sample->lastYearRuns, $lastYearStart, $referenceHeartRate);

        $drawn = array_values(array_filter(
            $measured,
            static fn (MeasuredRun $run): bool => $run->weekPosition >= 0 && $run->weekPosition < $weekCount,
        ));

        $runsPerWeek = array_fill(0, $weekCount, 0);

        foreach ($drawn as $run) {
            $runsPerWeek[$run->weekPosition]++;
        }

        return new EfficiencyMeasurement(
            referenceHeartRate: $referenceHeartRate,
            qualifyingCount: count($qualifyingHeartRates),
            hasRuns: $windowRuns !== [],
            runs: $drawn,
            linePoints: $this->linePoints($measured, $weekCount),
            lastYearPoints: $this->linePoints($measuredLastYear, $weekCount),
            runsPerWeek: $runsPerWeek,
            hasLastYear: $hasLastYear,
        );
    }

    /**
     * The window's qualifying rule: long enough, pace and heart rate recorded, and not steep.
     * A null metric disqualifies the run; it is never read as 0.
     */
    private function qualifies(EfficiencyRun $run): bool
    {
        return $this->isMeasurable($run) && $this->kindOf($run) !== EfficiencyRunKind::Steep;
    }

    private function isMeasurable(EfficiencyRun $run): bool
    {
        return $run->distanceMeters >= self::MIN_DISTANCE_METERS
            && $run->paceSecondsPerKm !== null
            && $run->heartRate !== null;
    }

    /**
     * The climb is compared as integers, ascent × 1000 against distance × limit: the
     * division `ascent / (distance / 1000)` can land a hair above 10.0 for a run that is
     * exactly 10.0 m per km, and a rounding error must not move a run from Hilly to Steep.
     */
    private function kindOf(EfficiencyRun $run): EfficiencyRunKind
    {
        $climb = ($run->ascentMeters ?? 0) * 1_000;

        if ($climb < $run->distanceMeters * self::FLAT_MAX_CLIMB) {
            return EfficiencyRunKind::Flat;
        }

        if ($climb <= $run->distanceMeters * self::HILLY_MAX_CLIMB) {
            return EfficiencyRunKind::Hilly;
        }

        return EfficiencyRunKind::Steep;
    }

    /**
     * Every run that can be drawn, restated at the reference heart rate.
     *
     * @param  array<int, EfficiencyRun>  $runs
     * @return array<int, MeasuredRun>
     */
    private function measure(array $runs, CarbonImmutable $firstWeek, int $referenceHeartRate): array
    {
        $measured = [];

        foreach ($runs as $run) {
            /*
             * Outlier guard: a run more than 10 bpm above the reference is neither drawn
             * nor pooled. Scaling its pace down to the reference heart rate would credit a
             * hard effort with an easy pace and pull the line towards a fitness it did not
             * show.
             */
            if (! $this->isMeasurable($run) || $run->heartRate > $referenceHeartRate + self::OUTLIER_BPM) {
                continue;
            }

            $measured[] = new MeasuredRun(
                date: $run->date,
                weekday: $run->weekday(),
                weekPosition: $this->weekPosition($firstWeek, $run->date),
                kind: $this->kindOf($run),
                paceAtReference: $this->paceAtReference($run, $referenceHeartRate),
            );
        }

        return $measured;
    }

    /**
     * 1 km ÷ (efficiency × reference heart rate), with efficiency = speed ÷ heart rate.
     *
     * The speed comes from the stored average pace, the column the qualifying rule
     * requires, not from moving time: a run can have a pace and no moving time worth
     * trusting, and the two are only the same number when the watch paused cleanly. The
     * result stays a float; rounding twice would let a half-second error compound into
     * the tile's "s/km faster".
     *
     * A run that climbs is converted to its flat-equivalent distance (distance + 8 × ascent)
     * first. Steep runs are converted the same way so they can be drawn, and are excluded
     * from every median by their kind.
     */
    private function paceAtReference(EfficiencyRun $run, int $referenceHeartRate): float
    {
        $pace = (float) $run->paceSecondsPerKm;

        if ($this->kindOf($run) !== EfficiencyRunKind::Flat) {
            $distance = (float) $run->distanceMeters;
            $pace = $pace * $distance / ($distance + self::HILL_FACTOR * ($run->ascentMeters ?? 0));
        }

        return $pace * (float) $run->heartRate / $referenceHeartRate;
    }

    /**
     * The rolling median per week: that week and the three before it, lead-in weeks
     * included. Flat runs only; when fewer than 4 are pooled the hilly runs join; fewer than
     * 3 usable runs and the week has no point, so the line breaks rather than inventing one.
     *
     * @param  array<int, MeasuredRun>  $measured
     * @return array<int, float|null>
     */
    private function linePoints(array $measured, int $weekCount): array
    {
        $points = [];

        for ($position = 0; $position < $weekCount; $position++) {
            $flat = [];
            $hilly = [];

            foreach ($measured as $run) {
                if ($run->weekPosition > $position || $run->weekPosition <= $position - self::MEDIAN_WEEKS) {
                    continue;
                }

                if ($run->kind === EfficiencyRunKind::Flat) {
                    $flat[] = $run->paceAtReference;
                } elseif ($run->kind === EfficiencyRunKind::Hilly) {
                    $hilly[] = $run->paceAtReference;
                }
            }

            $usable = count($flat) < self::MIN_FLAT_RUNS ? [...$flat, ...$hilly] : $flat;

            $points[$position] = count($usable) < self::MIN_USABLE_RUNS ? null : $this->median($usable);
        }

        return $points;
    }

    /**
     * The middle value; the mean of the two middle values on an even count.
     *
     * @param  array<int, float>  $values
     */
    private function median(array $values): float
    {
        sort($values);

        $count = count($values);
        $middle = intdiv($count, 2);

        return $count % 2 === 1 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
    }

    /**
     * The 0-based week of a day, counted from a Monday and negative for the weeks before it.
     * Computed from the calendar dates in UTC so a daylight-saving change never turns a
     * 7-day week into 6.96 days and a run into the wrong week.
     */
    private function weekPosition(CarbonImmutable $firstWeek, CarbonImmutable $day): int
    {
        $days = intdiv(
            (int) strtotime($day->toDateString().' UTC') - (int) strtotime($firstWeek->toDateString().' UTC'),
            86_400,
        );

        return (int) floor($days / 7);
    }
}
