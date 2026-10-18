# Red-Team Report — v2.0.1

**Assessor:** Oliver · **Date:** 2026-10-18 · **Target:** tag v2.0.1, fresh `composer install --no-dev --optimize-autoloader` in a throw-away worktree, `.env` copied from `.env.example` (`APP_ENV=local`, `APP_DEBUG=true`), served by PHP's built-in server (no staging exists) · **Exposure:** private VPS

## Summary

v2.0.1 changes no application code since v2.0.0: only `composer.json`, `composer.lock`, `package-lock.json`, `README.md`, `CHANGELOG.md` and a new unit test. The v2.0.0 code review (`red-team-v2.0.0.md`) therefore still holds, and this pass re-tests the deployment surface the release was cut for.

The v2.0.0 High is closed. Even under the unsafe `.env.example` defaults, a `--no-dev` install carries no developer tooling and every probed path answers 404. **0 High, 2 Medium, 3 Low** (all carried over from v2.0.0, none new).

## Re-test — developer tooling on a `--no-dev` install

- `vendor/andreapollastri`, `vendor/laravel/boost`, `vendor/laravel/mcp`: absent.
- `php artisan route:list --path=larapilot`: no matching route. No Boost or MCP route. No `larapilot:*` command defined.
- Unauthenticated HTTP, no session:

| Request | Status |
| --- | --- |
| `GET /larapilot` | 404 |
| `GET /larapilot/api/specs`, `/larapilot/api/board`, `/larapilot/api/versions` | 404 |
| `GET /larapilot/files/raw/project/.env` | 404 |
| `GET /larapilot/database-export/sql` | 404 |
| `GET /larapilot/laravel/dumps` | 404 |
| `GET /_boost/browser-logs`, `POST /_boost/browser-logs` | 404 |
| `GET /mcp` | 404 |
| `GET /.env`, `GET /database/database.sqlite` | 404 |
| `GET /up` | 200 (application boots) |

## Findings still open (carried over from v2.0.0)

### [Medium] symfony/http-foundation 8.1.6 — CVE-2026-108082, ReDoS via the Cache-Control header

- **Route / file:** `composer.lock` · every route (the header is parsed before routing and throttling)
- **PoC:** not exercised; `composer audit` reports PKSA-xmwy-rmx5-m7h4 against 8.1.6 (fixed in 8.1.7). OSV.dev does not list it, so `vendor-audit --gate` passes.

### [Medium] `.env.example` defaults are development values

- **Route / file:** `.env.example:2,4,19,21,37` — `APP_ENV=local`, `APP_DEBUG=true`, `LOG_STACK=single`, `LOG_LEVEL=debug`, `SESSION_SECURE_COOKIE=false`, `APP_URL=http://localhost`
- **PoC:** a host built from the example file renders debug error pages and keeps one unrotated log. The Boost part of the v2.0.0 finding is closed: Boost is no longer installed with `--no-dev`.

### [Low] The Garmin import review page has no rate limit

- **Route / file:** `routes/web.php:44`

### [Low] No security headers; the import confirmation page can be framed

- **Route / file:** `bootstrap/app.php`

### [Low] No sign-out; a remember-me cookie is always issued

- **Route / file:** `app/Http/Controllers/Strava/StravaCallbackController.php:124`
