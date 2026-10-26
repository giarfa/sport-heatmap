<?php

namespace App\Support\Garmin;

use App\Models\Activity;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Decides whether two records are the same run: same local date, starts at most
 * ±2 minutes apart, closest start wins.
 *
 * The Garmin import asks this one class both when it plans a file (to find a
 * conflict) and when it confirms (to re-check it). If each kept its own rule, a run
 * the review treated as "already stored" could be one the write treats as "new", and
 * the day would be counted twice.
 */
final readonly class StartTimeMatcher
{
    /**
     * Inclusive: "within ±2 minutes" includes exactly 2:00.
     */
    public const TOLERANCE_SECONDS = 120;

    /**
     * The candidate whose start is closest to $start on the same local date and
     * within the tolerance, or null. Ties go to the lowest id, so the answer never
     * depends on the order the candidates were loaded in.
     *
     * @param  iterable<Activity>  $candidates
     */
    public function closest(CarbonInterface $start, iterable $candidates): ?Activity
    {
        $match = null;
        $matchDifference = null;

        foreach ($candidates as $candidate) {
            $difference = $this->difference($start, $candidate);

            if ($difference === null) {
                continue;
            }

            if (
                $match === null
                || $difference < $matchDifference
                || ($difference === $matchDifference && $candidate->id < $match->id)
            ) {
                $match = $candidate;
                $matchDifference = $difference;
            }
        }

        return $match;
    }

    /**
     * Seconds between $start and the candidate's start when they are the same
     * run by this rule — same local date, within the tolerance — else null.
     */
    public function difference(CarbonInterface $start, Activity $candidate): ?int
    {
        if ($candidate->activity_date->format('Y-m-d') !== $start->format('Y-m-d')) {
            return null;
        }

        $difference = abs(self::wallClockSeconds($candidate->start_date_local) - self::wallClockSeconds($start));

        return $difference <= self::TOLERANCE_SECONDS ? $difference : null;
    }

    /**
     * Seconds of the wall-clock reading, ignoring the instance's timezone.
     *
     * Start times are local wall-clock values on both sides: the Garmin row builds
     * them in UTC, while Eloquent reads the stored column back in the
     * app timezone. Comparing the instants would shift one side by the app's UTC
     * offset and match runs hours apart; comparing the wall-clock readings cannot.
     */
    private static function wallClockSeconds(CarbonInterface $moment): int
    {
        return (int) CarbonImmutable::createFromFormat('Y-m-d H:i:s', $moment->format('Y-m-d H:i:s'), 'UTC')?->getTimestamp();
    }
}
