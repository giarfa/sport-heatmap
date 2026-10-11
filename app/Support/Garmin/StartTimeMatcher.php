<?php

namespace App\Support\Garmin;

use App\Models\Activity;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Decides whether two records are the same run: same local date, starts at most
 * ±2 minutes apart, closest start wins.
 *
 * The Garmin import (to find a conflict) and the Strava sync (to link a late twin)
 * both ask this one class. If each kept its own rule, a run the importer treated
 * as "already stored" could be one the sync treats as "new", and the day would be
 * counted twice.
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
        $date = $start->format('Y-m-d');
        $startSeconds = self::wallClockSeconds($start);

        $match = null;
        $matchDifference = null;

        foreach ($candidates as $candidate) {
            if ($candidate->activity_date->format('Y-m-d') !== $date) {
                continue;
            }

            $difference = abs(self::wallClockSeconds($candidate->start_date_local) - $startSeconds);

            if ($difference > self::TOLERANCE_SECONDS) {
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
     * Seconds of the wall-clock reading, ignoring the instance's timezone.
     *
     * Start times are local wall-clock values on both sides: the Strava and Garmin
     * DTOs build them in UTC, while Eloquent reads the stored column back in the
     * app timezone. Comparing the instants would shift one side by the app's UTC
     * offset and match runs hours apart; comparing the wall-clock readings cannot.
     */
    private static function wallClockSeconds(CarbonInterface $moment): int
    {
        return (int) CarbonImmutable::createFromFormat('Y-m-d H:i:s', $moment->format('Y-m-d H:i:s'), 'UTC')?->getTimestamp();
    }
}
