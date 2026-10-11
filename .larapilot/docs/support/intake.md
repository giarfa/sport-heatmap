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
