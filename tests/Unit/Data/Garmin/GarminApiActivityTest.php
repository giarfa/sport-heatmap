<?php

namespace Tests\Unit\Data\Garmin;

use App\Data\Garmin\GarminApiActivity;
use App\Enums\SportType;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GarminApiActivityTest extends TestCase
{
    public function test_it_builds_a_run_from_a_payload_with_every_value_rounded(): void
    {
        $run = GarminApiActivity::fromApiPayload($this->payload(0));

        $this->assertInstanceOf(GarminApiActivity::class, $run);
        $this->assertSame(11111111101, $run->activityId);
        $this->assertSame(SportType::Run, $run->sportType);
        $this->assertSame('Paese Corsa', $run->name);
        $this->assertSame(8290, $run->distanceMeters);
        $this->assertSame(3264, $run->movingTimeSeconds);
        $this->assertSame(45, $run->totalAscentMeters);
        $this->assertSame(149, $run->averageHeartRate);
    }

    public function test_the_pace_is_the_rounded_seconds_per_kilometre_of_the_average_speed(): void
    {
        $run = GarminApiActivity::fromApiPayload($this->payload(0));

        $this->assertSame((int) round(1_000 / 3.0959), $run->averagePaceSecondsPerKm);
        $this->assertSame(323, $run->averagePaceSecondsPerKm);
    }

    public function test_the_start_is_the_local_wall_clock_and_the_activity_date_its_day(): void
    {
        $run = GarminApiActivity::fromApiPayload($this->payload(0));

        $this->assertSame('2026-11-10 07:12:34', $run->startDateLocal->format('Y-m-d H:i:s'));
        $this->assertSame('UTC', $run->startDateLocal->timezoneName);
        $this->assertSame('2026-11-10 00:00:00', $run->activityDate->format('Y-m-d H:i:s'));
    }

    public function test_the_trail_and_virtual_types_keep_their_sport_type(): void
    {
        $this->assertSame(SportType::TrailRun, GarminApiActivity::fromApiPayload($this->payload(1))->sportType);
        $this->assertSame(SportType::VirtualRun, GarminApiActivity::fromApiPayload($this->payload(2))->sportType);
    }

    public function test_a_null_speed_elevation_and_heart_rate_are_empty_metrics_never_zero(): void
    {
        $run = GarminApiActivity::fromApiPayload($this->payload(5));

        $this->assertNull($run->averagePaceSecondsPerKm);
        $this->assertNull($run->totalAscentMeters);
        $this->assertNull($run->averageHeartRate);
        $this->assertSame([], $run->metricAttributes());
    }

    public function test_a_zero_speed_and_a_zero_heart_rate_are_empty_metrics(): void
    {
        $run = GarminApiActivity::fromApiPayload($this->payload(6));

        $this->assertNull($run->averagePaceSecondsPerKm);
        $this->assertNull($run->averageHeartRate);
        $this->assertNull($run->totalAscentMeters);
    }

    public function test_a_real_zero_elevation_gain_is_kept(): void
    {
        $run = GarminApiActivity::fromApiPayload($this->payload(2));

        $this->assertSame(0, $run->totalAscentMeters);
        $this->assertSame(0, $run->metricAttributes()['total_ascent_meters']);
    }

    public function test_the_run_attributes_carry_every_api_owned_column_and_never_the_garmin_identity(): void
    {
        $attributes = GarminApiActivity::fromApiPayload($this->payload(0))->toRunAttributes();

        $this->assertEqualsCanonicalizing([
            'sport_type', 'name', 'distance_meters', 'moving_time_seconds', 'start_date_local', 'activity_date',
            'average_pace_seconds_per_km', 'total_ascent_meters', 'average_heart_rate',
        ], array_keys($attributes));
        $this->assertArrayNotHasKey('garmin_activity_id', $attributes);
        $this->assertArrayNotHasKey('source', $attributes);
    }

    public function test_a_type_that_is_not_a_run_builds_no_run(): void
    {
        $cycling = $this->payload(3);

        $this->assertSame('cycling', GarminApiActivity::typeKeyOf($cycling));
        $this->assertNull(GarminApiActivity::fromApiPayload($cycling));
    }

    public function test_the_type_key_is_empty_when_the_payload_has_none(): void
    {
        $this->assertSame('', GarminApiActivity::typeKeyOf([]));
        $this->assertSame('', GarminApiActivity::typeKeyOf(['activityType' => 'running']));
        $this->assertSame('', GarminApiActivity::typeKeyOf(['activityType' => ['typeKey' => 5]]));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function requiredFields(): array
    {
        return [
            'activityId' => ['activityId'],
            'startTimeLocal' => ['startTimeLocal'],
            'distance' => ['distance'],
            'movingDuration' => ['movingDuration'],
        ];
    }

    #[DataProvider('requiredFields')]
    public function test_a_missing_required_field_builds_no_run(string $field): void
    {
        $payload = $this->payload(0);
        unset($payload[$field]);

        $this->assertNull(GarminApiActivity::fromApiPayload($payload));
    }

    #[DataProvider('requiredFields')]
    public function test_a_null_required_field_builds_no_run(string $field): void
    {
        $this->assertNull(GarminApiActivity::fromApiPayload([...$this->payload(0), $field => null]));
    }

    public function test_an_unreadable_start_a_negative_distance_or_an_absurd_one_builds_no_run(): void
    {
        $payload = $this->payload(0);

        $this->assertNull(GarminApiActivity::fromApiPayload([...$payload, 'startTimeLocal' => '10/11/2026 07:12']));
        $this->assertNull(GarminApiActivity::fromApiPayload([...$payload, 'startTimeLocal' => '2026-13-45 07:12:34']));
        $this->assertNull(GarminApiActivity::fromApiPayload([...$payload, 'distance' => -5.0]));
        $this->assertNull(GarminApiActivity::fromApiPayload([...$payload, 'distance' => 1.0e15]));
        $this->assertNull(GarminApiActivity::fromApiPayload([...$payload, 'movingDuration' => '3263']));
        $this->assertNull(GarminApiActivity::fromApiPayload([...$payload, 'activityId' => '11111111101']));
        $this->assertNull(GarminApiActivity::fromApiPayload([...$payload, 'activityId' => 0]));
    }

    public function test_a_missing_name_gets_a_readable_fallback_and_a_name_is_trimmed(): void
    {
        $payload = $this->payload(0);
        unset($payload['activityName']);

        $this->assertSame('Garmin activity', GarminApiActivity::fromApiPayload($payload)->name);
        $this->assertSame('Evening jog', GarminApiActivity::fromApiPayload([...$this->payload(0), 'activityName' => '  Evening jog '])->name);
    }

    /**
     * One activity of the fixture page.
     *
     * @return array<string, mixed>
     */
    private function payload(int $index): array
    {
        $page = json_decode((string) file_get_contents(base_path('tests/Fixtures/Garmin/api/activities-page-mixed.json')), true);

        return $page[$index];
    }
}
