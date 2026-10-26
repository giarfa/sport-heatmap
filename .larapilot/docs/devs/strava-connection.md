# Strava connection

| | |
| --- | --- |
| **Status** | Active |
| **Introduced by** | `US-002` |
| **Last updated by** | `US-020` — 2026-10-26 |
| **Owner persona** | 🔗 Matt |

## Purpose

Connects the owner's Strava account to this instance and keeps that connection
usable. Since `US-020` it is **no longer a sign-in**: the signed-in owner (see
`owner-authentication.md`) authorizes Strava through OAuth2 (authorization code),
an allowlist of Strava athlete ids decides whether the returned athlete is
allowed, and a read-only token pair is stored encrypted on the owner's row. The
access token is refreshed before any Strava call needs it. There is no
displayed-athlete selection any more: the dashboard always shows the signed-in
owner. If it stops working, the nightly sync can no longer read activities.
(`US-021` removes this domain.)

## Functional flow

**Connect (`GET /auth/strava/redirect` → Strava → `GET /auth/strava/callback`)**

1. `StravaRedirectController` mints a 40-character `state`, stores it in the
   session under `strava.oauth_state`, and redirects to Strava's authorize URL
   with the literal `activity:read_all` scope.
   Both routes sit behind `auth` + `password.set`, so a signed-out request goes to
   `/login` before reaching Strava.
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
   9. The returned athlete id already belongs to another `users` row →
      `strava.rejected` (`reason=taken`), 403, nothing written (it would break
      the unique index and hand the owner someone else's identity).
   10. `ConnectStravaAthlete($owner, $tokens)` writes the athlete id and the
      encrypted tokens onto the **signed-in owner** and redirects to the literal
      `dashboard` route. It creates no user, starts no session and sets no
      remember-me cookie.

**Sign out** moved to `owner-authentication.md` (`POST /logout`, redirects to
`/login`); it still never touches the Strava token columns — signing out is not
deauthorisation (FR-012), and the nightly `strava:sync` runs on the stored tokens
without a session.

**The `strava.fresh` guard (`EnsureFreshStravaToken`) is on no route since
`US-020`.** Its allowlist check would 403 the owner `giarfa` when no Strava id is
stored, and neither the dashboard nor the Garmin import calls Strava. The class
and the alias stay in place for `US-021` to delete; the behaviour it implemented
(allowlist re-check on every request, dead-token sign-out with the
`strava.reconnect` banner) no longer exists, and its feature test was removed.
`EnsureFreshAccessToken` (below) is still used by the sync.

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
| Routes | `routes/web.php` | `strava.redirect`, `strava.callback` (both `throttle:strava-oauth`, in the `auth` + `password.set` group) |
| Rate limiter | `app/Providers/AppServiceProvider.php` | `strava-oauth`: 10 / minute per IP |
| Redirect controller | `app/Http/Controllers/Strava/StravaRedirectController.php` | Mints and stores the state nonce |
| Callback controller | `app/Http/Controllers/Strava/StravaCallbackController.php` | The gate sequence above; attaches tokens to the signed-in owner, creates no session |
| OAuth service | `app/Services/Strava/StravaOAuth.php` | Authorize URL, code exchange, refresh; 5 s connect / 10 s total timeouts |
| Token DTO | `app/Data/StravaTokens.php` | `fromExchangeResponse()` / `fromRefreshResponse()` |
| Allowlist | `app/Support/Strava/AthleteAllowlist.php` | `athleteIds()` in configured order; `allows(string $athleteId)`, strict compare |
| Connect action | `app/Actions/Strava/ConnectStravaAthlete.php` | The only write path of the OAuth flow: tokens onto the signed-in owner |
| Refresh action | `app/Actions/Strava/EnsureFreshAccessToken.php` | Margin, lock, rotation, undecryptable tokens |
| Guard middleware | `app/Http/Middleware/EnsureFreshStravaToken.php` (alias `strava.fresh`) | On no route since `US-020`; deleted by `US-021` |
| Exceptions | `app/Exceptions/Strava/StravaOAuthException.php`, `StravaReconnectRequiredException.php` | Non-secret messages only |
| Model | `app/Models/User.php`, `…_add_strava_columns_to_users_table` migration | `strava_athlete_id` (unique), encrypted `strava_access_token` / `strava_refresh_token`, `strava_token_expires_at` |
| Views | `resources/views/strava/connect.blade.php`, `strava/rejected.blade.php` | Connect card (and reconnect banner), rejection screens |
| Config | `config/strava.php` | `client_id`, `client_secret`, `redirect_uri`, literal `scope`, literal endpoints, `token_refresh_margin_seconds`, `allowed_athlete_ids` |
| Env vars | `.env` | `STRAVA_CLIENT_ID`, `STRAVA_CLIENT_SECRET`, `STRAVA_REDIRECT_URI`, `STRAVA_ALLOWED_ATHLETE_IDS`, `STRAVA_TOKEN_REFRESH_MARGIN` |
| Tests | `tests/Feature/Strava/StravaConnectFlowTest.php`, `StravaOAuthTest.php`, `ConnectStravaAthleteTest.php`, `EnsureFreshAccessTokenTest.php`, `tests/Unit/Support/Strava/AthleteAllowlistTest.php`, `tests/Feature/Config/StravaConfigTest.php` | |

`User` keeps the Strava columns out of `$fillable` and hides both tokens. The
owner's own `name` and `email` are never overwritten by Strava's.

## Architectural choices

| Choice | Alternatives considered | Why this one |
| --- | --- | --- |
| OAuth hand-rolled on the HTTP client | Laravel Socialite | Strava is OAuth2 code flow only; Socialite's `league/oauth1-client` dependency would force Guzzle 8 down to Guzzle 7 |
| The callback attaches tokens to the signed-in owner instead of signing a Strava athlete in (`US-020`) | Keep Strava as the way in next to the password; create a user per athlete | The password form is the only way to get a session; no route creates users. The allowlist still gates which Strava athlete may attach to the owner, until `US-021` removes Strava |
| An athlete id held by another row is rejected, not merged | Move the id to the owner; delete the other row | Merging users is out of scope for `US-020`; the unique index forbids two holders |
| Scope and OAuth endpoints are config literals | `env()` lookups | Read-only is a product invariant (FR-001); pointing the token URL elsewhere would leak the client secret |
| Write scopes refused on the callback | Trust the requested scope | The response scope is attacker-controlled (hand-edited consent URL); read-only must be a property of what is stored |
| State consumed first on every path | Consume only on success | An early return would leave a live, replayable nonce |
| Separate `ConnectStravaAthlete` action, unaware of the allowlist | One long callback method | "Allowlist first, persistence second" is structural, and the "nothing persisted on rejection" criterion is assertable on the owner's Strava columns |
| Literal `dashboard` redirect after the callback | `redirect()->intended()` | The intended URL is session data; an absolute one is an open redirect |
| Cache lock around refresh | No lock | Strava retires the old refresh token on refresh; a lost race persists a dead token and forces a full re-authorization |
| `rejectedToken` argument for forced refresh | `bool $force` | A plain force would re-refresh after the lock and retire the token the race winner just stored |

## Key decisions & invariants

- **Invariant — signing out is not deauthorisation.** It never reads or writes a
  token column (FR-012). _Breaks if:_ sign-out revokes or deletes tokens — the
  nightly sync then stops until the owner reconnects.
- **Invariant — nothing is written before the allowlist passes.** _Breaks if:_ a
  rejected athlete leaves tokens on the owner's row (or any row).
- **Invariant — the callback creates no user and no session.** _Breaks if:_ it
  calls `Auth::login` or creates a `users` row — the password form would no
  longer be the only way in.
- **Invariant — the allowlist fails closed.** `config/strava.php` keeps only
  all-digit, non-empty entries; `allows()` compares strictly. _Breaks if:_ a loose
  compare or a coerced `env()` value (`true` → `"1"`) authorises someone.
- **Invariant — both halves of a refreshed token pair are persisted.** _Breaks if:_
  only the access token is stored; the next refresh then fails.
- **Invariant — tokens never reach a log line or an exception message.** Messages
  carry an HTTP status, a missing key name, or an athlete id only.
- **Invariant — undecryptable tokens are a reconnect, not a 500.** Rotating
  `APP_KEY` must lead to the reconnect path.
- **Invariant — the OAuth routes are throttled.** They are behind the session,
  but the redirect route is the cheaper one to hammer and neither is ever hit
  more than a handful of times by a legitimate owner.

## Extension points & gotchas

- Query parameters on the callback are attacker-supplied: read them with the
  private `queryString()` helper — `?state[]=x` arrives as an array and a plain
  `(string)` cast becomes a 500.
- Recallers issued before 2.0.2 stay valid until a sign-out cycles `remember_token`; the app no longer creates new ones.
- Any new Strava API caller must obtain its token through `EnsureFreshAccessToken`,
  never by reading `strava_access_token` directly.
- `<meta name="referrer" content="same-origin">` in the layout keeps the
  authorization code in the callback URL from leaking through a referrer.

## Related

- Specs: `US-002`, `US-003`, `US-007`, `US-009`, `US-011`, `US-019`, `US-020`
- Plans: `.larapilot/plans/US-002-plan.yaml`, `US-007-plan.yaml`, `US-009-plan.yaml`, `US-020-plan.yaml`
- Review: `.larapilot/docs/review/US-002.md`, `US-007.md`, `US-009.md`
- Other domains: `owner-authentication.md`, `activity-sync.md`, `heatmap-dashboard.md`
