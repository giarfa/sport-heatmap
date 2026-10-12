<?php

namespace Tests\Feature\Strava;

use App\Enums\ActivitySource;
use App\Enums\SyncRunStatus;
use App\Models\Activity;
use App\Models\SyncRun;
use App\Models\User;
use App\Services\Strava\StravaClient;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

class SyncStravaActivitiesCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Config::set('strava.retry_backoff_ms', [0, 0, 0]);
    }

    public function test_a_two_page_response_populates_the_window_and_records_a_succeeded_run(): void
    {
        Config::set('strava.activities_per_page', 2);

        $this->allowlistedAthlete();

        Http::fake([
            'www.strava.com/api/v3/athlete/activities*' => function (Request $request) {
                return match ((int) $request['page']) {
                    1 => Http::response([
                        $this->stravaActivityPayload(['id' => 1]),
                        $this->stravaActivityPayload(['id' => 2, 'sport_type' => 'Ride']),
                    ]),
                    default => Http::response([$this->stravaActivityPayload(['id' => 3])]),
                };
            },
        ]);

        $this->artisan('strava:sync')->assertExitCode(Command::SUCCESS);

        $this->assertSame(2, Activity::count());
        $this->assertFalse(Activity::where('strava_activity_id', 2)->exists());

        $syncRun = SyncRun::sole();
        $this->assertSame(SyncRunStatus::Succeeded, $syncRun->status);
        $this->assertSame(2, $syncRun->activities_upserted);
        $this->assertSame(0, $syncRun->activities_deleted);
        $this->assertNull($syncRun->error_message);
    }

    public function test_a_garmin_won_activity_survives_the_sync_and_is_excluded_from_the_run_counts(): void
    {
        $athlete = $this->allowlistedAthlete();

        $twin = Activity::factory()->for($athlete)->garminOverwritten()->onDate(now())->create([
            'strava_activity_id' => 42,
            'name' => 'Paese Corsa',
            'distance_meters' => 8_290,
        ]);
        $imported = Activity::factory()->for($athlete)->garminImported()->onDate(now()->subDays(3))->create();

        Http::fake([
            'www.strava.com/api/v3/athlete/activities*' => Http::response([
                $this->stravaActivityPayload(['id' => 42, 'name' => 'Morning Run', 'distance' => 8_150.0]),
                $this->stravaActivityPayload(['id' => 1]),
            ]),
        ]);

        $this->artisan('strava:sync')->assertExitCode(Command::SUCCESS);

        $this->assertSame(3, Activity::count());

        $twin->refresh();
        $this->assertSame(ActivitySource::GarminCsv, $twin->source);
        $this->assertSame('Paese Corsa', $twin->name);
        $this->assertSame(8_290, $twin->distance_meters);
        $this->assertTrue(Activity::whereKey($imported->id)->exists());

        $syncRun = SyncRun::sole();
        $this->assertSame(SyncRunStatus::Succeeded, $syncRun->status);
        $this->assertSame(1, $syncRun->activities_upserted);
        $this->assertSame(0, $syncRun->activities_deleted);
    }

    public function test_running_it_twice_against_the_same_response_reports_zero_zero_the_second_time(): void
    {
        $this->allowlistedAthlete();

        Http::fake([
            'www.strava.com/api/v3/athlete/activities*' => Http::response([$this->stravaActivityPayload(['id' => 1])]),
        ]);

        $this->artisan('strava:sync')->assertExitCode(Command::SUCCESS);
        $updatedAtAfterFirstRun = Activity::sole()->updated_at;

        $this->artisan('strava:sync')->assertExitCode(Command::SUCCESS);

        $this->assertSame(2, SyncRun::count());
        $this->assertSame(1, Activity::count());
        $this->assertEquals($updatedAtAfterFirstRun, Activity::sole()->updated_at);

        $secondRun = SyncRun::latest('id')->first();
        $this->assertSame(0, $secondRun->activities_upserted);
        $this->assertSame(0, $secondRun->activities_deleted);
    }

    public function test_an_activity_edited_upstream_propagates_and_a_deleted_one_disappears(): void
    {
        $this->allowlistedAthlete();

        /*
         * A single Http::fake() registration, driven by a variable the test
         * mutates between runs: Http::fake() called a second time for the same
         * URL pattern adds another stub rather than replacing the first, so two
         * separate fakes for the same URL would both match and the earlier one
         * would win.
         */
        $responsePayloads = [
            $this->stravaActivityPayload(['id' => 1, 'distance' => 5_000]),
            $this->stravaActivityPayload(['id' => 2]),
        ];

        Http::fake([
            'www.strava.com/api/v3/athlete/activities*' => function () use (&$responsePayloads) {
                return Http::response($responsePayloads);
            },
        ]);
        $this->artisan('strava:sync')->assertExitCode(Command::SUCCESS);

        $responsePayloads = [$this->stravaActivityPayload(['id' => 1, 'distance' => 12_000])];
        $this->artisan('strava:sync')->assertExitCode(Command::SUCCESS);

        $this->assertSame(1, Activity::count());
        $this->assertSame(12_000, Activity::sole()->distance_meters);

        $secondRun = SyncRun::latest('id')->first();
        $this->assertSame(1, $secondRun->activities_upserted);
        $this->assertSame(1, $secondRun->activities_deleted);
    }

    public function test_a_500_on_every_attempt_fails_the_run_and_leaves_activities_unchanged(): void
    {
        $user = $this->allowlistedAthlete();

        // Seeded directly, not via a prior sync run, so this test needs only one
        // Http::fake() registration for the 500 response.
        Activity::factory()->for($user)->create();

        $accessToken = (string) $user->strava_access_token;
        $activitiesBefore = Activity::orderBy('id')->get()->toArray();

        Http::fake([
            'www.strava.com/api/v3/athlete/activities*' => Http::response(
                ['message' => 'Internal Server Error'],
                500,
                ['X-RateLimit-Usage' => '900,1000'],
            ),
        ]);

        $this->artisan('strava:sync')->assertExitCode(Command::FAILURE);

        $failedRun = SyncRun::latest('id')->first();
        $this->assertSame(SyncRunStatus::Failed, $failedRun->status);
        $this->assertSame(0, $failedRun->activities_upserted);
        $this->assertSame(0, $failedRun->activities_deleted);
        $this->assertStringContainsString('500', (string) $failedRun->error_message);
        $this->assertStringContainsString('900,1000', (string) $failedRun->error_message);
        $this->assertStringNotContainsString($accessToken, (string) $failedRun->error_message);

        $this->assertSame($activitiesBefore, Activity::orderBy('id')->get()->toArray());
    }

    public function test_a_rejected_refresh_token_surfaces_as_a_failed_run(): void
    {
        $user = User::factory()->stravaTokenExpired()->create();
        $this->allowlistAthletes($user);

        Http::fake([
            'www.strava.com/oauth/token' => Http::response(['message' => 'Unauthorized'], 401),
        ]);

        $this->artisan('strava:sync')->assertExitCode(Command::FAILURE);

        $run = SyncRun::sole();
        $this->assertSame(SyncRunStatus::Failed, $run->status);
        $this->assertStringContainsString('rejected', (string) $run->error_message);
    }

    public function test_no_connected_athlete_exits_success_and_writes_no_sync_run(): void
    {
        User::factory()->create();

        $this->artisan('strava:sync')->assertExitCode(Command::SUCCESS);

        $this->assertSame(0, SyncRun::count());
    }

    public function test_an_athlete_no_longer_on_the_allowlist_is_skipped(): void
    {
        User::factory()->stravaConnected()->create();
        Config::set('strava.allowed_athlete_ids', []);

        $this->artisan('strava:sync')->assertExitCode(Command::SUCCESS);

        $this->assertSame(0, SyncRun::count());
    }

    public function test_a_crashed_run_leaves_the_running_row_visible(): void
    {
        $this->allowlistedAthlete();

        $this->mock(StravaClient::class, function ($mock): void {
            $mock->shouldReceive('activitiesInWindow')->andThrow(new RuntimeException('Unexpected failure.'));
        });

        /*
         * $this->artisan() invokes the command directly rather than through the
         * top-level console entry point, so an unhandled exception propagates to
         * the caller instead of being rendered as a non-zero exit code.
         */
        $this->expectException(RuntimeException::class);

        try {
            $this->artisan('strava:sync');
        } finally {
            $run = SyncRun::sole();
            $this->assertSame(SyncRunStatus::Running, $run->status);
            $this->assertNull($run->finished_at);
        }
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
            'name' => 'Morning Run',
            'distance' => 8_042.3,
            'moving_time' => 2_400,
            'start_date_local' => now()->format('Y-m-d\TH:i:s\Z'),
        ], $overrides);
    }

    public function test_a_csv_only_athlete_is_skipped_without_a_sync_run_or_a_log_line(): void
    {
        $athlete = User::factory()->csvOnly()->create();
        Activity::factory()->garminImported()->for($athlete)->create();
        $log = Log::spy();

        $this->artisan('strava:sync')->assertExitCode(Command::SUCCESS);

        $this->assertSame(0, SyncRun::count());
        $this->assertSame(1, $athlete->activities()->count());
        $log->shouldNotHaveReceived('warning');
        $log->shouldNotHaveReceived('error');
        Http::assertNothingSent();
    }

    public function test_only_the_connected_athlete_is_fetched_beside_a_csv_only_one(): void
    {
        $connected = $this->allowlistedAthlete();
        $csvOnly = User::factory()->csvOnly()->create();
        $csvRun = Activity::factory()->garminImported()->for($csvOnly)->create(['activity_date' => now()->subDay()->toDateString()]);
        Http::fake(['www.strava.com/api/v3/athlete/activities*' => Http::response([$this->stravaActivityPayload()])]);

        $this->artisan('strava:sync')->assertExitCode(Command::SUCCESS);

        Http::assertSentCount(1);
        $this->assertSame(1, $connected->activities()->count());
        $this->assertSame([$csvRun->id], $csvOnly->activities()->pluck('id')->all());
        $this->assertSame(SyncRunStatus::Succeeded, SyncRun::sole()->status);
    }
}
