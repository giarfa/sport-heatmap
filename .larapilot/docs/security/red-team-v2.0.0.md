# Red-Team Report — v2.0.0

**Assessor:** Oliver · **Date:** 2026-10-17 · **Target:** local build of tag v2.0.0 (no staging exists) · **Exposure:** private VPS

## Summary

The application code is solid:

- **OAuth:** the state is single-use and compared with `hash_equals`; write scopes are refused; the allowlist is checked before anything is persisted and re-checked on every request.
- **Injection and XSS:** there are no `{!! !!}` echoes, run names are escaped everywhere, and the JavaScript has no `innerHTML` sinks.
- **Access control and mass assignment:** the athlete is never taken from the request; `?to=` is validated and cannot fill the cache; `user_id` and the Strava columns are not fillable.
- **Other areas:** every POST carries a CSRF token; Strava URLs are fixed literals, so there is no SSRF; sync error text holds only status codes.

The real exposure is deployment configuration. A development tool (the Larapilot dashboard, which pulls in Laravel Boost) is a production dependency, and with the `.env.example` defaults it gives full unauthenticated admin access. Composer also flags two vulnerable packages. **1 High, 2 Medium, 4 Low, 3 Info.**

The code on `develop` is identical to tag `v2.0.0`; only `.larapilot/*` files differ. All probes were GET requests against the local site.

## Findings

### [High] The Larapilot dashboard is a production dependency and is open to anyone when APP_ENV=local

- **Route / file:** `composer.json:13` (`andreapollastri/larapilot` is in `require`) · `vendor/andreapollastri/larapilot/src/Services/Auth/AccessPolicy.php:53-63` · `.env.example:2` (`APP_ENV=local`) · `/larapilot/*`
- **PoC:**
  - The dashboard's access mode depends only on APP_ENV. `production` hides it (404). `local` or `testing` opens it with no sign-in, and the visitor acts as admin. Any other environment requires a sign-in.
  - Locally, with no session, these all returned 200:
    - `GET /larapilot/files/raw/project/.env`: the real `APP_KEY` in plain text.
    - `GET /larapilot/database-export/sql`: a 341 KB dump.
    - `GET /larapilot/logs`
    - `GET /larapilot/api/diagnostics`
  - The dashboard also exposes POST routes for file upload, rename and delete (`/larapilot/files/{root}/upload|delete`) and for user management (`/larapilot/users`).
  - `.env.example` ships with `APP_ENV=local`, and `composer setup` copies it to `.env`.
- **Impact:** a VPS left on `APP_ENV=local` lets anyone inside the perimeter:
  - read `.env` (APP_KEY and the Strava client secret), which lets them decrypt the stored Strava tokens and forge encrypted cookies;
  - dump the database;
  - write files into the project.

  The README's host checklist does not mention the dashboard.
- **Suggested remediation:**
  - Move `andreapollastri/larapilot` to `require-dev` and deploy with `composer install --no-dev`. Otherwise, set `LARAPILOT_DASHBOARD_ROUTE=false` in production.
  - Change `.env.example` to `APP_ENV=production`.
  - Document the Larapilot keys in the README host checklist.

### [Medium] APP_DEBUG=true also turns on Laravel Boost, which adds an unauthenticated, CSRF-exempt endpoint that writes to the logs

- **Route / file:** `vendor/laravel/boost/src/BoostServiceProvider.php:106-146,201-215` · `POST /_boost/browser-logs` · `.env.example:4,21,36`
- **PoC:**
  - Boost is a production dependency, pulled in through Larapilot.
  - It boots when `APP_ENV=local` or `APP_DEBUG=true`. It then registers `POST /_boost/browser-logs` with `withoutMiddleware(VerifyCsrfToken)`, which writes any `logs[].data` payload to the `browser` log channel, and it injects a script into every web page.
  - Other `.env.example` defaults are unsafe too: `LOG_LEVEL=debug`, `LOG_STACK=single` (one file that never rotates), `SESSION_SECURE_COOKIE=false`, `APP_URL=http://localhost`.
- **Impact:** an anonymous client can forge log entries and fill the disk, and the debug error page exposes environment details on any 500.
- **Suggested remediation:**
  - Set `APP_DEBUG=false` and `BOOST_ENABLED=false` in production, and add both to `.env.example`.
  - Use `LOG_STACK=daily` with `LOG_LEVEL=warning`.
  - Set `SESSION_SECURE_COOKIE=true` and an `https` `APP_URL`.

### [Medium] Vulnerable symfony/http-foundation 8.1.6 (CVE-2026-108082, ReDoS via the Cache-Control header)

- **Route / file:** `composer.lock` · `vendor/symfony/http-foundation/HeaderBag.php:151`
- **PoC:** `composer audit` flags versions below 8.1.7. The request's `Cache-Control` header is parsed when the request headers are built, before routing or throttling, so `curl -H 'Cache-Control: <crafted>' /up` reaches the vulnerable code without signing in.
- **Impact:** anyone who can reach the host can tie up PHP workers on a single VPS.
- **Suggested remediation:** `composer update symfony/http-foundation` to 8.1.7 or later, then run `composer audit` again.

### [Low] Vulnerable league/commonmark 2.10.0 (DisallowedRawHtml bypass; quadratic DoS in GFM tables)

- **Route / file:** `composer.lock` (pulled in by `laravel/framework`)
- **PoC:** `composer audit` reports GHSA-97jj-33gv-5xf9 and GHSA-3q6v-r5mr-hxv8. The app's own code renders no Markdown; only the Larapilot dashboard does.
- **Impact:** low, as long as the dashboard is not served.
- **Suggested remediation:** `composer update league/commonmark` to 2.10.2 or later.

### [Low] The import review page has no rate limit and its work grows with rows × stored runs; pending imports bloat the sessions table

- **Route / file:** `routes/web.php:44` (GET `/import/garmin/review`, no throttle) · `app/Support/Garmin/PlanGarminImport.php` (nested loop over rows and stored runs) · `app/Support/Garmin/PendingGarminImport.php` (up to 5,000 parsed rows kept in the session)
- **PoC:**
  1. As a guest, upload a valid 5,000-row CSV spread across the owner's run dates.
  2. Reload `/import/garmin/review` in a loop. Each request re-plans every row against every stored run on those dates, with no rate limit.
  3. Separately, ten uploads a minute from fresh cookie-less sessions each leave a session row of 1 MB or more in SQLite until garbage collection removes it.
- **Impact:** a guest can exhaust CPU and disk. Exposure is limited by the private perimeter.
- **Suggested remediation:**
  - Apply `throttle:garmin-import` to the review route.
  - Cache the plan per token and fingerprint.
  - Index stored runs by date before matching.
  - Keep parsed rows in a short-lived cache entry keyed by the token instead of the session.

### [Low] No security headers; the import confirmation page can be framed

- **Route / file:** `bootstrap/app.php` (no header middleware) · `resources/views/garmin/review.blade.php:105-107`
- **PoC:** no response carries `X-Frame-Options`, CSP `frame-ancestors`, `X-Content-Type-Options` or `Referrer-Policy`. A page inside the perimeter can frame `/import/garmin/review` and trick a user into clicking "Overwrite"; CSRF tokens do not stop this kind of UI redress.
- **Impact:** the owner's data can be overwritten through a trick click.
- **Suggested remediation:** a small middleware, or nginx headers: `frame-ancestors 'none'`, `nosniff`, `Referrer-Policy: same-origin`, and HSTS once on HTTPS.

### [Low] There is no sign-out, and a remember-me cookie is always issued

- **Route / file:** `app/Http/Controllers/Strava/StravaCallbackController.php:124` (`remember: true`) · `routes/web.php` (no logout route)
- **PoC:** sign in on a shared device. Nothing in the UI or the routes ends the session, and the remember-me cookie (Laravel's default lifetime of about 400 days) signs the user back in.
- **Impact:** a lost or shared device keeps owner access until the allowlist is edited or the app is revoked on Strava.
- **Suggested remediation:** a CSRF-protected `POST /logout` that clears the session and the remember cookie, or stop issuing the remember cookie.

### [Info] A rejected athlete's Strava authorization is never revoked

- **Route / file:** `StravaCallbackController.php:105-110`
- **Detail:** a non-allowlisted athlete's tokens are discarded without being stored, which is correct, but the grant stays live on Strava's side. Optionally call Strava's `/oauth/deauthorize` endpoint.

### [Info] Trusted proxies are not configured

- **Route / file:** `bootstrap/app.php`
- **Detail:** this is correct while nginx terminates TLS on the same host. Behind a VPN gateway that does NAT, every client shares one rate-limit bucket. Never use `trustProxies(at: '*')` without restricting the source.

### [Info] Accepted risks, recorded in the PRD and not re-raised

- The Garmin import is open to guests, which includes overwriting the displayed athlete's Strava runs.
- The fallback athlete is displayed publicly.
- Heart rate is public.
- The Strava API Agreement display clause.
- SECURITY.md and security.txt are deferred while the instance is private.

Related checks that came back fine:

- `/up` is public and harmless.
- `/storage/{path}` needs a signed URL (403).
- `.env` is not served from `public/` (404).
- Sync failure reasons shown to visitors contain only static text, the HTTP status and Strava's rate-limit header.
- Guests do not see stored-run details on the review page.
- Formula injection does not apply, because there is no CSV export.
