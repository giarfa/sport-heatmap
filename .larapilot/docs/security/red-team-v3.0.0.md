# Red-Team Report — v3.0.0

**Assessor:** Oliver · **Date:** 2026-10-26 · **Target:** tag v3.0.0, fresh `composer install --no-dev --optimize-autoloader` and `npm ci && npm run build` in a throw-away worktree, `.env` copied from `.env.example` (`APP_ENV=production`, `APP_DEBUG=false`), every migration run on an empty SQLite file, served by PHP 8.4's built-in server (no staging exists; production is suspended) · **Exposure:** private VPS behind Cipi basic auth

## Summary

v3.0.0 adds a username/password sign-in (US-020) and removes Strava (US-021). The attack surface shrinks: no OAuth callback, no outbound HTTP, no scheduled job, no public page but `/login` and `/up`. **0 Critical, 0 High, 0 Medium, 2 Low, 2 Info.** The only High in the release gate is a build-tool advisory (vite dev server), reported by Lars from the vendor audit — not reachable on a deployed host.

## Access control & surface

| # | Request (signed out) | Expected | Observed |
| --- | --- | --- | --- |
| 1 | `GET /`, `/import/garmin`, `/import/garmin/review`, `/import/garmin/summary`, `/password/change` | 302 → `/login` | 302 → `/login` |
| 2 | `GET /login`, `/up` | 200 | 200 |
| 3 | `GET /strava/connect`, `/strava/callback`, `/larapilot`, `/.env`, `/storage/logs/laravel.log`, `/database/database.sqlite` | 404 | 404 |
| 4 | `POST /login`, `/logout`, `/import/garmin`, `/import/garmin/confirm`, `/import/garmin/cancel`, `PUT /password/change` with no CSRF token | 419 | 419 |
| 5 | `route:list` on the `--no-dev` build | 12 app routes, no `strava`, no dev tooling | As expected |
| 6 | Response headers on `/login` | `frame-ancestors 'none'`, `X-Frame-Options: DENY`, `nosniff`, `Referrer-Policy: same-origin`; session cookie `HttpOnly`, `SameSite=Lax` | As expected (`Secure` is set by `SESSION_SECURE_COOKIE=true` on the host) |

## Authentication (US-020)

| # | Probe | Expected | Observed |
| --- | --- | --- | --- |
| 1 | Unknown username | Generic refusal | 302 → `/login` |
| 2 | `giarfa` with an empty password, before a password exists | Signed in, forced to change | 302 → `/`, then every page → `/password/change` |
| 3 | Import while the change is pending | Blocked | 302 → `/password/change` |
| 4 | New password of 5 characters | Refused | 302 back to the form |
| 5 | New password of 21 characters | Accepted | 302 → `/`, dashboard 200 |
| 6 | Empty password from a second browser after the change | Refused | 302 → `/login` |
| 7 | 7 wrong passwords, then the right one | Throttled after 5; message names the wait | "Too many sign-in attempts. Try again in 58 seconds." — the right password is refused while throttled |
| 8 | `username[]=giarfa` (array) | Refused, no 500 | 302 → `/login` |
| 9 | `POST /logout`, then replay the session | Guest | 302 → `/login` |

## Garmin import

| # | Probe | Expected | Observed |
| --- | --- | --- | --- |
| 1 | PHP payload uploaded as `shell.php` and as `shell.csv` | Refused | 302 back to the form, nothing written |
| 2 | Filename `../../public/x.php` | Nothing written outside storage | Refused; `public/` unchanged |
| 3 | 6 MB file | Refused before parsing | 413 |
| 4 | Binary garbage | Refused, no 500 | 302 back to the form |
| 5 | Title `<script>alert(1)</script><img src=x onerror=alert(2)>` | Escaped everywhere | Escaped in the review and in the day detail (`<h3 class="run-name">&lt;script&gt;…`); no raw tag in any page |
| 6 | Row with `Activity Type` `=cmd\|'/C calc'!A0` and title `=HYPERLINK(1)` | Not a run, skipped | Skipped (not counted as a new run) |
| 7 | Confirm the review, then replay the same confirm | Applied once | First 302 → summary; replay 302 → form, one row stored |
| 8 | Day detail links | No external link | None |

## Findings

### [Low] Empty-password first sign-in stays an account-takeover window until the owner signs in

- **Route:** `POST /login` with `username=giarfa`, `password=` — right after the deploy that runs `add_username_to_users_table`.
- **PoC:** probe 2 above — whoever reaches the instance first sets the password.
- **Severity rationale:** accepted risk on record (2026-10-18). On the host, Cipi basic auth is enabled for `heatmap` (user `owner`), so only the basic-auth holder reaches `/login`.
- **To Lars:** sign in and set the password immediately after the deploy.

### [Low] The Strava-removal migration flushes the whole cache once

- **Location:** `database/migrations/2026_10_26_210040_remove_strava_from_schema.php` — `Cache::flush()`.
- **Effect:** the `database` cache store also holds the throttle counters; they reset once at deploy. No security impact beyond a one-off reset of the login and import limits.

### [Info] Leftover Strava keys in the host environment

- `STRAVA_REDIRECT_URI`, `STRAVA_TOKEN_REFRESH_MARGIN`, `STRAVA_SYNC_HOUR`, `STRAVA_SYNC_TIMEZONE` are still set in the `heatmap` env on the host. Nothing reads them after v3.0.0; remove them for hygiene.

### [Info] Cipi scheduler still on

- `cipi schedule status heatmap` → on. v3.0.0 schedules nothing; the cron entry runs `schedule:run` every minute for no work. Turn it off (`cipi schedule off heatmap`).

## Not tested

- The live host: suspended (503) at the time of the pass; production holds no users and no runs.
- TLS configuration of the sslip.io domain (unchanged since v2.0.2).
