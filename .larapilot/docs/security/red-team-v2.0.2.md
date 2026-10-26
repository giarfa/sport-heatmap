# Red-Team Report — v2.0.2

**Assessor:** Oliver · **Date:** 2026-10-18 · **Target:** tag v2.0.2, fresh `composer install --no-dev --optimize-autoloader` and `npm ci && npm run build` in a throw-away worktree, `.env` copied from the new `.env.example` (`APP_ENV=production`, `APP_DEBUG=false`), served by PHP 8.4's built-in server (no staging exists) · **Exposure:** private VPS

## Summary

v2.0.2 closes all five findings carried from v2.0.1 (2 Medium, 3 Low). This pass re-tests each one on the release tag, and runs the session re-tests handed over by the US-019 review. **0 Critical, 0 High, 0 Medium, 1 Low (new, documented), all v2.0.1 findings closed.**

## Re-tests — v2.0.1 findings

| Finding (v2.0.1) | Re-test on v2.0.2 | Result |
| --- | --- | --- |
| [Medium] `symfony/http-foundation` CVE-2026-108082 | `composer audit --locked`: no advisory. `vendor-audit --gate` (OSV.dev, 231 components): PASS | Closed |
| [Medium] `.env.example` development defaults | A host built from the file answers a server error with the generic "Server Error" page, no stack trace; the error lands in `storage/logs/laravel-YYYY-MM-DD.log` at `production.ERROR` | Closed |
| [Low] Garmin import review not throttled | 12 × `GET /import/garmin/review` from one client: 10 × 302, then 429, 429 | Closed |
| [Low] No security headers / framing | `GET /` and an unrouted `GET /nope` (404) both carry `Content-Security-Policy: frame-ancestors 'none'`, `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: same-origin`; HSTS absent on plain HTTP as designed | Closed |
| [Low] No sign-out; remember-me cookie always issued | See the session re-tests below | Closed |

## Session re-tests (US-019 handoff)

A signed-in session and a pre-2.0.2 recaller were minted for an allowlisted athlete with the app's own key, then exercised over HTTP:

| # | Request | Expected | Observed |
| --- | --- | --- | --- |
| 1 | `GET /` with the owner's session cookie | "Sign out" shown | Shown |
| 2 | `POST /logout` with the session and its CSRF token | 302 → `/` | 302 → `/` |
| 3 | Replay the same session cookie after sign-out | Guest | Guest |
| 4 | `GET /logout` | Not routed | 405 |
| 5 | `POST /logout` with no CSRF token | 419 | 419 |
| 6 | `GET /` with only a pre-2.0.2 `remember_web_*` cookie | Signed in (known, below) | Signed in; no new remember cookie issued |
| 7 | Sign out from that recaller session, then replay the recaller | Guest | Guest |
| 8 | Stored Strava tokens after sign-out | Kept | Athlete id, access and refresh tokens, expiry unchanged |

The callback calls `Auth::login($user)` with no `remember` (`app/Http/Controllers/Strava/StravaCallbackController.php:130`); `StravaConnectFlowTest` asserts the callback response sets no recaller.

## Developer tooling on a `--no-dev` install

`GET /larapilot`, `/larapilot/api/specs`, `/_boost/browser-logs`, `/mcp`, `/.env`, `/database/database.sqlite`: 404. `GET /up`: 200.

## Findings

### [Low] Recallers issued before 2.0.2 stay valid until the owner signs out once

- **Route / file:** `app/Http/Controllers/Strava/StravaCallbackController.php:130` (sign-in no longer touches `remember_token`)
- **PoC:** re-test 6 above — a browser that signed in under v2.0.1 or earlier still carries a ~400-day `remember_web_*` cookie, and it signs that browser back in after the session expires.
- **Closes when:** the owner signs out once (re-test 7), or `remember_token` is cleared for every user at deploy, which invalidates every outstanding recaller at once.

## Note

The first request on the release tag answered 500 until `npm run build` ran: the Vite manifest is not committed. The deploy must build the assets.
