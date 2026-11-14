<?php

namespace Tests\Feature\Garmin;

use App\Actions\Garmin\SyncGarminActivities;
use App\Data\Garmin\GarminSyncResult;
use App\Enums\ActivitySource;
use App\Enums\GarminSyncTrigger;
use App\Models\Activity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Database\ExtendedRunMetricsMigrationTest;
use Tests\TestCase;

/**
 * FR-022 on the sync side: the extended metrics of the faked Garmin response reach the
 * database on create, link and update, a second sync changes nothing, the CSV-only score of
 * a linked run survives, and no coordinate is stored. Nothing here reaches Garmin.
 */
class GarminSyncExtendedMetricsTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'GCDC_extended-metrics-key';

    private const TOKEN_URL = 'https://personal.connectapi.garmin.com/personalapitoken-service/personal-api/personal-api-key/token';

    private const SEARCH_URL = 'https://personal.connectapi.garmin.com/activitylist-service/activities/search/activities*';

    private User $owner;

    /**
     * What the activity search answers on its first page.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $page = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-14 09:00:00');

        Http::preventStrayRequests();

        Config::set('services.garmin.api_key', self::KEY);
        Config::set('services.garmin.token_cache_store', 'array');

        $this->owner = User::factory()->ownerWithPassword()->create();
        $this->page = $this->fixturePage();

        Http::fake([
            self::TOKEN_URL => Http::response($this->jwt(now()->addDay()->getTimestamp())),
            self::SEARCH_URL => function (Request $request) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

                return Http::response((int) $query['start'] === 0 ? $this->page : []);
            },
        ]);
    }

    public function test_a_new_synced_run_stores_every_extended_metric_of_the_response(): void
    {
        $result = $this->sync();

        $this->assertSame(5, $result->counts->new);

        $run = Activity::where('garmin_activity_id', 11111111101)->sole();

        $this->assertSame(612, $run->calories);
        $this->assertSame(171, $run->max_heart_rate);
        $this->assertSame(3.8, $run->aerobic_training_effect);
        $this->assertSame(168, $run->average_running_cadence);
        $this->assertSame(181, $run->max_running_cadence);
        $this->assertSame(252, $run->best_pace_seconds_per_km);
        $this->assertSame(41, $run->total_descent_meters);
        $this->assertSame(104.2, $run->average_stride_length_cm);
        $this->assertSame(8.7, $run->average_vertical_ratio_percent);
        $this->assertSame(9.6, $run->average_vertical_oscillation_cm);
        $this->assertSame(287, $run->average_ground_contact_time_ms);
        $this->assertSame(49.8, $run->average_ground_contact_balance_left_percent);
        $this->assertSame(7_312, $run->steps);
        $this->assertSame(9, $run->lap_count);
        $this->assertSame(3_301, $run->elapsed_time_seconds);
        $this->assertSame(61, $run->min_elevation_meters);
        $this->assertSame(99, $run->max_elevation_meters);
        $this->assertSame([120, 1_451, 1_200, 480, 0], [
            $run->hr_zone_1_seconds, $run->hr_zone_2_seconds, $run->hr_zone_3_seconds, $run->hr_zone_4_seconds, $run->hr_zone_5_seconds,
        ]);
        $this->assertSame([292, 478, 1_530], [$run->fastest_1k_seconds, $run->fastest_mile_seconds, $run->fastest_5k_seconds]);
        $this->assertSame('Verona', $run->location_name);
        $this->assertNull($run->training_stress_score, 'The API has no Training Stress Score.');
    }

    public function test_real_zeros_are_kept_and_an_empty_place_name_is_not_recorded(): void
    {
        $this->sync();

        $run = Activity::where('garmin_activity_id', 11111111103)->sole();

        $this->assertSame(0.0, $run->aerobic_training_effect);
        $this->assertSame(0, $run->total_descent_meters);
        $this->assertSame(0, $run->min_elevation_meters);
        $this->assertSame(0, $run->max_elevation_meters);
        $this->assertSame(0, $run->hr_zone_1_seconds);
        $this->assertNull($run->location_name);
        $this->assertSame(-3, Activity::where('garmin_activity_id', 11111111102)->sole()->min_elevation_meters);
    }

    public function test_null_and_unreadable_extended_fields_leave_the_metrics_empty_and_still_store_the_run(): void
    {
        $this->sync();

        foreach ([11111111106 => 6_400, 11111111107 => 4_000] as $garminId => $distance) {
            $run = Activity::where('garmin_activity_id', $garminId)->sole();

            $this->assertSame($distance, $run->distance_meters, 'The run is stored.');

            foreach ($this->extendedColumns() as $column) {
                $this->assertNull($run->{$column}, "{$column} must stay empty for run {$garminId}, never 0.");
            }
        }
    }

    public function test_a_second_sync_over_the_same_page_changes_nothing(): void
    {
        $this->sync();
        $snapshot = DB::table('activities')->orderBy('id')->get();

        $this->travelTo('2026-11-14 09:30:00');
        $second = $this->sync();

        $this->assertSame(0, $second->counts->new);
        $this->assertSame(0, $second->counts->updated);
        $this->assertSame(0, $second->counts->linked);
        $this->assertEquals($snapshot, DB::table('activities')->orderBy('id')->get(), 'Values and updated_at are untouched.');
    }

    public function test_a_changed_extended_value_updates_the_run_once_and_a_repeat_sync_is_a_no_op(): void
    {
        $this->sync();

        $this->page[0]['calories'] = 700.0;
        $this->page[0]['hrTimeInZone_3'] = 1_300.0;
        $this->travelTo('2026-11-14 09:30:00');

        $first = $this->sync();

        $this->assertSame(1, $first->counts->updated);
        $run = Activity::where('garmin_activity_id', 11111111101)->sole();
        $this->assertSame(700, $run->calories);
        $this->assertSame(1_300, $run->hr_zone_3_seconds);

        $stamp = $run->updated_at;
        $this->travelTo('2026-11-14 10:00:00');

        $second = $this->sync();

        $this->assertSame(0, $second->counts->updated);
        $this->assertEquals($stamp, $run->fresh()->updated_at);
    }

    public function test_an_api_field_that_is_null_never_blanks_a_stored_value(): void
    {
        $this->sync();
        Activity::where('garmin_activity_id', 11111111101)->update(['calories' => 500, 'steps' => 9_999]);

        $this->page[0]['calories'] = null;
        unset($this->page[0]['steps']);
        $this->sync();

        $run = Activity::where('garmin_activity_id', 11111111101)->sole();
        $this->assertSame(500, $run->calories);
        $this->assertSame(9_999, $run->steps);
        $this->assertSame(171, $run->max_heart_rate, 'The rest of the response still applies.');
    }

    public function test_a_linked_csv_run_gains_the_api_values_and_keeps_its_training_stress_score(): void
    {
        $csvRun = Activity::factory()->for($this->owner)->garminImported()->withExtendedMetrics()->create([
            'start_date_local' => '2026-11-10 07:12:00',
            'activity_date' => '2026-11-10',
            'distance_meters' => 8_290,
            'moving_time_seconds' => 3_264,
            'training_stress_score' => 61.2,
            'calories' => 480,
        ]);

        $result = $this->sync();

        $this->assertSame(1, $result->counts->linked);

        $linked = $csvRun->fresh();
        $this->assertSame(11111111101, $linked->garmin_activity_id);
        $this->assertSame(ActivitySource::GarminApi, $linked->source);
        $this->assertSame(612, $linked->calories, 'The API value replaces the CSV one.');
        $this->assertSame('Verona', $linked->location_name);
        $this->assertSame(120, $linked->hr_zone_1_seconds);
        $this->assertSame(61.2, $linked->training_stress_score, 'The CSV-only score is kept.');
    }

    public function test_no_coordinate_is_stored_from_the_response(): void
    {
        $this->assertSame(45.4384, $this->page[0]['startLatitude'], 'The fixture does carry coordinates.');

        $this->sync();

        $stored = (string) json_encode(DB::table('activities')->get());

        foreach (['45.4384', '10.9916', '45.4391', '10.9923'] as $coordinate) {
            $this->assertStringNotContainsString($coordinate, $stored);
        }

        foreach (DB::getSchemaBuilder()->getColumnListing('activities') as $column) {
            $this->assertDoesNotMatchRegularExpression('/(^|_)(lat|latitude|lon|lng|longitude)(_|$)/i', $column);
        }
    }

    private function sync(): GarminSyncResult
    {
        return $this->app->make(SyncGarminActivities::class)($this->owner, GarminSyncTrigger::Manual);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fixturePage(): array
    {
        return json_decode((string) file_get_contents(base_path('tests/Fixtures/Garmin/api/activities-page-mixed.json')), true);
    }

    /**
     * @return array<int, string>
     */
    private function extendedColumns(): array
    {
        return array_values(array_diff(
            ExtendedRunMetricsMigrationTest::EXTENDED_COLUMNS,
            ['training_stress_score'],
        ));
    }

    private function jwt(int $exp): string
    {
        $encode = fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');

        return $encode('{"alg":"none"}').'.'.$encode((string) json_encode(['sub' => 'owner', 'exp' => $exp])).'.'.$encode('signature');
    }
}
