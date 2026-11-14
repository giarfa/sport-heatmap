<?php

namespace Tests\Feature\Garmin;

use App\Actions\Garmin\SyncGarminActivities;
use App\Data\Garmin\GarminSyncResult;
use App\Enums\ActivitySource;
use App\Enums\GarminSyncFailure;
use App\Enums\GarminSyncOutcome;
use App\Enums\GarminSyncTrigger;
use App\Enums\SportType;
use App\Exceptions\Garmin\GarminSyncAlreadyRunningException;
use App\Models\Activity;
use App\Models\GarminSync;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The sync action against faked Garmin responses: paging, the window, type mapping,
 * stored values, linking, idempotence, partial failure, the grid cache and secret
 * hygiene. Nothing here reaches Garmin.
 */
class GarminSyncTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'GCDC_sync-test-key-never-leak-me';

    private const TOKEN_URL = 'https://personal.connectapi.garmin.com/personalapitoken-service/personal-api/personal-api-key/token';

    private const SEARCH_URL = 'https://personal.connectapi.garmin.com/activitylist-service/activities/search/activities*';

    private User $owner;

    private string $token;

    /**
     * What the activity search answers, by its `start` offset: a list of activities, or
     * an HTTP status to fail with. An offset with no entry answers an empty page.
     *
     * @var array<int, array<int, array<string, mixed>>|int>
     */
    private array $pages = [];

    /**
     * The status the token call answers with; 200 answers the raw JWT.
     */
    private int $tokenStatus = 200;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-14 09:00:00'); // a Saturday

        Http::preventStrayRequests();

        Config::set('services.garmin.api_key', self::KEY);
        Config::set('services.garmin.token_cache_store', 'array');

        $this->owner = User::factory()->ownerWithPassword()->create();
        $this->token = $this->jwt(now()->addDay()->getTimestamp());

        Http::fake([
            self::TOKEN_URL => fn () => $this->tokenStatus === 200
                ? Http::response($this->token)
                : Http::response('denied', $this->tokenStatus),
            self::SEARCH_URL => function (Request $request) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                $page = $this->pages[(int) $query['start']] ?? [];

                return is_int($page) ? Http::response('', $page) : Http::response($page);
            },
        ]);
    }

    public function test_it_pages_with_start_and_limit_until_a_page_comes_back_short(): void
    {
        $this->pages = [
            0 => $this->manyRuns(100, 0),
            100 => $this->manyRuns(100, 100),
            200 => $this->manyRuns(37, 200),
        ];

        $result = $this->sync();

        $this->assertSame([0, 100, 200], $this->searchedOffsets());
        $this->assertSame(237, $result->counts->new);
        $this->assertSame(237, Activity::count());
    }

    public function test_a_full_last_page_makes_one_more_call_and_an_empty_page_stops_it(): void
    {
        $this->pages = [0 => $this->manyRuns(100, 0)];

        $this->sync();

        $this->assertSame([0, 100], $this->searchedOffsets());
    }

    public function test_only_running_types_are_stored_and_every_other_activity_is_counted(): void
    {
        $this->pages = [0 => [
            $this->apiRun(1, '2026-11-10 07:00:00'),
            $this->apiRun(2, '2026-11-09 07:00:00', ['activityType' => ['typeKey' => 'trail_running']]),
            $this->apiRun(3, '2026-11-08 07:00:00', ['activityType' => ['typeKey' => 'virtual_run']]),
            $this->apiRun(4, '2026-11-07 07:00:00', ['activityType' => ['typeKey' => 'cycling']]),
            $this->apiRun(5, '2026-11-06 07:00:00', ['activityType' => ['typeKey' => 'strength_training'], 'distance' => null, 'movingDuration' => null]),
            $this->apiRun(6, '2026-11-15 07:00:00'),
            $this->apiRun(7, '2026-11-14 18:00:00'),
            [...$this->apiRun(8, '2026-11-05 07:00:00'), 'startTimeLocal' => null],
        ]];

        $result = $this->sync();

        $this->assertSame(4, $result->counts->new);
        $this->assertSame(2, $result->counts->ignored, 'Cycling and the strength session, which has no distance, are ignored, not invalid.');
        $this->assertSame(1, $result->counts->futureDated, 'Tomorrow is future-dated; later today is not.');
        $this->assertSame(1, $result->counts->invalid);
        $this->assertSame(
            [1 => SportType::Run, 2 => SportType::TrailRun, 3 => SportType::VirtualRun, 7 => SportType::Run],
            Activity::query()->orderBy('garmin_activity_id')->get()->mapWithKeys(fn (Activity $run): array => [$run->garmin_activity_id => $run->sport_type])->all(),
        );
    }

    public function test_today_is_the_owners_day_so_a_run_after_local_midnight_is_not_future_dated(): void
    {
        Config::set('heatmap.display_timezone', 'Europe/Rome');
        $this->travelTo('2026-11-14 23:30:00'); // 00:30 on the 15th in Rome
        $this->pages = [0 => [
            $this->apiRun(1, '2026-11-15 00:10:00'),
            $this->apiRun(2, '2026-11-16 07:00:00'),
        ]];

        $result = $this->sync();

        $this->assertSame(1, $result->counts->new);
        $this->assertSame(1, $result->counts->futureDated);
        $this->assertSame(1, Activity::sole()->garmin_activity_id);
    }

    public function test_an_attempt_left_running_beyond_the_lock_lifetime_is_closed_as_failed_when_the_next_starts(): void
    {
        $stale = GarminSync::factory()->running()->create(['started_at' => now()->subMinutes(31)]);
        $recent = GarminSync::factory()->running()->create(['started_at' => now()->subMinutes(5)]);

        $this->sync();

        $this->assertSame(GarminSyncOutcome::Failed, $stale->refresh()->outcome);
        $this->assertSame(GarminSyncFailure::Unexpected, $stale->failure_cause);
        $this->assertNotNull($stale->finished_at);
        $this->assertSame(GarminSyncOutcome::Running, $recent->refresh()->outcome, 'A recent running row may still be alive.');
    }

    public function test_a_stored_run_carries_every_value_the_api_gave_rounded(): void
    {
        $this->pages = [0 => [$this->apiRun(11111111101, '2026-11-10 07:12:34')]];

        $this->sync();

        $run = Activity::sole();

        $this->assertSame(ActivitySource::GarminApi, $run->source);
        $this->assertSame(11111111101, $run->garmin_activity_id);
        $this->assertSame($this->owner->id, $run->user_id);
        $this->assertSame('Paese Corsa', $run->name);
        $this->assertSame(8290, $run->distance_meters);
        $this->assertSame(3264, $run->moving_time_seconds);
        $this->assertSame('2026-11-10 07:12:34', $run->start_date_local->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-10', $run->activity_date->format('Y-m-d'));
        $this->assertSame(323, $run->average_pace_seconds_per_km);
        $this->assertSame(45, $run->total_ascent_meters);
        $this->assertSame(149, $run->average_heart_rate);
    }

    public function test_a_missing_metric_is_stored_empty_never_as_zero(): void
    {
        $this->pages = [0 => [
            $this->apiRun(1, '2026-11-10 07:00:00', ['averageSpeed' => null, 'elevationGain' => null, 'averageHR' => null]),
            $this->apiRun(2, '2026-11-09 07:00:00', ['averageSpeed' => 0.0, 'averageHR' => 0.0]),
        ]];

        $this->sync();

        foreach (Activity::all() as $run) {
            $this->assertNull($run->average_pace_seconds_per_km);
            $this->assertNull($run->average_heart_rate);
        }

        $this->assertNull(Activity::where('garmin_activity_id', 1)->sole()->total_ascent_meters);
    }

    public function test_the_first_sync_reads_the_whole_history_and_later_ones_start_seven_days_before_the_newest_run(): void
    {
        $this->pages = [0 => [$this->apiRun(1, '2026-11-10 07:00:00'), $this->apiRun(2, '2026-11-02 07:00:00')]];

        $first = $this->sync();
        $this->assertTrue($first->fullHistory);
        $this->assertArrayNotHasKey('startDate', $this->searchQueries()[0]);

        $second = $this->sync();
        $this->assertFalse($second->fullHistory);
        $this->assertSame('2026-11-03', $this->searchQueries()[1]['startDate']);
        $this->assertArrayNotHasKey('endDate', $this->searchQueries()[1]);
    }

    public function test_a_first_sync_that_failed_half_way_is_followed_by_another_full_history_read(): void
    {
        $this->pages = [0 => $this->manyRuns(100, 0), 100 => 503];

        $failed = $this->sync();
        $this->assertSame(GarminSyncOutcome::Failed, $failed->outcome);
        $this->assertSame(100, Activity::whereNotNull('garmin_activity_id')->count());

        $this->pages = [0 => $this->manyRuns(100, 0), 100 => $this->manyRuns(20, 100)];

        $retry = $this->sync();
        $this->assertTrue($retry->fullHistory, 'Runs with a Garmin id exist, but no full-history sync has succeeded yet.');
        $this->assertSame(20, $retry->counts->new);
        $this->assertSame(0, $retry->counts->updated);
        $this->assertSame(120, Activity::count());

        $this->assertFalse($this->sync()->fullHistory, 'Once a full read succeeded, later syncs use the window.');
    }

    public function test_a_run_renamed_on_garmin_within_the_span_is_updated(): void
    {
        $this->pages = [0 => [$this->apiRun(1, '2026-11-10 07:00:00')]];
        $this->sync();

        $this->pages = [0 => [$this->apiRun(1, '2026-11-10 07:00:00', ['activityName' => 'Lungo di domenica', 'distance' => 9100.0])]];
        $result = $this->sync();

        $this->assertSame(1, $result->counts->updated);
        $this->assertSame(0, $result->counts->new);
        $this->assertSame('Lungo di domenica', Activity::sole()->name);
        $this->assertSame(9100, Activity::sole()->distance_meters);
    }

    public function test_a_run_moved_to_another_day_on_garmin_is_updated_not_created_again(): void
    {
        $this->pages = [0 => [$this->apiRun(1, '2026-11-10 07:00:00')]];
        $this->sync();

        $this->pages = [0 => [$this->apiRun(1, '2026-11-12 18:30:00')]];
        $result = $this->sync();

        $this->assertSame(1, $result->counts->updated);
        $this->assertSame(1, Activity::count());
        $this->assertSame('2026-11-12', Activity::sole()->activity_date->format('Y-m-d'));
    }

    public function test_a_csv_run_within_two_minutes_on_the_same_date_is_the_same_run_and_gains_the_id(): void
    {
        $csv = $this->csvRun('2026-11-10 07:01:00', ['name' => 'Verona Corsa', 'distance_meters' => 8_100]);
        $this->pages = [0 => [$this->apiRun(11111111101, '2026-11-10 07:02:30')]];

        $result = $this->sync();

        $this->assertSame(1, $result->counts->linked);
        $this->assertSame(0, $result->counts->new);
        $this->assertSame(1, Activity::count());

        $linked = $csv->refresh();
        $this->assertSame(11111111101, $linked->garmin_activity_id);
        $this->assertSame(ActivitySource::GarminApi, $linked->source);
        $this->assertSame('Paese Corsa', $linked->name);
        $this->assertSame(8290, $linked->distance_meters);
        $this->assertSame('2026-11-10 07:02:30', $linked->start_date_local->format('Y-m-d H:i:s'));
    }

    public function test_exactly_two_minutes_apart_is_still_the_same_run_and_four_minutes_is_a_new_one(): void
    {
        $this->csvRun('2026-11-10 07:01:00');
        $this->pages = [0 => [
            $this->apiRun(1, '2026-11-10 07:03:00'),
            $this->apiRun(2, '2026-11-10 07:08:00'),
        ]];

        $result = $this->sync();

        $this->assertSame(1, $result->counts->linked);
        $this->assertSame(1, $result->counts->new);
        $this->assertSame(2, Activity::count());
    }

    public function test_a_csv_run_on_another_date_is_never_linked(): void
    {
        $this->csvRun('2026-11-09 07:01:00');
        $this->pages = [0 => [$this->apiRun(1, '2026-11-10 07:01:30')]];

        $result = $this->sync();

        $this->assertSame(0, $result->counts->linked);
        $this->assertSame(2, Activity::count());
    }

    public function test_two_activities_in_the_same_minute_cannot_claim_one_csv_run(): void
    {
        $this->csvRun('2026-11-10 07:00:00');
        $this->pages = [0 => [$this->apiRun(1, '2026-11-10 07:00:10'), $this->apiRun(2, '2026-11-10 07:00:20')]];

        $result = $this->sync();

        $this->assertSame(1, $result->counts->linked);
        $this->assertSame(1, $result->counts->new);
        $this->assertSame(2, Activity::count());
    }

    public function test_a_link_keeps_a_metric_the_api_leaves_empty_and_a_metric_the_csv_backfilled(): void
    {
        $csv = $this->csvRun('2026-11-10 07:00:00', ['average_heart_rate' => 151, 'total_ascent_meters' => 60]);
        $this->pages = [0 => [$this->apiRun(1, '2026-11-10 07:00:30', ['averageHR' => null, 'elevationGain' => null])]];

        $this->sync();

        $this->assertSame(151, $csv->refresh()->average_heart_rate);
        $this->assertSame(60, $csv->total_ascent_meters);
        $this->assertSame(323, $csv->average_pace_seconds_per_km, 'A value the API does give replaces the stored one.');
    }

    public function test_only_the_owners_own_csv_runs_are_candidates_to_link(): void
    {
        $other = User::factory()->create();
        Activity::factory()->for($other)->create(['start_date_local' => '2026-11-10 07:00:00', 'activity_date' => '2026-11-10']);
        $this->pages = [0 => [$this->apiRun(1, '2026-11-10 07:00:30')]];

        $result = $this->sync();

        $this->assertSame(1, $result->counts->new);
        $this->assertNull(Activity::where('user_id', $other->id)->sole()->garmin_activity_id);
    }

    public function test_running_the_sync_twice_changes_nothing(): void
    {
        $this->pages = [0 => [
            $this->apiRun(1, '2026-11-10 07:00:00'),
            $this->apiRun(2, '2026-11-09 07:00:00', ['averageHR' => null]),
        ]];
        $this->csvRun('2026-11-08 07:00:00');
        $this->pages[0][] = $this->apiRun(3, '2026-11-08 07:00:40');
        $this->sync();

        $before = Activity::query()->orderBy('id')->get()->map->getAttributes()->all();

        $this->travel(2)->hours();
        $second = $this->sync();

        $this->assertSame(0, $second->counts->new);
        $this->assertSame(0, $second->counts->updated);
        $this->assertSame(0, $second->counts->linked);
        $this->assertSame($before, Activity::query()->orderBy('id')->get()->map->getAttributes()->all(), 'No column, `updated_at` included, moves.');
    }

    public function test_a_run_deleted_on_garmin_stays_stored(): void
    {
        $this->pages = [0 => [$this->apiRun(1, '2026-11-10 07:00:00'), $this->apiRun(2, '2026-11-09 07:00:00')]];
        $this->sync();

        $this->pages = [0 => [$this->apiRun(1, '2026-11-10 07:00:00')]];
        $this->sync();

        $this->assertSame(2, Activity::count());
    }

    public function test_a_failure_on_a_later_page_keeps_the_earlier_pages_and_records_the_cause_and_the_counts(): void
    {
        $this->pages = [0 => $this->manyRuns(100, 0), 100 => 503];

        $result = $this->sync(GarminSyncTrigger::Scheduled);

        $this->assertSame(GarminSyncOutcome::Failed, $result->outcome);
        $this->assertSame(GarminSyncFailure::Unreachable, $result->failure);
        $this->assertSame(100, $result->counts->new);
        $this->assertSame(100, Activity::count());

        $attempt = GarminSync::sole();
        $this->assertSame(GarminSyncOutcome::Failed, $attempt->outcome);
        $this->assertSame(GarminSyncFailure::Unreachable, $attempt->failure_cause);
        $this->assertSame(100, $attempt->new_count);
        $this->assertNotNull($attempt->finished_at);
    }

    public function test_a_rejected_key_is_recorded_as_such_and_writes_nothing(): void
    {
        $this->tokenStatus = 401;

        $result = $this->sync();

        $this->assertSame(GarminSyncFailure::KeyRejected, $result->failure);
        $this->assertSame(0, Activity::count());
        $this->assertSame(GarminSyncFailure::KeyRejected, GarminSync::sole()->failure_cause);
    }

    public function test_every_attempt_is_recorded_with_its_trigger_window_and_counts(): void
    {
        $this->pages = [0 => [$this->apiRun(1, '2026-11-10 07:00:00'), $this->apiRun(2, '2026-11-07 07:00:00', ['activityType' => ['typeKey' => 'cycling']])]];

        $this->sync(GarminSyncTrigger::Scheduled);
        $this->travel(1)->hours();
        $this->sync(GarminSyncTrigger::Manual);

        [$first, $second] = GarminSync::query()->orderBy('id')->get()->all();

        $this->assertSame(GarminSyncTrigger::Scheduled, $first->trigger);
        $this->assertSame(GarminSyncOutcome::Succeeded, $first->outcome);
        $this->assertTrue($first->full_history);
        $this->assertSame([1, 0, 0, 1, 0, 0], [$first->new_count, $first->updated_count, $first->linked_count, $first->ignored_count, $first->future_dated_count, $first->invalid_count]);
        $this->assertNull($first->failure_cause);
        $this->assertTrue($first->started_at->lessThanOrEqualTo($first->finished_at));

        $this->assertSame(GarminSyncTrigger::Manual, $second->trigger);
        $this->assertFalse($second->full_history);
        $this->assertSame([0, 0, 0, 1, 0, 0], [$second->new_count, $second->updated_count, $second->linked_count, $second->ignored_count, $second->future_dated_count, $second->invalid_count]);
    }

    public function test_a_sync_that_wrote_a_run_retires_the_cached_grid_and_one_that_wrote_nothing_does_not(): void
    {
        $generationKey = "heatmap:grid:generation:{$this->owner->id}";
        $this->csvRun('2026-11-12 07:00:00');

        $before = $this->dashboard();
        $this->assertStringNotContainsString('data-day="2026-11-13"', $before);
        $trendBefore = $this->trendCard($before);

        $this->pages = [0 => [$this->apiRun(1, '2026-11-13 07:00:00', ['distance' => 21_000.0])]];
        $this->sync();

        $after = $this->dashboard();
        $this->assertStringContainsString('data-day="2026-11-13"', $after, 'The cell shows the synced run.');
        $this->assertNotSame($trendBefore, $this->trendCard($after), 'The trend shows it.');
        $this->assertSame(1, Cache::get($generationKey));

        $this->sync();
        $this->assertSame(1, Cache::get($generationKey), 'A sync that wrote nothing leaves the cached grid alone.');
    }

    public function test_a_failed_sync_that_wrote_runs_still_retires_the_cached_grid(): void
    {
        $this->pages = [0 => $this->manyRuns(100, 0), 100 => 503];

        $this->sync();

        $this->assertSame(1, Cache::get("heatmap:grid:generation:{$this->owner->id}"));
    }

    public function test_a_second_sync_while_one_runs_is_refused_and_starts_nothing(): void
    {
        $lock = Cache::lock('garmin:sync', 60);
        $this->assertTrue($lock->get());

        try {
            $this->sync();
            $this->fail('A second sync must be refused.');
        } catch (GarminSyncAlreadyRunningException $exception) {
            $this->assertSame('A Garmin sync is already running — nothing started.', $exception->getMessage());
        } finally {
            $lock->release();
        }

        Http::assertNothingSent();
        $this->assertSame(0, GarminSync::count());
    }

    public function test_the_lock_is_released_after_a_sync_even_a_failed_one(): void
    {
        $this->pages = [0 => 500];

        $this->sync();
        $this->sync();

        $this->assertSame(2, GarminSync::count());
    }

    public function test_neither_the_key_nor_the_token_reaches_the_database_or_the_log(): void
    {
        /*
         * A database cache as the default store, the way production runs, so the cache and
         * lock tables are real tables the scan can read; the token is kept in the array
         * store, which is what the configured token store stands in for.
         */
        Config::set('cache.default', 'database');
        $log = Log::spy();

        $this->pages = [0 => $this->manyRuns(100, 0), 100 => 503];
        $this->assertSame(GarminSyncFailure::Unreachable, $this->sync()->failure);
        $this->pages = [0 => [$this->apiRun(1, '2026-11-10 07:00:00')]];
        $this->assertTrue($this->sync()->succeeded());
        $this->tokenStatus = 403;
        Cache::store('array')->flush();
        $this->assertSame(GarminSyncFailure::KeyRejected, $this->sync()->failure);

        foreach (['cache', 'cache_locks', 'garmin_syncs', 'activities', 'sessions', 'jobs', 'failed_jobs'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $contents = json_encode(DB::table($table)->get()->all());

            $this->assertStringNotContainsString(self::KEY, (string) $contents, "{$table} holds the key.");
            $this->assertStringNotContainsString($this->token, (string) $contents, "{$table} holds the token.");
        }

        $log->shouldHaveReceived('warning')->with('Garmin sync failed.', ['cause' => 'unreachable', 'status' => 503])->once();
        $log->shouldHaveReceived('warning')->with('Garmin sync failed.', ['cause' => 'key_rejected', 'status' => 403])->once();
        $log->shouldNotHaveReceived('error');
    }

    private function sync(GarminSyncTrigger $trigger = GarminSyncTrigger::Manual): GarminSyncResult
    {
        return $this->app->make(SyncGarminActivities::class)($this->owner, $trigger);
    }

    /**
     * A running activity as the API answers it, with the values of the spec's example.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function apiRun(int $id, string $start, array $overrides = []): array
    {
        return [
            'activityId' => $id,
            'activityName' => 'Paese Corsa',
            'activityType' => ['typeKey' => 'running'],
            'startTimeLocal' => $start,
            'distance' => 8290.4,
            'movingDuration' => 3263.7,
            'averageSpeed' => 3.0959,
            'elevationGain' => 45.4,
            'averageHR' => 148.6,
            ...$overrides,
        ];
    }

    /**
     * A page of distinct runs, one per day, going back from the day before today.
     *
     * @return array<int, array<string, mixed>>
     */
    private function manyRuns(int $count, int $offset): array
    {
        return array_map(
            fn (int $index): array => $this->apiRun(
                20_000_000_000 + $offset + $index,
                CarbonImmutable::parse('2026-11-13')->subDays($offset + $index)->format('Y-m-d').' 07:00:00',
            ),
            range(0, $count - 1),
        );
    }

    /**
     * A run the CSV import stored: no Garmin id.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function csvRun(string $start, array $attributes = []): Activity
    {
        return Activity::factory()->for($this->owner)->garminImported()->create([
            'start_date_local' => $start,
            'activity_date' => substr($start, 0, 10),
            ...$attributes,
        ]);
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function searchQueries(): array
    {
        return Http::recorded()
            ->map(fn (array $pair): Request => $pair[0])
            ->filter(fn (Request $request): bool => str_contains($request->url(), 'search/activities'))
            ->map(function (Request $request): array {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

                return $query;
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int, int>
     */
    private function searchedOffsets(): array
    {
        return array_map(fn (array $query): int => (int) $query['start'], $this->searchQueries());
    }

    private function dashboard(): string
    {
        return (string) $this->actingAs($this->owner)->get('/')->assertOk()->getContent();
    }

    private function trendCard(string $html): string
    {
        return preg_match('/<section class="card p-4 trend".*?<\/section>/s', $html, $match) === 1 ? $match[0] : '';
    }

    private function jwt(int $exp): string
    {
        $encode = fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');

        return $encode('{"alg":"none"}').'.'.$encode((string) json_encode(['sub' => 'owner', 'exp' => $exp])).'.'.$encode('signature');
    }
}
