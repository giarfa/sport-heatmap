## BUG-20261011-home-page-cached-grid-500

- **Reported:** 2026-10-11
- **Severity:** High
- **Environment:** Local (Herd, `CACHE_STORE=database`)
- **Summary:** `GET /` returns 500 whenever the athlete's heatmap grid is already cached — every visit after the first within the cache TTL.
- **Steps to reproduce:** 1. `CACHE_STORE=database` (the `.env.example` default). 2. Open `/` once — the grid is built and cached, 200. 3. Open `/` again — 500.
- **Expected / Actual:** Expected the dashboard with 200 / Actual `TypeError`: `HeatmapGridCache::remember()` must return `HeatmapGrid`, `__PHP_Incomplete_Class` returned.
- **Root cause:** `config/cache.php` sets `serializable_classes => false` (Laravel 13 default), so the database store refuses to unserialize the cached `HeatmapGrid` DTO and its nested `WeekRow`/`DayCell`/`LegendRow`/`DayCellState`/`CarbonImmutable`. The test suite runs the `array` store with `serialize => false`, so no test ever round-tripped the grid through serialization.
- **Promise broken:** FR-004 "The dashboard renders a fixed grid of 16 week-rows by 7 weekday-columns"; US-009 criterion "a signed-out `GET /` returns 200 and renders that athlete's grid…"
- **Logs:** `TypeError` at `app/Support/Heatmap/HeatmapGridCache.php:28` ×1, last 2026-10-11 13:40:23 (local); reproduced with two requests → 500, 500
- **Affected spec:** US-004 (DONE — introduced the grid cache), US-009 (DONE)
- **Routed to:** spec-add US-010
- **Security:** yes — Lars tagged: the fix must allowlist only the app's own grid classes, never `serializable_classes => true`
- **Occurrences:** 2026-10-11 Local

## BUG-20261017-last-sunday-missing

- **Reported:** 2026-10-17
- **Severity:** High
- **Environment:** Local (Herd, SQLite)
- **Summary:** A run on the last day (Sunday) of a heatmap window is missing from that window's cell, run detail, week total, weekly trend, and intensity calibration.
- **Steps to reproduce:** 1. Have a run on a Sunday (local data: 2026-10-11, 17 507 m). 2. Open the window ending that Sunday — `/?to=2026-10-11`, or Previous from the current window. 3. The Sunday cell shows a 0 km rest day. Same on the current window when today is Sunday.
- **Expected / Actual:** Expected the Sunday cell to carry 17.5 km and its run detail / Actual 0 km, no detail.
- **Root cause:** `activity_date` is cast `date`, so Eloquent stores `2026-10-11 00:00:00`; `DailyRunningDistance` and `DailyRuns` bound the window with `toDateString()` (`'2026-10-11'`), and SQLite's text comparison `'2026-10-11 00:00:00' <= '2026-10-11'` is false — the window's last day falls out. Reproduced in tinker against the local data: both readers return no entry for 2026-10-11.
- **Promise broken:** FR-004 "Each cell represents one calendar day and carries that day's total running distance."
- **Logs:** nothing logged — the query silently returns fewer rows
- **Affected spec:** US-004 (DONE — introduced the bounds), US-012 (DONE — copied them into `DailyRuns`), US-013 (DONE — exposes it on every past window)
- **Routed to:** spec-add US-015 (release 2.0.0, found in v0.1.0)
- **Security:** no
- **Occurrences:** 2026-10-17 Local
