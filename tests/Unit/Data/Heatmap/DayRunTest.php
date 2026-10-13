<?php

namespace Tests\Unit\Data\Heatmap;

use App\Data\Heatmap\DayRun;
use App\Models\Activity;
use Tests\TestCase;

class DayRunTest extends TestCase
{
    public function test_it_formats_every_value_with_its_unit(): void
    {
        $run = $this->dayRun();

        $this->assertSame('8.3 km', $run->kilometres());
        $this->assertSame('0:44:37', $run->movingTime());
        $this->assertSame('5:23 /km', $run->pace());
        $this->assertSame('45 m', $run->ascent());
        $this->assertSame('152 bpm', $run->heartRate());
    }

    public function test_a_metric_that_was_not_recorded_is_null_not_zero(): void
    {
        $run = $this->dayRun(averagePaceSecondsPerKm: null, totalAscentMeters: null, averageHeartRate: null);

        $this->assertNull($run->pace());
        $this->assertNull($run->ascent());
        $this->assertNull($run->heartRate());
    }

    public function test_a_flat_run_still_prints_its_zero_ascent(): void
    {
        $this->assertSame('0 m', $this->dayRun(totalAscentMeters: 0)->ascent());
    }

    public function test_a_strava_run_links_to_strava_and_names_its_source(): void
    {
        $run = DayRun::fromActivity(Activity::factory()->make(['user_id' => 1, 'strava_activity_id' => 4_242]));

        $this->assertSame('https://www.strava.com/activities/4242', $run->stravaUrl);
        $this->assertSame('Strava', $run->sourceLabel());
    }

    public function test_a_garmin_run_has_no_link_even_when_it_keeps_a_strava_id(): void
    {
        $imported = DayRun::fromActivity(Activity::factory()->garminImported()->make(['user_id' => 1]));
        $twin = DayRun::fromActivity(Activity::factory()->garminOverwritten()->make(['user_id' => 1, 'strava_activity_id' => 4_242]));

        $this->assertNull($imported->stravaUrl);
        $this->assertNull($twin->stravaUrl);
        $this->assertSame('Garmin import', $imported->sourceLabel());
        $this->assertSame('Garmin import', $twin->sourceLabel());
    }

    public function test_it_carries_the_activitys_values_and_no_model(): void
    {
        $run = DayRun::fromActivity(Activity::factory()->withoutHeartRate()->make([
            'user_id' => 1,
            'name' => 'Paese Corsa',
            'distance_meters' => 8_290,
            'moving_time_seconds' => 3_263,
            'average_pace_seconds_per_km' => 394,
            'total_ascent_meters' => 12,
        ]));

        $this->assertSame('Paese Corsa', $run->name);
        $this->assertSame(8_290, $run->distanceMeters);
        $this->assertSame(3_263, $run->movingTimeSeconds);
        $this->assertSame(394, $run->averagePaceSecondsPerKm);
        $this->assertSame(12, $run->totalAscentMeters);
        $this->assertNull($run->averageHeartRate);
    }

    private function dayRun(
        ?int $averagePaceSecondsPerKm = 323,
        ?int $totalAscentMeters = 45,
        ?int $averageHeartRate = 152,
    ): DayRun {
        return new DayRun(
            name: 'Morning run',
            distanceMeters: 8_290,
            movingTimeSeconds: 2_677,
            averagePaceSecondsPerKm: $averagePaceSecondsPerKm,
            totalAscentMeters: $totalAscentMeters,
            averageHeartRate: $averageHeartRate,
            stravaUrl: null,
        );
    }
}
