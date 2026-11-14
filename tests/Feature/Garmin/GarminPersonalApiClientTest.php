<?php

namespace Tests\Feature\Garmin;

use App\Enums\GarminSyncFailure;
use App\Exceptions\Garmin\GarminApiException;
use App\Support\Garmin\GarminPersonalApiClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GarminPersonalApiClientTest extends TestCase
{
    private const KEY = 'GCDC_test-key-never-leak-me';

    private const TOKEN_URL = 'https://personal.connectapi.garmin.com/personalapitoken-service/personal-api/personal-api-key/token';

    private const SEARCH_URL = 'https://personal.connectapi.garmin.com/activitylist-service/activities/search/activities*';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        Config::set('services.garmin.api_key', self::KEY);

        /*
         * A store of its own, apart from the default `array` one: it lets the tests prove
         * the token is written to the configured store and never to the default cache,
         * which in production is the database.
         */
        Config::set('cache.stores.garmin_token_test', ['driver' => 'array']);
        Config::set('services.garmin.token_cache_store', 'garmin_token_test');

        Carbon::setTestNow('2026-11-14 08:00:00');
    }

    public function test_it_exchanges_the_key_for_a_raw_jwt_and_sends_it_as_a_bearer_token(): void
    {
        $token = $this->jwt(now()->addDay()->getTimestamp());
        $this->fakeGarmin(tokens: [$token], pages: [$this->fixturePage()]);

        $page = $this->client()->activities(0, 100);

        $this->assertCount(8, $page);
        $this->assertSame(11111111101, $page[0]['activityId']);

        $exchange = $this->requestsTo('personal-api-key/token')[0];
        $this->assertSame('POST', $exchange->method());
        $this->assertSame('ApiKey '.self::KEY, $exchange->header('Authorization')[0]);

        $search = $this->requestsTo('search/activities')[0];
        $this->assertSame('GET', $search->method());
        $this->assertSame("Bearer {$token}", $search->header('Authorization')[0]);
        $this->assertSame('application/json', $search->header('Accept')[0]);
    }

    public function test_it_sends_the_paging_and_the_date_window_and_omits_a_missing_date(): void
    {
        $this->fakeGarmin(tokens: [$this->jwt(now()->addDay()->getTimestamp())], pages: [[], []]);

        $this->client()->activities(200, 100, Carbon::parse('2026-11-01 15:00:00'), Carbon::parse('2026-11-14'));
        $this->client()->activities(0, 100);

        [$windowed, $unbounded] = $this->requestsTo('search/activities');
        parse_str((string) parse_url($windowed->url(), PHP_URL_QUERY), $windowedQuery);
        parse_str((string) parse_url($unbounded->url(), PHP_URL_QUERY), $unboundedQuery);

        $this->assertSame(['start' => '200', 'limit' => '100', 'startDate' => '2026-11-01', 'endDate' => '2026-11-14'], $windowedQuery);
        $this->assertSame(['start' => '0', 'limit' => '100'], $unboundedQuery);
    }

    public function test_a_token_is_reused_across_calls_so_the_key_is_exchanged_once(): void
    {
        $this->fakeGarmin(tokens: [$this->jwt(now()->addDay()->getTimestamp())], pages: [[], []]);

        $this->client()->activities(0, 100);
        $this->client()->activities(100, 100);

        $this->assertCount(1, $this->requestsTo('personal-api-key/token'));
        $this->assertCount(2, $this->requestsTo('search/activities'));
    }

    public function test_a_token_is_kept_until_one_hour_before_it_expires_and_then_exchanged_again(): void
    {
        $this->fakeGarmin(tokens: [
            $this->jwt(now()->addHours(3)->getTimestamp()),
            $this->jwt(now()->addHours(30)->getTimestamp()),
        ], pages: [[], [], []]);

        $this->client()->activities(0, 100);

        $this->travel(119)->minutes();
        $this->client()->activities(0, 100);
        $this->assertCount(1, $this->requestsTo('personal-api-key/token'), 'Still more than an hour before exp.');

        $this->travel(2)->minutes();
        $this->client()->activities(0, 100);
        $this->assertCount(2, $this->requestsTo('personal-api-key/token'), 'Inside the last hour the token is replaced.');
    }

    public function test_a_token_already_inside_its_last_hour_is_used_once_and_not_cached(): void
    {
        $this->fakeGarmin(tokens: [$this->jwt(now()->addMinutes(30)->getTimestamp())], pages: [[], []]);

        $this->client()->activities(0, 100);
        $this->client()->activities(0, 100);

        $this->assertCount(2, $this->requestsTo('personal-api-key/token'));
        $this->assertCount(2, $this->requestsTo('search/activities'));
    }

    public function test_the_token_is_stored_in_the_configured_store_and_never_in_the_default_cache(): void
    {
        $token = $this->jwt(now()->addDay()->getTimestamp());
        $this->fakeGarmin(tokens: [$token], pages: [[]]);

        $this->client()->activities(0, 100);

        $this->assertSame($token, Cache::store('garmin_token_test')->get('garmin:personal-api-token'));
        $this->assertNull(Cache::store(config('cache.default'))->get('garmin:personal-api-token'));
    }

    public function test_a_data_call_401_drops_the_token_exchanges_once_more_and_retries_once(): void
    {
        $first = $this->jwt(now()->addDay()->getTimestamp(), 'first');
        $second = $this->jwt(now()->addDay()->getTimestamp(), 'second');
        Http::fake([
            self::TOKEN_URL => Http::sequence()->push($first)->push($second),
            self::SEARCH_URL => Http::sequence()->push('', 401)->push($this->fixturePage()),
        ]);

        $page = $this->client()->activities(0, 100);

        $this->assertCount(8, $page);
        $this->assertCount(2, $this->requestsTo('personal-api-key/token'));

        [$refused, $retried] = $this->requestsTo('search/activities');
        $this->assertSame("Bearer {$first}", $refused->header('Authorization')[0]);
        $this->assertSame("Bearer {$second}", $retried->header('Authorization')[0]);
    }

    public function test_a_second_401_on_the_data_call_is_a_rejected_key_and_is_not_retried_again(): void
    {
        $this->fakeGarmin(
            tokens: [$this->jwt(now()->addDay()->getTimestamp(), 'a'), $this->jwt(now()->addDay()->getTimestamp(), 'b')],
            pages: [new FailedPage(401), new FailedPage(401)],
        );

        $exception = $this->failureOf(fn () => $this->client()->activities(0, 100));

        $this->assertSame(GarminSyncFailure::KeyRejected, $exception->cause);
        $this->assertSame(401, $exception->status);
        $this->assertCount(2, $this->requestsTo('personal-api-key/token'));
        $this->assertCount(2, $this->requestsTo('search/activities'));
    }

    /**
     * @return array<string, array{int, GarminSyncFailure}>
     */
    public static function tokenCallStatuses(): array
    {
        return [
            '401 is a rejected key' => [401, GarminSyncFailure::KeyRejected],
            '403 is a rejected key' => [403, GarminSyncFailure::KeyRejected],
            '429 is unreachable' => [429, GarminSyncFailure::Unreachable],
            '500 is unreachable' => [500, GarminSyncFailure::Unreachable],
            '503 is unreachable' => [503, GarminSyncFailure::Unreachable],
            '400 is unexpected' => [400, GarminSyncFailure::Unexpected],
            '404 is unexpected' => [404, GarminSyncFailure::Unexpected],
            'a redirect is never followed and is unexpected' => [302, GarminSyncFailure::Unexpected],
        ];
    }

    #[DataProvider('tokenCallStatuses')]
    public function test_the_token_call_maps_its_status_to_a_cause(int $status, GarminSyncFailure $cause): void
    {
        Http::fake([self::TOKEN_URL => Http::response('{"message":"'.self::KEY.' nope"}', $status)]);

        $exception = $this->failureOf(fn () => $this->client()->activities(0, 100));

        $this->assertSame($cause, $exception->cause);
        $this->assertSame($status, $exception->status);
        $this->assertCount(0, $this->requestsTo('search/activities'), 'No data call is made without a token.');
        $this->assertSecretFree($exception);
    }

    /**
     * @return array<string, array{int, GarminSyncFailure}>
     */
    public static function dataCallStatuses(): array
    {
        return [
            '403 is a rejected key' => [403, GarminSyncFailure::KeyRejected],
            '429 is unreachable' => [429, GarminSyncFailure::Unreachable],
            '500 is unreachable' => [500, GarminSyncFailure::Unreachable],
            '503 is unreachable' => [503, GarminSyncFailure::Unreachable],
            '400 is unexpected' => [400, GarminSyncFailure::Unexpected],
            '404 is unexpected' => [404, GarminSyncFailure::Unexpected],
            'a redirect is never followed and is unexpected' => [302, GarminSyncFailure::Unexpected],
        ];
    }

    #[DataProvider('dataCallStatuses')]
    public function test_the_data_call_maps_its_status_to_a_cause(int $status, GarminSyncFailure $cause): void
    {
        $token = $this->jwt(now()->addDay()->getTimestamp());
        $this->fakeGarmin(tokens: [$token], pages: [new FailedPage($status, 'secret '.$token)]);

        $exception = $this->failureOf(fn () => $this->client()->activities(0, 100));

        $this->assertSame($cause, $exception->cause);
        $this->assertSame($status, $exception->status);
        $this->assertSecretFree($exception, $token);
    }

    public function test_a_connection_failure_or_timeout_on_the_token_call_is_unreachable_and_chained_without_its_message(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out for '.self::TOKEN_URL.' with '.self::KEY));

        $exception = $this->failureOf(fn () => $this->client()->activities(0, 100));

        $this->assertSame(GarminSyncFailure::Unreachable, $exception->cause);
        $this->assertNull($exception->status);
        $this->assertInstanceOf(ConnectionException::class, $exception->getPrevious());
        $this->assertSecretFree($exception);
        $this->assertStringNotContainsString('https://', $exception->getMessage());
    }

    public function test_a_connection_failure_or_timeout_on_the_data_call_is_unreachable_and_chained_without_its_message(): void
    {
        $token = $this->jwt(now()->addDay()->getTimestamp());
        Http::fake([
            self::TOKEN_URL => Http::response($token),
            self::SEARCH_URL => fn () => throw new ConnectionException('Connection refused for '.self::SEARCH_URL.' with '.$token),
        ]);

        $exception = $this->failureOf(fn () => $this->client()->activities(0, 100));

        $this->assertSame(GarminSyncFailure::Unreachable, $exception->cause);
        $this->assertInstanceOf(ConnectionException::class, $exception->getPrevious());
        $this->assertSecretFree($exception, $token);
        $this->assertStringNotContainsString('https://', $exception->getMessage());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unusableTokenBodies(): array
    {
        $encode = fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
        $withClaims = fn (array $claims): string => $encode('{"alg":"none"}').'.'.$encode((string) json_encode($claims)).'.'.$encode('signature');

        return [
            'not a jwt' => ['not-a-jwt'],
            'two segments' => ['a.b'],
            'no exp claim' => [$withClaims(['sub' => 'owner'])],
            'a string exp' => [$withClaims(['exp' => 'tomorrow'])],
            'a payload that is not json' => ['aaa.'.$encode('not json').'.ccc'],
            'an empty body' => [''],
            'only whitespace' => ['   '],
        ];
    }

    #[DataProvider('unusableTokenBodies')]
    public function test_a_token_that_is_not_a_jwt_or_has_no_readable_exp_is_unexpected_and_not_cached(string $body): void
    {
        Http::fake([self::TOKEN_URL => Http::response($body)]);

        $exception = $this->failureOf(fn () => $this->client()->activities(0, 100));

        $this->assertSame(GarminSyncFailure::Unexpected, $exception->cause);
        $this->assertSecretFree($exception, $body);
        $this->assertNull(Cache::store('garmin_token_test')->get('garmin:personal-api-token'), 'No token is cached on a guessed lifetime.');
        $this->assertCount(0, $this->requestsTo('search/activities'));
    }

    public function test_a_token_wrapped_in_quotes_and_whitespace_is_still_read(): void
    {
        $token = $this->jwt(now()->addDay()->getTimestamp());
        $this->fakeGarmin(tokens: ["  \"{$token}\"\n"], pages: [[]]);

        $this->client()->activities(0, 100);

        $this->assertSame("Bearer {$token}", $this->requestsTo('search/activities')[0]->header('Authorization')[0]);
    }

    public function test_a_page_that_is_not_a_json_list_is_unexpected_and_a_non_object_item_keeps_the_page_length(): void
    {
        $token = $this->jwt(now()->addDay()->getTimestamp());
        $this->fakeGarmin(tokens: [$token], pages: [['message' => 'Bad request'], 'not json at all', [['activityId' => 1], 'junk', null]]);

        $this->assertSame(GarminSyncFailure::Unexpected, $this->failureOf(fn () => $this->client()->activities(0, 100))->cause);
        $this->assertSame(GarminSyncFailure::Unexpected, $this->failureOf(fn () => $this->client()->activities(0, 100))->cause);
        $this->assertSame([['activityId' => 1], [], []], $this->client()->activities(0, 100));
    }

    public function test_without_a_key_it_is_not_configured_and_calls_nothing(): void
    {
        Config::set('services.garmin.api_key', '');
        Http::fake();

        $this->assertFalse($this->client()->isConfigured());

        $exception = $this->failureOf(fn () => $this->client()->activities(0, 100));

        $this->assertSame(GarminSyncFailure::Unexpected, $exception->cause);
        Http::assertNothingSent();

        Config::set('services.garmin.api_key', null);
        $this->assertFalse($this->client()->isConfigured());

        Config::set('services.garmin.api_key', self::KEY);
        $this->assertTrue($this->client()->isConfigured());
    }

    public function test_it_writes_nothing_to_the_log(): void
    {
        $log = Log::spy();
        $this->fakeGarmin(tokens: [$this->jwt(now()->addDay()->getTimestamp())], pages: [new FailedPage(500)]);

        $this->failureOf(fn () => $this->client()->activities(0, 100));

        $log->shouldNotHaveReceived('error');
        $log->shouldNotHaveReceived('warning');
        $log->shouldNotHaveReceived('info');
        $log->shouldNotHaveReceived('debug');
    }

    private function client(): GarminPersonalApiClient
    {
        return new GarminPersonalApiClient;
    }

    /**
     * Fake the token call and the activity search, each answering in order.
     *
     * @param  array<int, string>  $tokens  raw JWT bodies
     * @param  array<int, mixed>  $pages  a list to answer as JSON, a string as a raw body, a FailedPage as an error
     */
    private function fakeGarmin(array $tokens, array $pages): void
    {
        $tokenSequence = Http::sequence();
        foreach ($tokens as $token) {
            $tokenSequence->push($token);
        }
        $tokenSequence->whenEmpty(Http::response($tokens[array_key_last($tokens)]));

        $searchSequence = Http::sequence();
        foreach ($pages as $page) {
            match (true) {
                $page instanceof FailedPage => $searchSequence->push($page->body, $page->status),
                is_string($page) => $searchSequence->push($page),
                default => $searchSequence->push($page),
            };
        }

        Http::fake([self::TOKEN_URL => $tokenSequence, self::SEARCH_URL => $searchSequence]);
    }

    /**
     * @return array<int, Request>
     */
    private function requestsTo(string $fragment): array
    {
        return Http::recorded()
            ->map(fn (array $pair): Request => $pair[0])
            ->filter(fn (Request $request): bool => str_contains($request->url(), $fragment))
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fixturePage(): array
    {
        return json_decode((string) file_get_contents(base_path('tests/Fixtures/Garmin/api/activities-page-mixed.json')), true);
    }

    /**
     * An unsigned JWT: header.payload.signature, base64url, with an `exp` claim.
     */
    private function jwt(int $exp, string $subject = 'owner'): string
    {
        return $this->jwtFromClaims(['sub' => $subject, 'exp' => $exp]);
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function jwtFromClaims(array $claims): string
    {
        $encode = fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');

        return $encode('{"alg":"none"}').'.'.$encode((string) json_encode($claims)).'.'.$encode('signature');
    }

    private function failureOf(callable $call): GarminApiException
    {
        try {
            $call();
        } catch (GarminApiException $exception) {
            return $exception;
        }

        $this->fail('Expected a GarminApiException.');
    }

    private function assertSecretFree(GarminApiException $exception, ?string $token = null): void
    {
        $this->assertStringNotContainsString(self::KEY, $exception->getMessage());
        $this->assertStringNotContainsString('Bearer', $exception->getMessage());
        $this->assertStringNotContainsString('secret', $exception->getMessage());

        if ($token !== null && $token !== '') {
            $this->assertStringNotContainsString($token, $exception->getMessage());
        }

        $this->assertNull(Cache::store(config('cache.default'))->get('garmin:personal-api-token'));
    }
}

/**
 * A failed activity-search answer: a status and a body that must never reach a message.
 */
final readonly class FailedPage
{
    public function __construct(public int $status, public string $body = '') {}
}
