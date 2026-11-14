# Red-Team Report — v3.1.0

**Assessor:** Oliver · **Date:** 2026-11-14 · **Target:** `release/3.1.0` at `fdde030`, fresh `composer install --no-dev --optimize-autoloader` and `npm ci && npm run build` in a throw-away worktree, `.env` from `.env.example` (`APP_ENV=production`, `APP_DEBUG=false`, `GARMIN_API_KEY` empty), every migration run on an empty SQLite file, served by PHP 8.4's built-in server (no staging exists) · **Exposure:** Cipi VPS on an `sslip.io` hostname; Cipi basic auth is off since 2026-10-26, so the app's sign-in is the only gate

## Summary

v3.1.0 adds the Garmin Connect sync (US-022): one new route, `POST /sync/garmin`, a daily scheduled command, and the first outbound HTTP since v3.0.0. It also adds 27 extended run metrics read from the CSV and the API (US-023). **0 Critical, 0 High, 0 Medium, 3 Low, 3 Info.** No live Garmin call was made: the API client is covered by the suite with a faked HTTP layer, including the proof that no key or token reaches a response.

## Access control & surface

| # | Request | Expected | Observed |
| --- | --- | --- | --- |
| 1 | `GET /` signed out | 302 → `/login` | 302 → `/login` |
| 2 | `POST /sync/garmin` signed out, no CSRF token | 419 | 419 |
| 3 | `POST /sync/garmin` signed out, valid CSRF token | 302 → `/login`, nothing called | 302 → `/login` |
| 4 | `GET /sync/garmin` | 405, a link or prefetch cannot start a sync | 405 |
| 5 | `POST /sync/garmin` signed in, no CSRF token | 419 | 419 |
| 6 | `POST /sync/garmin` signed in, no key, 4 times in a minute | 302 "not configured" ×3, then throttled | 302, 302, 302, 429 |
| 7 | Sync now button with no key | Not rendered | Not rendered |
| 8 | `route:list` on the `--no-dev` build | 13 app routes, the new one is `sync/garmin` | As expected |
| 9 | `/.env`, `/database/database.sqlite`, `/storage/framework/cache/data`, `/larapilot` | 404 | 404 |
| 10 | Response headers | `frame-ancestors 'none'`, `X-Frame-Options: DENY`, `nosniff`, `Referrer-Policy: same-origin`; session cookie `Secure`, `HttpOnly`, `SameSite=Lax` | As expected |
| 11 | `GARMIN_API_KEY`, `ApiKey`, `Bearer` in the dashboard HTML | Absent | Absent |

## Outbound HTTP (US-022) — code review

| # | Check | Observed |
| --- | --- | --- |
| 1 | Can configuration or input steer the request to another host (SSRF)? | No. `config/services.php` keeps the host and both paths as literals; only the key comes from the environment. No request parameter reaches the client. |
| 2 | Where does the 24-hour access token live? | In the `file` cache store, never the database; never logged. |
| 3 | What does a failure expose? | `GarminApiException` carries a cause from a closed list and fixed text; no key, token, response body, or URL reaches a message, a log line, or the page. |
| 4 | Two syncs at once (Sync now + schedule)? | A lock refuses the second ("already running"); the throttle allows 3 a minute per user. |

## Extended metrics (US-023)

| # | Probe | Expected | Observed |
| --- | --- | --- | --- |
| 1 | CSV row with `1e999`, `NaN`, `INF`, `1e308`, `-168`, `999999999999`, `<script>alert(1)</script>`, `=cmd\|'/C calc'!A0` in extended columns | Row accepted, each bad value stored empty, no 500 | Imported; every hostile metric `NULL` |
| 2 | Clean row in the same file | Values stored, units normalised | `calories 812`, `max_heart_rate 163`, `aerobic_training_effect 3.8`, `best_pace 280`, `stride 104 cm`, `TSS 48.3`, `min_elevation -12` |
| 3 | Title `<img src=x onerror=alert(1)>` | Escaped on review and dashboard | No raw tag on either page |
| 4 | Latitude/longitude stored | Never | No such column |
| 5 | Re-import and replayed confirm | Applied once | Covered by `GarminImportEnrichmentTest`; the probe's replay was refused (419) and the row count stayed 2 |

## Findings

### [Low] Sync now runs the sync inside the request

- **Route:** `POST /sync/garmin` (`SyncGarminNowController`, `ignore_user_abort(true)`).
- **Effect:** the first full-history read can hold a PHP-FPM worker for a long time, and it continues after the tab closes. Only the signed-in owner can start it; throttle (3/min) and the sync lock bound it.
- **To Lars:** accepted as designed for a single-owner instance; run the first full read with `php artisan garmin:sync` on the host instead of the button if it is slow.

### [Low] `location_name` is text from the API, stored unescaped

- **Location:** `activities.location_name` (US-023).
- **Effect:** nothing displays it today. Any future view must print it with `{{ }}`, never `{!! !!}`.

### [Low] Personal API key has a fixed life (30 days to one year)

- **Location:** `GARMIN_API_KEY` in the host `.env`.
- **Effect:** when it expires the dashboard shows "Garmin key rejected — renew it"; no data is lost. The owner renews it at connect.garmin.com and updates the host env.

### [Info] Session payload grows with the extended columns

- A 5,000-row upload keeps a few MB in the session until confirm; bounded by the 5 MB and 5,000-row caps already in place.

### [Info] `X-Powered-By` seen on the built-in server

- Came from `php -S`. On the host, nginx/PHP-FPM decides it; harmless, worth `expose_php = Off` when convenient.

### [Info] First deploy with outbound traffic

- The host must reach `personal.connectapi.garmin.com:443`. Nothing else is called.
