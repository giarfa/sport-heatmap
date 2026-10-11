# Strava connection

| | |
| --- | --- |
| **Status** | Active |
| **Introduced by** | `US-002` |
| **Last updated by** | `US-007` — 2026-10-11 (catch-up) |
| **Owner persona** | 🔗 Matt |

## Purpose

Connects the owner's Strava account to this instance and keeps that connection
usable. It authenticates *a* Strava athlete through OAuth2 (authorization code),
decides whether it is *the* athlete through an allowlist of Strava athlete ids,
stores a read-only token pair encrypted at rest, and refreshes the access token
before any Strava call needs it. If it stops working, nobody can sign in and the
nightly sync can no longer read activities.

## Functional flow

**Connect (`GET /auth/strava/redirect` → Strava → `GET /auth/strava/callback`)**

1. `StravaRedirectController` mints a 40-character `state`, stores it in the
   session under `strava.oauth_state`, and redirects to Strava's authorize URL
   with the literal `activity:read_all` scope.
2. `StravaCallbackController` runs a fixed sequence of gates, and **nothing is
   written until the last one passes**:
   1. The expected state is pulled (consumed) from the session first, on every
      path, including cancellation.
   2. `error=access_denied` → redirect to `dashboard` with the flash
      `status` = "Strava authorization was cancelled. Nothing was stored."
   3. State mismatch, or a missing state → `403`.
   4. Granted scope lacks `activity:read_all` → `strava.rejected` (`reason=scope`), 403.
   5. Granted scope contains any write scope (`*:write`, `write`) →
      `strava.rejected` (`reason=write_scope`), 403.
   6. No `code` → `strava.rejected` (`reason=exchange`), 400, no HTTP call.
   7. Code exchange fails (non-2xx, malformed body, unreachable) →
      `strava.rejected` (`reason=exchange`), 503, logged without token data.
   8. Athlete id missing or not on the allowlist → `strava.rejected`
      (`reason=allowlist`), 403.
   9. `ConnectStravaAthlete` upserts the user (by `strava_athlete_id`) in a
      transaction, then `Auth::login($user, remember: true)` and redirect to
      the literal `dashboard` route.

**Every authenticated request (`strava.fresh` middleware on `/`)**

1. `EnsureFreshStravaToken` is a no-op for guests.
2. For a signed-in user it re-checks the allowlist. A user no longer on it is
   signed out (session invalidated, remember-me cookie dropped) and gets
   `strava.rejected` (`reason=allowlist`) with 403.
3. It then calls `EnsureFreshAccessToken`. If that throws
   `StravaReconnectRequiredException` (refresh token rejected, no refresh token,
   undecryptable tokens, lock timeout), the session is ended and the
   `strava.connect` view is rendered with `reconnect: true` — the
   "Your Strava connection expired" banner above the connect card, status 200.

**Token refresh (`EnsureFreshAccessToken`)**

1. A token that is valid beyond `strava.token_refresh_margin_seconds` (default
   300 s) is returned as-is — zero HTTP requests.
2. Otherwise a cache lock `strava:refresh:{user id}` (15 s) serialises the
   refresh; the lock holder re-reads the row, and refreshes only if still needed.
3. The refresh persists **both** the new access token and the rotated refresh
   token, plus the new expiry.
4. `StravaClient` passes `rejectedToken:` after a mid-sync 401 to force one
   refresh, and only if the stored token is still the rejected one.

## Technical design

| Piece | Where | Role |
| --- | --- | --- |
| Routes | `routes/web.php` | `strava.redirect`, `strava.callback` (both `throttle:strava-oauth`); `dashboard` carries `strava.fresh` |
| Rate limiter | `app/Providers/AppServiceProvider.php` | `strava-oauth`: 10 / minute per IP |
| Redirect controller | `app/Http/Controllers/Strava/StravaRedirectController.php` | Mints and stores the state nonce |
| Callback controller | `app/Http/Controllers/Strava/StravaCallbackController.php` | The gate sequence above; the only place a session is created |
| OAuth service | `app/Services/Strava/StravaOAuth.php` | Authorize URL, code exchange, refresh; 5 s connect / 10 s total timeouts |
| Token DTO | `app/Data/StravaTokens.php` | `fromExchangeResponse()` / `fromRefreshResponse()` |
| Allowlist | `app/Support/Strava/AthleteAllowlist.php` | `allows(string $athleteId)`, strict compare |
| Connect action | `app/Actions/Strava/ConnectStravaAthlete.php` | The only write path of the OAuth flow |
| Refresh action | `app/Actions/Strava/EnsureFreshAccessToken.php` | Margin, lock, rotation, undecryptable tokens |
| Guard middleware | `app/Http/Middleware/EnsureFreshStravaToken.php` (alias `strava.fresh`) | Allowlist re-check + token freshness |
| Exceptions | `app/Exceptions/Strava/StravaOAuthException.php`, `StravaReconnectRequiredException.php` | Non-secret messages only |
| Model | `app/Models/User.php`, `…_add_strava_columns_to_users_table` migration | `strava_athlete_id` (unique), encrypted `strava_access_token` / `strava_refresh_token`, `strava_token_expires_at` |
| Views | `resources/views/strava/connect.blade.php`, `strava/rejected.blade.php` | Connect card (and reconnect banner), rejection screens |
| Config | `config/strava.php` | `client_id`, `client_secret`, `redirect_uri`, literal `scope`, literal endpoints, `token_refresh_margin_seconds`, `allowed_athlete_ids` |
| Env vars | `.env` | `STRAVA_CLIENT_ID`, `STRAVA_CLIENT_SECRET`, `STRAVA_REDIRECT_URI`, `STRAVA_ALLOWED_ATHLETE_IDS`, `STRAVA_TOKEN_REFRESH_MARGIN` |
| Tests | `tests/Feature/Strava/StravaConnectFlowTest.php`, `StravaOAuthTest.php`, `ConnectStravaAthleteTest.php`, `EnsureFreshAccessTokenTest.php`, `ReconnectStateTest.php`, `tests/Unit/Support/Strava/AthleteAllowlistTest.php`, `tests/Feature/Config/StravaConfigTest.php` | |

`User` keeps the Strava columns out of `$fillable` and hides both tokens. The
email column is `NOT NULL UNIQUE` but Strava exposes no email under
`activity:read_all`, so a new athlete gets `athlete-{id}@strava.invalid`
(RFC 2606 reserved TLD — nothing can ever be sent there).

## Architectural choices

| Choice | Alternatives considered | Why this one |
| --- | --- | --- |
| OAuth hand-rolled on the HTTP client | Laravel Socialite | Strava is OAuth2 code flow only; Socialite's `league/oauth1-client` dependency would force Guzzle 8 down to Guzzle 7 |
| Allowlist of Strava athlete ids as the only access control | Password login, registration, roles | One owner, one screen; Strava already authenticates — the app only has to decide *which* athlete |
| Allowlist re-checked on every authenticated request | Checked once at connect | The session and remember-me cookie outlive a `.env` edit by weeks; removal must take effect immediately |
| Scope and OAuth endpoints are config literals | `env()` lookups | Read-only is a product invariant (FR-001); pointing the token URL elsewhere would leak the client secret |
| Write scopes refused on the callback | Trust the requested scope | The response scope is attacker-controlled (hand-edited consent URL); read-only must be a property of what is stored |
| State consumed first on every path | Consume only on success | An early return would leave a live, replayable nonce |
| Separate `ConnectStravaAthlete` action, unaware of the allowlist | One long callback method | "Allowlist first, persistence second" is structural, and the "nothing persisted on rejection" criterion is assertable by counting rows |
| Literal `dashboard` redirect after login | `redirect()->intended()` | The intended URL is session data; an absolute one is an open redirect |
| Cache lock around refresh | No lock | Strava retires the old refresh token on refresh; a lost race persists a dead token and forces a full re-authorization |
| `rejectedToken` argument for forced refresh | `bool $force` | A plain force would re-refresh after the lock and retire the token the race winner just stored |
| Session teardown in the middleware | `render()` on the exception | Rendering a reconnect screen while signed in with a dead token loops; teardown belongs where the request is |

## Key decisions & invariants

- **Invariant — nothing is written before the allowlist passes.** _Breaks if:_ a
  rejected athlete leaves a `users` row or tokens behind.
- **Invariant — the allowlist fails closed.** `config/strava.php` keeps only
  all-digit, non-empty entries; `allows()` compares strictly. _Breaks if:_ a loose
  compare or a coerced `env()` value (`true` → `"1"`) authorises someone.
- **Invariant — both halves of a refreshed token pair are persisted.** _Breaks if:_
  only the access token is stored; the next refresh then fails.
- **Invariant — tokens never reach a log line or an exception message.** Messages
  carry an HTTP status, a missing key name, or an athlete id only.
- **Invariant — undecryptable tokens are a reconnect, not a 500.** Rotating
  `APP_KEY` must lead to the reconnect path.
- **Invariant — the OAuth routes are throttled.** They are the only unauthenticated
  entry points that do work.

## Extension points & gotchas

- Query parameters on the callback are attacker-supplied: read them with the
  private `queryString()` helper — `?state[]=x` arrives as an array and a plain
  `(string)` cast becomes a 500.
- `Auth::login()` already migrates the session id; do not regenerate it again.
- Any new Strava API caller must obtain its token through `EnsureFreshAccessToken`,
  never by reading `strava_access_token` directly.
- `<meta name="referrer" content="same-origin">` in the layout keeps the
  authorization code in the callback URL from leaking through a referrer.

## Related

- Specs: `US-002`, `US-003`, `US-007`
- Plans: `.larapilot/plans/US-002-plan.yaml`
- Review: `.larapilot/docs/review/US-002.md`
- Other domains: `activity-sync.md`, `heatmap-dashboard.md`
