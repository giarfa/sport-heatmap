<?php

namespace Tests\Feature\Strava;

use App\Exceptions\Strava\StravaApiException;
use App\Services\Strava\StravaClient;
use App\Support\Heatmap\HeatmapWindow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StravaClientTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Config::set('strava.retry_backoff_ms', [0, 0, 0]);
    }

    public function test_a_full_page_followed_by_a_short_page_issues_two_requests(): void
    {
        Config::set('strava.activities_per_page', 2);

        $user = $this->allowlistedAthlete();
        $window = HeatmapWindow::current();

        Http::fake([
            'www.strava.com/api/v3/athlete/activities*' => function (Request $request) {
                return match ((int) $request['page']) {
                    1 => Http::response([
                        $this->stravaActivityPayload(['id' => 1]),
                        $this->stravaActivityPayload(['id' => 2]),
                    ]),
                    default => Http::response([$this->stravaActivityPayload(['id' => 3])]),
                };
            },
        ]);

        $activities = $this->client()->activitiesInWindow($user, $window);

        $this->assertCount(3, $activities);
        Http::assertSentCount(2);

        Http::assertSent(function (Request $request) use ($window): bool {
            return (int) $request['after'] === $window->afterTimestamp()
                && (int) $request['per_page'] === 2
                && (int) $request['page'] === 1;
        });
    }

    public function test_a_single_short_page_issues_one_request(): void
    {
        Config::set('strava.activities_per_page', 2);

        $user = $this->allowlistedAthlete();

        Http::fake([
            'www.strava.com/api/v3/athlete/activities*' => Http::response([$this->stravaActivityPayload()]),
        ]);

        $activities = $this->client()->activitiesInWindow($user, HeatmapWindow::current());

        $this->assertCount(1, $activities);
        Http::assertSentCount(1);
    }

    public function test_non_running_sport_types_are_absent_from_the_result(): void
    {
        $user = $this->allowlistedAthlete();

        Http::fake([
            'www.strava.com/api/v3/athlete/activities*' => Http::response([
                $this->stravaActivityPayload(['id' => 1]),
                $this->stravaActivityPayload(['id' => 2, 'sport_type' => 'Ride']),
            ]),
        ]);

        $activities = $this->client()->activitiesInWindow($user, HeatmapWindow::current());

        $this->assertCount(1, $activities);
        $this->assertSame(1, $activities[0]->stravaActivityId);
    }

    public function test_an_activity_before_the_window_start_is_filtered_out(): void
    {
        $user = $this->allowlistedAthlete();
        $window = HeatmapWindow::current();
        $beforeWindow = $window->startsOn->subDay();

        Http::fake([
            'www.strava.com/api/v3/athlete/activities*' => Http::response([
                $this->stravaActivityPayload(['id' => 1]),
                $this->stravaActivityPayload([
                    'id' => 2,
                    'start_date_local' => $beforeWindow->format('Y-m-d\TH:i:s\Z'),
                ]),
            ]),
        ]);

        $activities = $this->client()->activitiesInWindow($user, $window);

        $this->assertCount(1, $activities);
        $this->assertSame(1, $activities[0]->stravaActivityId);
    }

    public function test_a_429_response_is_retried_and_then_succeeds(): void
    {
        $user = $this->allowlistedAthlete();

        Http::fake([
            'www.strava.com/api/v3/athlete/activities*' => Http::sequence()
                ->push(['message' => 'Rate limited'], 429)
                ->push([$this->stravaActivityPayload()]),
        ]);

        $activities = $this->client()->activitiesInWindow($user, HeatmapWindow::current());

        $this->assertCount(1, $activities);
        Http::assertSentCount(2);
    }

    public function test_a_500_on_every_attempt_raises_an_exception_carrying_no_token(): void
    {
        Config::set('strava.retry_attempts', 3);

        $user = $this->allowlistedAthlete();
        $accessToken = (string) $user->strava_access_token;

        Http::fake([
            'www.strava.com/api/v3/athlete/activities*' => Http::response(
                ['message' => 'Internal Server Error'],
                500,
                ['X-RateLimit-Usage' => '600,1000'],
            ),
        ]);

        try {
            $this->client()->activitiesInWindow($user, HeatmapWindow::current());
            $this->fail('An exhausted retry against a 500 must raise StravaApiException.');
        } catch (StravaApiException $exception) {
            $this->assertStringContainsString('500', $exception->getMessage());
            $this->assertStringContainsString('600,1000', $exception->getMessage());
            $this->assertStringNotContainsString($accessToken, $exception->getMessage());
        }

        Http::assertSentCount(3);
    }

    public function test_a_401_mid_sync_refreshes_the_token_and_retries_once(): void
    {
        $user = $this->allowlistedAthlete();
        $originalAccessToken = (string) $user->strava_access_token;

        Http::fake([
            'www.strava.com/oauth/token' => Http::response([
                'access_token' => 'rotated-access-token',
                'refresh_token' => 'rotated-refresh-token',
                'expires_at' => now()->addHours(6)->getTimestamp(),
            ]),
            'www.strava.com/api/v3/athlete/activities*' => Http::sequence()
                ->push(['message' => 'Unauthorized'], 401)
                ->push([$this->stravaActivityPayload()]),
        ]);

        $activities = $this->client()->activitiesInWindow($user, HeatmapWindow::current());

        $this->assertCount(1, $activities);

        $requests = $this->activitiesRequests();

        $this->assertCount(2, $requests);
        $this->assertSame('Bearer '.$originalAccessToken, $this->bearerHeader($requests[0]));
        $this->assertNotSame(
            $this->bearerHeader($requests[0]),
            $this->bearerHeader($requests[1]),
        );

        $user->refresh();
        $this->assertSame('rotated-access-token', $user->strava_access_token);
        $this->assertSame('rotated-refresh-token', $user->strava_refresh_token);
    }

    public function test_a_second_401_with_a_freshly_refreshed_token_fails_instead_of_looping(): void
    {
        $user = $this->allowlistedAthlete();

        Http::fake([
            'www.strava.com/oauth/token' => Http::response([
                'access_token' => 'rotated-access-token',
                'refresh_token' => 'rotated-refresh-token',
                'expires_at' => now()->addHours(6)->getTimestamp(),
            ]),
            'www.strava.com/api/v3/athlete/activities*' => Http::sequence()
                ->push(['message' => 'Unauthorized'], 401)
                ->push(['message' => 'Unauthorized'], 401),
        ]);

        $this->expectException(StravaApiException::class);

        try {
            $this->client()->activitiesInWindow($user, HeatmapWindow::current());
        } finally {
            $this->assertCount(2, $this->activitiesRequests());
        }
    }

    public function test_the_max_activity_pages_cap_stops_the_loop_when_every_page_is_full(): void
    {
        Config::set('strava.activities_per_page', 2);
        Config::set('strava.max_activity_pages', 2);

        $user = $this->allowlistedAthlete();

        Http::fake([
            'www.strava.com/api/v3/athlete/activities*' => Http::response([
                $this->stravaActivityPayload(['id' => 1]),
                $this->stravaActivityPayload(['id' => 2]),
            ]),
        ]);

        $activities = $this->client()->activitiesInWindow($user, HeatmapWindow::current());

        $this->assertCount(4, $activities);
        Http::assertSentCount(2);
    }

    private function client(): StravaClient
    {
        return app(StravaClient::class);
    }

    private function bearerHeader(Request $request): ?string
    {
        $header = $request->header('Authorization');

        return $header[0] ?? null;
    }

    /**
     * @return array<int, Request>
     */
    private function activitiesRequests(): array
    {
        return collect(Http::recorded())
            ->filter(static fn (array $pair): bool => str_contains($pair[0]->url(), 'athlete/activities'))
            ->map(static fn (array $pair): Request => $pair[0])
            ->values()
            ->all();
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
}
