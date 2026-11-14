<?php

namespace Tests\Feature\Garmin;

use App\Enums\GarminSyncOutcome;
use App\Enums\GarminSyncTrigger;
use App\Models\Activity;
use App\Models\GarminSync;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * `php artisan garmin:sync` — what the scheduler runs and what the owner can run by hand.
 */
class GarminSyncCommandTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN_URL = 'https://personal.connectapi.garmin.com/personalapitoken-service/personal-api/personal-api-key/token';

    private const SEARCH_URL = 'https://personal.connectapi.garmin.com/activitylist-service/activities/search/activities*';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-14 09:00:00');

        Http::preventStrayRequests();

        Config::set('services.garmin.token_cache_store', 'array');
    }

    public function test_without_a_key_it_says_so_calls_nothing_records_nothing_and_succeeds(): void
    {
        Config::set('services.garmin.api_key', '');
        User::factory()->ownerWithPassword()->create();
        Http::fake();

        $this->artisan('garmin:sync --scheduled')
            ->expectsOutputToContain('GARMIN_API_KEY is not set — nothing to sync.')
            ->assertExitCode(0);

        Http::assertNothingSent();
        $this->assertSame(0, GarminSync::count());
        $this->assertSame(0, Activity::count());
    }

    public function test_a_bare_run_is_a_manual_sync_that_prints_what_it_did(): void
    {
        $this->fakeGarmin([$this->apiRun(1, '2026-11-10 07:00:00'), $this->apiRun(2, '2026-11-09 07:00:00', ['activityType' => ['typeKey' => 'cycling']])]);
        User::factory()->ownerWithPassword()->create();

        $this->artisan('garmin:sync')
            ->expectsTable(['New', 'Updated', 'Linked', 'Ignored', 'Future-dated', 'Invalid'], [[1, 0, 0, 1, 0, 0]])
            ->assertExitCode(0);

        $this->assertSame(GarminSyncTrigger::Manual, GarminSync::sole()->trigger);
        $this->assertSame(1, Activity::count());
    }

    public function test_the_scheduled_flag_marks_the_attempt_as_the_schedulers(): void
    {
        $this->fakeGarmin([]);
        User::factory()->ownerWithPassword()->create();

        $this->artisan('garmin:sync --scheduled')->assertExitCode(0);

        $this->assertSame(GarminSyncTrigger::Scheduled, GarminSync::sole()->trigger);
        $this->assertSame(GarminSyncOutcome::Succeeded, GarminSync::sole()->outcome);
    }

    public function test_a_second_run_changes_nothing_and_says_so(): void
    {
        $this->fakeGarmin([$this->apiRun(1, '2026-11-10 07:00:00')]);
        User::factory()->ownerWithPassword()->create();
        $this->artisan('garmin:sync')->assertExitCode(0);

        $this->artisan('garmin:sync')
            ->expectsTable(['New', 'Updated', 'Linked', 'Ignored', 'Future-dated', 'Invalid'], [[0, 0, 0, 0, 0, 0]])
            ->assertExitCode(0);
    }

    public function test_an_unreachable_garmin_prints_the_warning_and_fails(): void
    {
        $this->fakeGarmin(searchStatus: 503);
        User::factory()->ownerWithPassword()->create();

        $this->artisan('garmin:sync')
            ->expectsOutputToContain('Garmin unreachable')
            ->assertExitCode(1);
    }

    public function test_a_rejected_key_prints_the_renew_warning_and_fails(): void
    {
        $this->fakeGarmin(tokenStatus: 401);
        User::factory()->ownerWithPassword()->create();

        $this->artisan('garmin:sync')
            ->expectsOutputToContain('Garmin key rejected — renew it')
            ->assertExitCode(1);
    }

    public function test_while_another_sync_runs_it_is_refused_and_starts_nothing(): void
    {
        Config::set('services.garmin.api_key', 'GCDC_key');
        User::factory()->ownerWithPassword()->create();
        Http::fake();
        $lock = Cache::lock('garmin:sync', 60);
        $this->assertTrue($lock->get());

        try {
            $this->artisan('garmin:sync')
                ->expectsOutputToContain('A Garmin sync is already running — nothing started.')
                ->assertExitCode(1);
        } finally {
            $lock->release();
        }

        Http::assertNothingSent();
        $this->assertSame(0, GarminSync::count());
    }

    public function test_without_an_owner_account_it_fails_and_calls_nothing(): void
    {
        Config::set('services.garmin.api_key', 'GCDC_key');
        Http::fake();

        $this->artisan('garmin:sync')
            ->expectsOutputToContain('There is no owner account to sync into.')
            ->assertExitCode(1);

        Http::assertNothingSent();
    }

    /**
     * @param  array<int, array<string, mixed>>  $page
     */
    private function fakeGarmin(array $page = [], int $tokenStatus = 200, int $searchStatus = 200): void
    {
        Config::set('services.garmin.api_key', 'GCDC_command-test-key');

        $encode = fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
        $token = $encode('{"alg":"none"}').'.'.$encode((string) json_encode(['exp' => now()->addDay()->getTimestamp()])).'.'.$encode('signature');

        Http::fake([
            self::TOKEN_URL => $tokenStatus === 200 ? Http::response($token) : Http::response('denied', $tokenStatus),
            self::SEARCH_URL => $searchStatus === 200 ? Http::response($page) : Http::response('', $searchStatus),
        ]);
    }

    /**
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
}
