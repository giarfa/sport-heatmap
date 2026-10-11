# Heatmap dashboard

| | |
| --- | --- |
| **Status** | Active |
| **Introduced by** | `US-004` |
| **Last updated by** | `US-007` — 2026-10-11 (catch-up) |
| **Owner persona** | ✨ Joe |

## Purpose

The single screen of the product, served at `/`: a 16-week × 7-day grid of daily
running distance with weekly totals, a self-calibrating intensity legend, and a
sync status (header chip, warning banner, detail card). It must never present
stale data as current. If it stops working, the owner loses the only view of
their training.

## Functional flow

1. `GET /` runs the `strava.fresh` middleware (see `strava-connection.md`), then
   `DashboardController`.
2. A signed-out visitor gets the `strava.connect` view (200) — there is no login
   page, and a redirect would loop.
3. For the signed-in athlete:
   1. `HeatmapWindow::current()` — Monday of the week 15 weeks ago through Sunday
      of the current week (112 days).
   2. `HeatmapGridCache::remember()` returns the cached `HeatmapGrid`, or builds
      it: `DailyRunningDistance` (one grouped `SUM` query) →
      `HeatmapBuilder` (rest / ran / future cells, weekly totals, partial current
      week) → `CalibrateIntensityScale` (p25 / p50 / p75, R-7).
   3. `ResolveSyncStatus` reads `sync_runs` and the window's activity count
      **outside** the cache and returns a `SyncStatus`.
   4. `dashboard.blade.php` renders the chip, an optional warning, the grid (or
      the skeleton during a first sync), the legend, and the sync detail card.

**Sync health precedence (`SyncStatus::from`)**: no run → `NeverSynced`; last run
failed → `Failed`; no successful run → `NeverSynced`; last success finished more
than `strava.stale_after_hours` (48) ago → `Stale`; else `Healthy`. Every
non-healthy state shows the banner (title, HTML-escaped reason, "Reconnect
Strava" link to `strava.redirect`) and hides the redundant chip from screen
readers.

**Intensity scale**: fewer than 4 running days → fixed bands (5 / 10 / 20 km)
with a "too few" note; p25 = p75 → fixed bands with a "too alike" note; otherwise
calibrated bands, upper-inclusive.

## Technical design

| Piece | Where | Role |
| --- | --- | --- |
| Route | `routes/web.php` → `dashboard` (`/`, `strava.fresh`) | The only screen |
| Controller | `app/Http/Controllers/DashboardController.php` | Guest → connect screen; athlete → grid + status |
| Window | `app/Support/Heatmap/HeatmapWindow.php` | ISO-week anchored 112-day window |
| Daily totals | `app/Support/Heatmap/DailyRunningDistance.php` | One grouped aggregate query |
| Grid builder | `app/Support/Heatmap/HeatmapBuilder.php` | Cells, weeks, totals |
| Scale | `app/Support/Heatmap/CalibrateIntensityScale.php`, `app/Data/Heatmap/IntensityScale.php` | Percentiles (R-7, pinned by `.ai/rules/heatmap.md`) and fallbacks |
| Grid DTOs | `app/Data/Heatmap/HeatmapGrid.php`, `WeekRow.php`, `DayCell.php`, `LegendRow.php`, `app/Enums/DayCellState.php` | All formatting and accessible names |
| Grid cache | `app/Support/Heatmap/HeatmapGridCache.php` | Key `heatmap:grid:{user id}:{window start}:{today}`, TTL `heatmap.grid_cache_ttl` |
| Sync status | `app/Support/Sync/ResolveSyncStatus.php`, `app/Data/SyncStatus.php`, `app/Enums/SyncHealth.php` | Fresh on every request; every user-facing sync string |
| Views | `resources/views/dashboard.blade.php`, `layouts/app.blade.php`, `components/heatmap-grid`, `heatmap-legend`, `heatmap-skeleton`, `sync-chip`, `sync-warning`, `sync-detail` | Markup only |
| Theme | inline head script in `layouts/app.blade.php`, `resources/js/app.js`, `resources/css/app.css` | Light / dark / system, persisted in `localStorage` |
| Config | `config/heatmap.php` | Literal `window_weeks` (16) and `grid_cache_ttl` (86 400) |
| Tests | `tests/Feature/Dashboard/*`, `tests/Unit/Support/Heatmap/*`, `tests/Unit/Data/Heatmap/*`, `tests/Unit/Data/SyncStatusTest.php`, `tests/Unit/Support/Sync/ResolveSyncStatusTest.php` | |

A dashboard request costs one grouped query on a cache miss, zero on a hit, plus
three small indexed queries for the sync status.

## Architectural choices

| Choice | Alternatives considered | Why this one |
| --- | --- | --- |
| Server-rendered Blade, no SPA, no starter kit, no Filament | Livewire, Inertia, Filament | One screen, one user; any of them ships more scaffolding than the product |
| Grid as a real `<table>` with an accessible name per cell | `<div>` grid / canvas / SVG chart | FR-009: table semantics, screen-reader names, 375 px without page scroll |
| Today's date in the cache key | Key on window only | Yesterday's grid would survive midnight and render today as a future cell — stale data shown as current (decision `4a7f0fb3e9bb61ac`) |
| Sync status resolved outside the cache | Cache it with the grid | A cached "synced 6 hours ago" is exactly what FR-007 forbids |
| R-7 percentiles | Nearest-rank | Only R-7 reproduces the mockup's validated legend (7.2 / 9.9 / 14.1 km) |
| All copy and arithmetic in DTOs / `SyncStatus` | Logic in Blade | Testable without rendering; components stay markup-only |
| `@yield('header-status')` in the layout | View composer / shared variable | The connect and rejected screens extend the same layout and must render no chip |
| Two-letter weekday `<abbr>` | Single letters from the PRD prose | Matches the mockup (decision log) |

## Key decisions & invariants

- **Invariant — the sync status is never cached.** _Breaks if:_ it moves into the
  `remember()` closure.
- **Invariant — the grid cache key carries today's date.** _Breaks if:_ the key
  drops it; the cache then outlives midnight.
- **Invariant — the scale is calibrated once per grid build**, so cells and legend
  cannot disagree.
- **Invariant — the failure reason is escaped** (`{{ }}`), never `{!! !!}`: it can
  carry a raw Strava error string.
- **Invariant — `noindex, nofollow` and the "Powered by Strava" footer link stay
  in the layout** (FR-008), so no view change can drop them.

## Extension points & gotchas

- `HeatmapBuilder` takes `$today` as an argument: test date rules by naming a
  date, not by travelling time.
- The first-sync skeleton shows only while the first run is in flight and no
  success exists (`SyncStatus::showsSkeleton()`).
- `sync_runs` is instance-wide; the status is the same for every athlete.
- Strava orange (`#fc4c02`) is reserved for the connect button and never used as
  a data colour.

## Related

- Specs: `US-004`, `US-005`, `US-006`
- Plans: `.larapilot/plans/US-004-plan.yaml`, `US-005-plan.yaml`, `US-006-plan.yaml`
- Review: `.larapilot/docs/review/US-004.md`, `US-005.md`, `US-006.md`
- Other domains: `strava-connection.md`, `activity-sync.md`
