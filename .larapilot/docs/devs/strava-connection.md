# Strava connection

| | |
| --- | --- |
| **Status** | Active |
| **Introduced by** | `US-002` |
| **Last updated by** | `US-009` — 2026-10-11 |
| **Owner persona** | 🔗 Matt |

## Purpose

Connects the owner's Strava account to this instance and keeps that connection
usable. It authenticates *a* Strava athlete through OAuth2 (authorization code),
decides whether it is *the* athlete through an allowlist of Strava athlete ids,
stores a read-only token pair encrypted at rest, and refreshes the access token
before any Strava call needs it. The allowlist's order also decides which
athlete the public home page displays; with no allowlisted athlete connected, it
falls back to a stored athlete with activities. If it stops working, nobody can sign in
and the nightly sync can no longer read activities.

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
   undecryptable tokens, lock timeout), the session is ended and the response is
   a redirect to `dashboard` with the flash `strava.reconnect`. The follow-up
   request is a guest one: the public map of the displayed athlete (normally
   the owner themselves) with the "Your Strava connection expired" banner, once
   — a reload no longer shows it.

**Displayed athlete (`ResolveDisplayedAthlete`, signed-out visitors)**

1. Empty allowlist → skip to the fallback (step 4).
2. One query: `select id, strava_athlete_id from users where
   strava_athlete_id in (…allowlist…)`.
3. Walk the allowlist **in configured order** and return the first id that has a
   user row — a connected allowlisted athlete always wins.
4. Fallback (US-009, FR-017): `select id, strava_athlete_id from users where
   exists (activities) order by id limit 1` — the stored athlete with the
   lowest `users.id` who has at least one activity, Strava or Garmin. None →
   `null` (the dashboard then renders the connect screen).
5. Token state is ignored and token columns are never loaded: an owner with a
   dead refresh token is still displayed, and so is a fallback athlete.

Query cost: allowlist hit → 1; empty allowlist → 1; non-empty allowlist with
nobody connected → 2.

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
| Allowlist | `app/Support/Strava/AthleteAllowlist.php` | `athleteIds()` in configured order; `allows(string $athleteId)`, strict compare |
| Displayed athlete | `app/Support/Strava/ResolveDisplayedAthlete.php` | Read-only: first connected allowlisted athlete, else lowest-id stored athlete with an activity; ≤ 2 queries, selects `id` and `strava_athlete_id` only |
| Connect action | `app/Actions/Strava/ConnectStravaAthlete.php` | The only write path of the OAuth flow |
| Refresh action | `app/Actions/Strava/EnsureFreshAccessToken.php` | Margin, lock, rotation, undecryptable tokens |
| Guard middleware | `app/Http/Middleware/EnsureFreshStravaToken.php` (alias `strava.fresh`) | Allowlist re-check + token freshness |
| Exceptions | `app/Exceptions/Strava/StravaOAuthException.php`, `StravaReconnectRequiredException.php` | Non-secret messages only |
| Model | `app/Models/User.php`, `…_add_strava_columns_to_users_table` migration | `strava_athlete_id` (unique), encrypted `strava_access_token` / `strava_refresh_token`, `strava_token_expires_at` |
| Views | `resources/views/strava/connect.blade.php`, `strava/rejected.blade.php` | Connect card (and reconnect banner), rejection screens |
| Config | `config/strava.php` | `client_id`, `client_secret`, `redirect_uri`, literal `scope`, literal endpoints, `token_refresh_margin_seconds`, `allowed_athlete_ids` |
| Env vars | `.env` | `STRAVA_CLIENT_ID`, `STRAVA_CLIENT_SECRET`, `STRAVA_REDIRECT_URI`, `STRAVA_ALLOWED_ATHLETE_IDS`, `STRAVA_TOKEN_REFRESH_MARGIN` |
| Tests | `tests/Feature/Strava/StravaConnectFlowTest.php`, `StravaOAuthTest.php`, `ConnectStravaAthleteTest.php`, `EnsureFreshAccessTokenTest.php`, `ReconnectStateTest.php`, `ResolveDisplayedAthleteTest.php`, `tests/Unit/Support/Strava/AthleteAllowlistTest.php`, `tests/Feature/Config/StravaConfigTest.php` | |

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
| Displayed athlete = first **connected** id in allowlist order | Order by `users.id`; a separate `STRAVA_PUBLIC_ATHLETE_ID` | The allowlist is the one place the owner states who comes first; `users.id` order would display whoever connected first, and a second env var could name an athlete who is not allowed in |
| No allowlisted athlete connected → the lowest `users.id` with at least one activity (US-009) | Connect screen (US-007 behaviour); the athlete with the most recent activity; a new `STRAVA_PUBLIC_ATHLETE_ID` env var | The connect screen showed nothing although data was stored; "most recent" would flip the displayed athlete every time someone runs; a new env var is one more thing to forget. The owner accepted that this can display an athlete removed from the allowlist (PRD `### Privacy & legal`, "Accepted risk — fallback display") |
| Resolver selects `id, strava_athlete_id` only | Load the full model | No guest request can decrypt, refresh, or leak a token, and undecryptable tokens cannot 500 the public page |
| Write scopes refused on the callback | Trust the requested scope | The response scope is attacker-controlled (hand-edited consent URL); read-only must be a property of what is stored |
| State consumed first on every path | Consume only on success | An early return would leave a live, replayable nonce |
| Separate `ConnectStravaAthlete` action, unaware of the allowlist | One long callback method | "Allowlist first, persistence second" is structural, and the "nothing persisted on rejection" criterion is assertable by counting rows |
| Literal `dashboard` redirect after login | `redirect()->intended()` | The intended URL is session data; an absolute one is an open redirect |
| Cache lock around refresh | No lock | Strava retires the old refresh token on refresh; a lost race persists a dead token and forces a full re-authorization |
| `rejectedToken` argument for forced refresh | `bool $force` | A plain force would re-refresh after the lock and retire the token the race winner just stored |
| Session teardown in the middleware | `render()` on the exception | Rendering a reconnect screen while signed in with a dead token loops; teardown belongs where the request is |
| Dead token → redirect to `/` + flash (Post/Redirect/Get) | Continue with `$next` as a guest; render the connect-only screen | Continuing couples the middleware to a route that happens to serve guests; the connect-only screen hid the owner's own public map (US-007). The flash lands in the freshly regenerated session and does not repeat on reload |

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
- **Invariant — a connected allowlisted athlete is always displayed ahead of the
  fallback, and the fallback selects no token column.** An athlete removed from
  the allowlist can be displayed only while no allowlisted athlete has connected
  (accepted risk, US-009). _Breaks if:_ the fallback runs before the allowlist
  walk, or its `select` is widened.
- **Invariant — the allowlist-removal branch never redirects.** It ends the
  session and answers 403 with `strava.rejected` in the same response; only the
  dead-token branch redirects. _Breaks if:_ both branches are merged — a removed
  athlete would land on a public map instead of the rejection.
- **Invariant — guests never reach `EnsureFreshAccessToken`.** The resolver loads
  no token column, so a guest request cannot trigger a refresh or an HTTP call.
- **Invariant — the OAuth routes are throttled.** Since US-007 they are no longer
  the only unauthenticated routes that do work: the public `/` runs the
  displayed-athlete queries, the sync-status queries, possibly a grid rebuild, and
  a session write per cookieless visit. `/` is deliberately left unthrottled — a
  rate limiter would add a cache write to every request on the `database` cache
  driver — and is an accepted trade-off for a single-owner instance; revisit it
  (throttle, or a non-database session driver) if the link is shared widely.

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
- Plans: `.larapilot/plans/US-002-plan.yaml`, `US-007-plan.yaml`
- Review: `.larapilot/docs/review/US-002.md`, `US-007.md`
- Other domains: `activity-sync.md`, `heatmap-dashboard.md`
