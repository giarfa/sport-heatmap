<?php

namespace Tests\Feature\Garmin;

use App\Models\Activity;
use App\Models\GarminSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Sync now at the HTTP boundary: who may press it, what it reports, and when it is
 * refused. Every Garmin call is faked.
 */
class SyncGarminNowTest extends TestCase
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

    public function test_a_signed_out_request_is_sent_to_the_login_and_calls_nothing(): void
    {
        $this->withKey();

        $this->post(route('garmin.sync'))->assertRedirect(route('login'));

        Http::assertNothingSent();
        $this->assertSame(0, GarminSync::count());
    }

    public function test_the_route_is_a_post_behind_the_session_the_web_group_and_its_own_throttle(): void
    {
        $route = Route::getRoutes()->getByName('garmin.sync');

        $this->assertNotNull($route);
        $this->assertSame(['POST'], $route->methods());
        $this->assertSame('sync/garmin', $route->uri());
        $this->assertEqualsCanonicalizing(
            ['web', 'auth', 'password.set', 'throttle:garmin-sync'],
            $route->gatherMiddleware(),
        );
    }

    public function test_a_get_does_not_start_a_sync(): void
    {
        $this->withKey();
        $this->signInOwner();

        $this->get('/sync/garmin')->assertStatus(405);

        Http::assertNothingSent();
    }

    public function test_it_runs_the_sync_and_reports_the_counts_on_the_dashboard(): void
    {
        $this->withKey([
            $this->apiRun(1, '2026-11-10 07:00:00'),
            $this->apiRun(2, '2026-11-09 07:00:00', ['activityType' => ['typeKey' => 'cycling']]),
        ]);
        $this->signInOwner();

        $response = $this->post(route('garmin.sync'));

        $response->assertRedirect(route('dashboard'));
        $response->assertSessionHas('status', 'Sync finished: 1 new, 0 updated, 0 linked, 1 ignored, 0 future-dated.');
        $this->assertSame(1, Activity::count());
        $this->assertSame(1, GarminSync::count());

        $this->followRedirects($response)->assertSee('Sync finished: 1 new, 0 updated, 0 linked, 1 ignored, 0 future-dated.');
    }

    public function test_unreadable_activities_are_reported_only_when_there_are_some(): void
    {
        $this->withKey([[...$this->apiRun(1, '2026-11-10 07:00:00'), 'distance' => null]]);
        $this->signInOwner();

        $this->post(route('garmin.sync'))
            ->assertSessionHas('status', 'Sync finished: 0 new, 0 updated, 0 linked, 0 ignored, 0 future-dated, 1 unreadable.');
    }

    public function test_a_manual_attempt_is_recorded_as_manual(): void
    {
        $this->withKey([]);
        $this->signInOwner();

        $this->post(route('garmin.sync'));

        $this->assertSame('manual', GarminSync::sole()->trigger->value);
    }

    public function test_a_failed_sync_reports_the_cause(): void
    {
        $this->withKey(searchStatus: 503);
        $this->signInOwner();

        $this->post(route('garmin.sync'))->assertSessionHas('status', 'Sync failed: Garmin unreachable');
    }

    public function test_a_rejected_key_reports_that_the_key_must_be_renewed(): void
    {
        $this->withKey(tokenStatus: 403);
        $this->signInOwner();

        $this->post(route('garmin.sync'))->assertSessionHas('status', 'Sync failed: Garmin key rejected — renew it');
    }

    public function test_while_a_sync_runs_it_is_refused_with_a_message_and_starts_nothing(): void
    {
        $this->withKey([$this->apiRun(1, '2026-11-10 07:00:00')]);
        $this->signInOwner();
        $lock = Cache::lock('garmin:sync', 60);
        $this->assertTrue($lock->get());

        try {
            $response = $this->post(route('garmin.sync'));
        } finally {
            $lock->release();
        }

        $response->assertRedirect(route('dashboard'));
        $response->assertSessionHas('status', 'A Garmin sync is already running — nothing started.');
        Http::assertNothingSent();
        $this->assertSame(0, GarminSync::count());
        $this->assertSame(0, Activity::count());
    }

    public function test_the_fourth_press_within_a_minute_is_throttled(): void
    {
        $this->withKey([]);
        $this->signInOwner();

        foreach (range(1, 3) as $press) {
            $this->post(route('garmin.sync'))->assertRedirect(route('dashboard'));
        }

        $this->post(route('garmin.sync'))->assertStatus(429);
        $this->assertSame(3, GarminSync::count(), 'The throttled press starts no sync.');
    }

    public function test_with_no_key_the_button_is_absent_and_a_post_calls_nothing(): void
    {
        $this->withKey(key: '');
        $this->signInOwner();

        $this->get('/')->assertDontSee('Sync now');

        $this->post(route('garmin.sync'))->assertRedirect(route('dashboard'))
            ->assertSessionHas('status', 'Garmin sync is not configured.');

        Http::assertNothingSent();
        $this->assertSame(0, GarminSync::count());
    }

    public function test_with_a_key_the_button_is_a_csrf_form_post_on_both_screens(): void
    {
        $this->withKey([]);
        $owner = $this->signInOwner();

        $empty = (string) $this->get('/')->getContent();
        $this->assertStringContainsString('action="'.route('garmin.sync').'"', $empty);
        $this->assertStringContainsString('Sync now', $empty);
        $this->assertMatchesRegularExpression('/<form method="POST" action="[^"]*sync\/garmin"[^>]*>\s*<input type="hidden" name="_token"/', $empty);

        Activity::factory()->for($owner)->create();
        $dashboard = (string) $this->get('/')->getContent();
        $this->assertStringContainsString('action="'.route('garmin.sync').'"', $dashboard);
        $this->assertMatchesRegularExpression('/<form method="POST" action="[^"]*sync\/garmin"[^>]*>\s*<input type="hidden" name="_token"/', $dashboard);
    }

    /**
     * Configure a key and fake Garmin: the token call, then the activity search.
     *
     * @param  array<int, array<string, mixed>>  $page
     */
    private function withKey(array $page = [], string $key = 'GCDC_now-test-key', int $tokenStatus = 200, int $searchStatus = 200): void
    {
        Config::set('services.garmin.api_key', $key);

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
