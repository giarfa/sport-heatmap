<?php

namespace Tests\Unit\Data;

use App\Data\StravaActivity;
use App\Enums\ActivitySource;
use App\Enums\SportType;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class StravaActivityTest extends TestCase
{
    public function test_a_running_activity_is_built_from_a_payload(): void
    {
        $activity = StravaActivity::fromApiPayload($this->stravaActivityPayload());

        $this->assertNotNull($activity);
        $this->assertSame(12_345_678, $activity->stravaActivityId);
        $this->assertSame(SportType::Run, $activity->sportType);
        $this->assertSame('Morning Run', $activity->name);
    }

    /**
     * The sync writes source explicitly, so an upsert can never leave a row it owns
     * marked as anything but Strava-sourced.
     */
    public function test_its_attributes_mark_the_row_as_strava_sourced(): void
    {
        $activity = StravaActivity::fromApiPayload($this->stravaActivityPayload());

        $this->assertSame(ActivitySource::Strava, $activity?->toAttributes()['source']);
    }

    public function test_a_non_running_sport_type_is_discarded(): void
    {
        $activity = StravaActivity::fromApiPayload($this->stravaActivityPayload(['sport_type' => 'Ride']));

        $this->assertNull($activity);
    }

    public function test_the_legacy_type_key_is_used_only_when_sport_type_is_absent(): void
    {
        $payload = $this->stravaActivityPayload(['type' => 'TrailRun']);
        unset($payload['sport_type']);

        $activity = StravaActivity::fromApiPayload($payload);

        $this->assertNotNull($activity);
        $this->assertSame(SportType::TrailRun, $activity->sportType);
    }

    public function test_an_invalid_sport_type_is_not_rescued_by_the_legacy_type_key(): void
    {
        $activity = StravaActivity::fromApiPayload($this->stravaActivityPayload([
            'sport_type' => 'Ride',
            'type' => 'Run',
        ]));

        $this->assertNull($activity);
    }

    public function test_float_metres_round_to_the_nearest_integer(): void
    {
        $activity = StravaActivity::fromApiPayload($this->stravaActivityPayload(['distance' => 10_234.6]));

        $this->assertSame(10_235, $activity?->distanceMeters);
    }

    public function test_a_late_evening_local_start_attributes_to_the_same_calendar_day_under_a_non_utc_timezone(): void
    {
        Config::set('app.timezone', 'Australia/Sydney');

        $activity = StravaActivity::fromApiPayload($this->stravaActivityPayload([
            'start_date_local' => '2026-06-14T23:40:00Z',
        ]));

        $this->assertNotNull($activity);
        $this->assertSame('2026-06-14', $activity->activityDate->toDateString());
        $this->assertSame('2026-06-14 23:40:00', $activity->startDateLocal->format('Y-m-d H:i:s'));
    }

    public function test_a_payload_without_an_identity_returns_null(): void
    {
        $payload = $this->stravaActivityPayload();
        unset($payload['id']);

        $this->assertNull(StravaActivity::fromApiPayload($payload));
    }

    public function test_a_payload_with_a_malformed_start_date_returns_null(): void
    {
        $activity = StravaActivity::fromApiPayload($this->stravaActivityPayload([
            'start_date_local' => 'not-a-date',
        ]));

        $this->assertNull($activity);
    }

    public function test_a_missing_name_falls_back_to_run(): void
    {
        $payload = $this->stravaActivityPayload();
        unset($payload['name']);

        $activity = StravaActivity::fromApiPayload($payload);

        $this->assertSame('Run', $activity?->name);
    }

    public function test_a_missing_moving_time_defaults_to_zero(): void
    {
        $payload = $this->stravaActivityPayload();
        unset($payload['moving_time']);

        $activity = StravaActivity::fromApiPayload($payload);

        $this->assertSame(0, $activity?->movingTimeSeconds);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function stravaActivityPayload(array $overrides = []): array
    {
        return array_merge([
            'id' => 12_345_678,
            'sport_type' => 'Run',
            'type' => 'Run',
            'name' => 'Morning Run',
            'distance' => 8_042.3,
            'moving_time' => 2_400,
            'start_date_local' => '2026-06-14T07:15:00Z',
        ], $overrides);
    }
}
