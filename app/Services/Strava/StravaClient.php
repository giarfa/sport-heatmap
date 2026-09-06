<?php

namespace App\Services\Strava;

use App\Actions\Strava\EnsureFreshAccessToken;
use App\Data\StravaActivity;
use App\Exceptions\Strava\StravaApiException;
use App\Models\User;
use App\Support\Heatmap\HeatmapWindow;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The only class that talks to Strava's activities API.
 *
 * Owns the access token, pagination, retry/backoff, and the mid-sync 401
 * refresh — SyncStravaActivities and ReconcileStravaWindow never see HTTP.
 */
class StravaClient
{
    public function __construct(private readonly EnsureFreshAccessToken $ensureFreshAccessToken) {}

    /**
     * Fetch every running activity Strava reports inside the window.
     *
     * All pages are collected before returning: the caller applies them in one
     * transaction, so a retry exhausted on a later page must not have left any
     * earlier page already written anywhere.
     *
     * @return array<int, StravaActivity>
     *
     * @throws StravaApiException
     */
    public function activitiesInWindow(User $user, HeatmapWindow $window): array
    {
        $activities = [];
        $currentToken = ($this->ensureFreshAccessToken)($user);
        $perPage = (int) config('strava.activities_per_page');
        $maxPages = (int) config('strava.max_activity_pages');
        $hitPageCap = true;

        for ($page = 1; $page <= $maxPages; $page++) {
            $refreshedOnce = false;

            $response = $this->fetchPage($user, $window, $page, $perPage, $currentToken, $refreshedOnce);

            if ($response->failed()) {
                throw StravaApiException::requestFailed($response->status(), $response->header('X-RateLimit-Usage'));
            }

            $payload = $response->json();

            if (! is_array($payload)) {
                throw StravaApiException::malformedResponse();
            }

            foreach ($payload as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $activity = StravaActivity::fromApiPayload($item);

                if ($activity !== null && $window->contains($activity->activityDate)) {
                    $activities[] = $activity;
                }
            }

            if (count($payload) < $perPage) {
                $hitPageCap = false;

                break;
            }
        }

        if ($hitPageCap) {
            /*
             * Hitting the cap means the window assumption — this instance's
             * activity volume comfortably fits inside max_activity_pages — no
             * longer holds. It is not itself a failure: the pages fetched so far
             * are still applied.
             */
            Log::warning('Strava activities sync hit the max_activity_pages safety cap.', [
                'athlete_id' => $user->strava_athlete_id,
                'max_activity_pages' => $maxPages,
            ]);
        }

        return $activities;
    }

    /**
     * @throws StravaApiException
     */
    private function fetchPage(User $user, HeatmapWindow $window, int $page, int $perPage, string &$currentToken, bool &$refreshedOnce): Response
    {
        try {
            return Http::withToken($currentToken)
                ->connectTimeout(5)
                ->timeout(15)
                ->retry(
                    (int) config('strava.retry_attempts'),
                    /*
                     * $times above is the attempt count; the backoff array is
                     * applied here, indexed by attempt, because PendingRequest::
                     * retry() only accepts an array as its *first* argument (the
                     * attempt count itself) — not as the sleep argument.
                     */
                    fn (int $attempt): int => (int) (self::retryBackoffMilliseconds()[$attempt - 1] ?? 0),
                    function (Throwable $exception, PendingRequest $request) use ($user, &$currentToken, &$refreshedOnce): bool {
                        if ($exception instanceof ConnectionException) {
                            return true;
                        }

                        if (! $exception instanceof RequestException) {
                            return false;
                        }

                        $status = $exception->response->status();

                        if ($status === 429 || $status >= 500) {
                            return true;
                        }

                        /*
                         * Guarded so this can only fire once per page: a token
                         * that is fresh and still rejected means the
                         * authorisation itself is gone, and retrying would loop.
                         */
                        if ($status === 401 && ! $refreshedOnce) {
                            $refreshedOnce = true;
                            $currentToken = ($this->ensureFreshAccessToken)($user, rejectedToken: $currentToken);
                            $request->withToken($currentToken);

                            return true;
                        }

                        return false;
                    },
                    throw: false,
                )
                ->get(config('strava.api_base_url').'/athlete/activities', [
                    'after' => $window->afterTimestamp(),
                    'per_page' => $perPage,
                    'page' => $page,
                ]);
        } catch (ConnectionException $exception) {
            throw StravaApiException::unreachable($exception);
        }
    }

    /**
     * @return array<int, int>
     */
    private static function retryBackoffMilliseconds(): array
    {
        /** @var array<int, int> $backoff */
        $backoff = config('strava.retry_backoff_ms');

        return $backoff;
    }
}
