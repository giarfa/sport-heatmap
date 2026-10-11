# Heatmap dashboard

| | |
| --- | --- |
| **Status** | Active |
| **Introduced by** | `US-004` |
| **Last updated by** | `US-008` — 2026-10-11 |
| **Owner persona** | ✨ Joe |

## Purpose

The single screen of the product, served at `/` and **public**: a 16-week × 7-day
grid of daily running distance with weekly totals, a self-calibrating intensity
legend, and a sync status (header chip, warning banner, detail card). Signed-out
visitors see the owner's last synced map read-only; signing in is only needed to
connect Strava. It must never present stale data as current. If it stops
working, the owner loses the only view of their training and the link they
share stops working.

## Functional flow

1. `GET /` runs the `strava.fresh` middleware (see `strava-connection.md`), then
   `DashboardController`.
2. The athlete to render is resolved:
   - signed in → the signed-in athlete (their own map);
   - signed out → `ResolveDisplayedAthlete`: the first id of
     `STRAVA_ALLOWED_ATHLETE_IDS`, in configured order, that has a user row
     (see `strava-connection.md`);
   - nobody resolvable (no allowlisted athlete has connected) → the
     `strava.connect` view, 200 — there is no login page, and a redirect would
     loop.
3. For that athlete, identically for owner and guest:
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

The guest branch is **read-only by structure**: the middleware is a no-op for
guests, nothing on the path calls `EnsureFreshAccessToken` or `StravaClient`, and
the resolver selects no token column. A guest request writes no row to `users`,
`activities`, or `sync_runs`, sends no HTTP request, and never refreshes a token.
The session store and, on a grid-cache miss, the cache store still write — with
the `database` drivers that is a `sessions` row per cookieless visit and a
`cache` row per rebuild.

`<x-session-notices />` renders above the heading on both the dashboard and the
connect screen: the OAuth cancellation flash (`status`) and the
"Your Strava connection expired" alert. Both views carry it because the OAuth
cancel path redirects to `dashboard`, which a guest sees as the public map once
an athlete has connected and as the connect screen before that.

**Guest UI.** Signed-out visitors get everything the owner sees, plus one
line under the map: "Owner? Connect with Strava", a text link to
`strava.redirect` (`@guest` only; 44 px tap target; `--accent` ink, global
`focus-visible` ring). It is not a header button: the 375 px header has no room
for a second control, the line must stay discreet on a page whose job is the
map, and Strava orange stays reserved for the connect screen. SEO contract:
`<meta name="robots" content="noindex, nofollow">` only — the page is publicly
reachable but not promoted (decision `4918d74ff18e8b0f`); the meta description
no longer calls it private.

**Owner UI.** A signed-in owner gets the mirror of the guest line instead:
"Runs missing from Strava? Import Garmin CSV", a text link to
`garmin.import.create` (`@auth` only, same 44 px target and `--accent` ink).
Guests never see it, and the import routes refuse them regardless (see
`garmin-csv-import.md`). A confirmed import forgets the owner's cached grid
for today (`HeatmapGridCache::forget()`), so the map shows the imported runs on
the next request, and "Import cancelled. Nothing was written." arrives through
`<x-session-notices />`. Garmin-imported runs are ordinary rows to the grid:
`DailyRunningDistance` sums every source alike.

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
| Controller | `app/Http/Controllers/DashboardController.php` | Signed-in athlete or displayed athlete → grid + status; nobody → connect screen |
| Displayed athlete | `app/Support/Strava/ResolveDisplayedAthlete.php` | Guest path only; read-only, ≤ 1 query |
| Window | `app/Support/Heatmap/HeatmapWindow.php` | ISO-week anchored 112-day window |
| Daily totals | `app/Support/Heatmap/DailyRunningDistance.php` | One grouped aggregate query |
| Grid builder | `app/Support/Heatmap/HeatmapBuilder.php` | Cells, weeks, totals |
| Scale | `app/Support/Heatmap/CalibrateIntensityScale.php`, `app/Data/Heatmap/IntensityScale.php` | Percentiles (R-7, pinned by `.ai/rules/heatmap.md`) and fallbacks |
| Grid DTOs | `app/Data/Heatmap/HeatmapGrid.php`, `WeekRow.php`, `DayCell.php`, `LegendRow.php`, `app/Enums/DayCellState.php` | All formatting and accessible names |
| Grid cache | `app/Support/Heatmap/HeatmapGridCache.php` | Key `heatmap:grid:{user id}:{window start}:{today}`, TTL `heatmap.grid_cache_ttl` |
| Sync status | `app/Support/Sync/ResolveSyncStatus.php`, `app/Data/SyncStatus.php`, `app/Enums/SyncHealth.php` | Fresh on every request; every user-facing sync string |
| Views | `resources/views/dashboard.blade.php`, `layouts/app.blade.php`, `components/heatmap-grid`, `heatmap-legend`, `heatmap-skeleton`, `sync-chip`, `sync-warning`, `sync-detail`, `session-notices` | Markup only |
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
| Public read-only home page for guests (FR-017) | Keep it sign-in only | The owner checks it on any device and shares the link; the owner accepted the Strava API Agreement risk (decisions `f71daaa7e976c188`, `88f04507cbc7e573`) |
| Guests and owner share the per-athlete grid cache | A separate guest cache | Same data, same key; a separate cache would double the rebuilds and could disagree with the owner's view |
| Guests see the same sync warning, reason included | A reduced guest warning | Decision `f75e292def892fad`; the reason is already non-secret and HTML-escaped |
| Owner's "Import Garmin CSV" as a text line under the map | A header button; a menu | The same reasoning as the guest line: discreet, no header budget at 375 px, one screen with no nav |
| Guest sign-in as a footer text link | Header button; Strava-orange button on the map | Discreet, fits the 375 px header budget, keeps orange reserved for the connect screen |
| Shared `session-notices` component | Duplicate the notices in both views | One copy of the cancellation and expiry copy; the caller only decides the width |
| Two-letter weekday `<abbr>` | Single letters from the PRD prose | Matches the mockup (decision log) |

## Key decisions & invariants

- **Invariant — a guest request is strictly read-only.** _Breaks if:_ anything on
  the guest path calls `EnsureFreshAccessToken`, `StravaClient`, or loads a token
  column — a guest could then trigger a refresh, an HTTP call, or a write.
- **Invariant — the displayed athlete is on the allowlist.** An athlete removed
  from it is never shown, even while their rows remain stored.

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
- Several allowlisted athletes: each signed-in athlete sees their own map, guests
  see the first connected one in allowlist order.
- Strava orange (`#fc4c02`) is reserved for the connect button and never used as
  a data colour.

## Related

- Specs: `US-004`, `US-005`, `US-006`, `US-007`, `US-008`
- Plans: `.larapilot/plans/US-004-plan.yaml`, `US-005-plan.yaml`, `US-006-plan.yaml`, `US-007-plan.yaml`
- Review: `.larapilot/docs/review/US-004.md`, `US-005.md`, `US-006.md`, `US-007.md`
- Other domains: `strava-connection.md`, `activity-sync.md`, `garmin-csv-import.md`
