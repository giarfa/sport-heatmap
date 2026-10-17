# Heatmap dashboard

| | |
| --- | --- |
| **Status** | Active |
| **Introduced by** | `US-004` |
| **Last updated by** | `US-014` — 2026-10-13 |
| **Owner persona** | ✨ Joe |

## Purpose

The single screen of the product, served at `/` and **public**: a 16-week × 7-day
grid of daily running distance with weekly totals, a self-calibrating intensity
legend, a sync status (header chip, warning banner, detail card), and a day detail
that lists each run of a tapped day with its pace, ascent and heart rate — public
too, heart rate included (accepted privacy risk, PRD Privacy & legal). Signed-out
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
     `STRAVA_ALLOWED_ATHLETE_IDS`, in configured order, that has a user row;
     with none (blank allowlist, or no listed id connected), the stored athlete
     with the lowest `users.id` who has at least one activity of either source
     (see `strava-connection.md`);
   - nobody resolvable (no stored athlete has any activity) → the
     `strava.connect` view, 200 — there is no login page, and a redirect would
     loop.
3. For that athlete, identically for owner and guest:
   1. The window: `HeatmapWindow::fromRequestedEnd($to, $today)` (`US-013`) —
      the 112 days (Monday of the week 15 weeks before, through Sunday) of the
      ISO week the `to` date falls in. No `to`, or a `to` that is malformed,
      impossible (`2026-02-31`), or in a week after the current one, gives
      `HeatmapWindow::current()`: the current week's window. The value never
      throws and never answers 404 or 422.
   2. `HeatmapGridCache::remember()` returns the cached `HeatmapGrid`, or builds
      it — an entry that cannot be restored as a `HeatmapGrid` (incomplete
      class, or `unserialize` throwing on a nested class, enum case, or date)
      is reported, counts as a miss, and is overwritten: `DailyRunningDistance` (one grouped `SUM` query),
      `DailyRuns` (one query for the window's runs, `US-012`) and `LeadInDistance`
      (one grouped query for the three weeks before the window, `US-014`) →
      `HeatmapBuilder` (rest / ran / future cells, weekly totals, partial current
      week, each Ran day's runs) → `CalibrateIntensityScale` (p25 / p50 / p75, R-7) and
      `BuildWeeklyTrend` (the weekly-mileage chart, from the same `WeekRow`s) — all of it
      cached as the one `HeatmapGrid`, so the chart has no cache of its own.
   3. `ResolveSyncStatus` reads `sync_runs` and the activity count of the
      **current** window — never the shown one — **outside** the cache and
      returns a `SyncStatus`, so the chip, warning, sync card and skeleton are the
      same on every window (`US-013`). For a **CSV-only
      athlete** (`User::isCsvOnly()`, no Strava id — US-011) it reads no
      `sync_runs` at all: the latest import is the newest `updated_at` among
      the athlete's `garmin_csv` activities, and the status is
      `SyncStatus::forCsvOnly()`.
   4. `OldestRunDate` (one `MIN(activity_date)`, read fresh) and the window feed
      `WindowNavigation::for()`: the heading ("Last 16 weeks" on the current
      window, "16 weeks to 6 Sep" on an earlier one), the page title, the empty
      note, and the Previous and Next URLs. Previous is a link only while the
      shown window starts after the oldest stored run (none stored → none);
      Next is a link only on an earlier window, and points at plain `/` when it
      reaches the current one, so the current window has one address.
   5. `dashboard.blade.php` renders the chip, an optional warning, the grid (or
      the skeleton during a first sync), `<x-day-detail>`, and the rail: legend →
      weekly trend → sync detail card (`US-014`). An empty current window keeps the sync-specific note; an
      empty earlier window says "No runs stored in these 16 weeks." (`$grid->hasRuns()`).

### Window navigation (`US-013`, FR-019)

`<x-window-nav>` sits between the heading's range line and the grid: a
`<nav aria-label="Window">` with two 44 px controls, "Previous 4 weeks" and "Next 4
weeks" (the visible word is "Previous" / "Next"; "4 weeks" is `sr-only`, so the visible
label is contained in the accessible name and the arrows are `aria-hidden`). An
available control is a plain `<a>` to `WindowNavigation::$previousUrl` / `$nextUrl`;
an unavailable one is a `<button type="button" disabled>` with a dashed border and
muted ink — a disabled button is announced as disabled and is not a link, which a
span or an `<a>` with no `href` is not. Navigation is full page loads: no script, no
live region, and a signed-out visitor triggers no write and no Strava call (FR-017).
The component does no arithmetic; every URL and availability rule is in
`WindowNavigation`. Styles are `.win-nav` / `.win-btn` / `.win-btn--off` in
`resources/css/app.css`, tokens only (`--control-border`, `--ink`, `--ink-muted`).

The row mirrors `.larapilot/mockups/US-014/` (`past-window.html`, which drew it for
context). The one agreed deviation is the disabled `<button>` in place of the drawn
`<span>` (decision `46f30df8a6f0ac7a`, recorded in that README's Deviations).

### Day cell detail (`US-012`, FR-011)

A day with at least one run (`DayCell::hasDetail()`) renders its cell as
`<button class="hm-hit" data-day="Y-m-d" aria-haspopup="dialog">` inside the
`<td class="hm-td hm-td--ran">`; rest and future days stay plain cells. The
page carries **one** native `<dialog id="day-dialog">` and, per ran day, an
inert `<template id="day-{date}">` holding that day's heading, "N runs · X km"
sub-line and a list of runs — name, source line, and a `<dl>` of Distance,
Moving time, Avg pace, Ascent, Heart rate (`<x-run-metric>` prints a missing
metric as an em dash read aloud as "not recorded", never `0`). A Strava-sourced
run adds "View on Strava" (`DayRun::$stravaUrl`, i.e. `Activity::stravaUrl()`,
the FR-008 rule: a Garmin-sourced run never links, even when it keeps its Strava
id); a Garmin run shows "Garmin import".

`resources/js/app.js` does the rest, with no dependency: a delegated click on
`[data-day]` clones the day's template into the dialog and calls `showModal()`
(Enter and Space click a `<button>`); the close button, Esc, and a click on the
backdrop close it; on `close` the opening cell gets `is-open` removed and focus
back. Opening reads only the page, so it writes no row and calls no endpoint for
a signed-out visitor too (FR-017). Without JS the cells are buttons that do
nothing; their screen-reader text still names the day, distance and run count.

Layout follows `.larapilot/mockups/US-012/`: a bottom sheet below 640 px
(`max-height: 85dvh`), a centred dialog from `sm`, slide/fade only under
`prefers-reduced-motion: no-preference`, light and dark from the existing tokens
plus one `--backdrop`. Tailwind's preflight zeroes `margin`, which the browser
uses to centre a modal, so `.day-dlg` restores `margin: auto` from `sm`. The
button fills the whole `<td>` (padding 2 / 3 / 4 px by breakpoint, the gap the
`<td>` had) so the target is the full column width — 25.8 px at 320 px — while
the visible square, the `<colgroup>` widths and the ramp are untouched.

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
cancel path redirects to `dashboard`, which a guest sees as the public map
whenever an athlete can be displayed (allowlisted and connected, else the
stored-athlete fallback) and as the connect screen otherwise.

**Guest UI.** Signed-out visitors get everything the owner sees, plus one
line under the map: "Owner? Connect with Strava · Import Garmin CSV" — text
links to `strava.redirect` and `garmin.import.create` (44 px tap targets;
`--accent` ink, global `focus-visible` ring; the `·` separator is
`aria-hidden`). It is not a header button: the 375 px header has no room
for a second control, the line must stay discreet on a page whose job is the
map, and Strava orange stays reserved for the connect screen. SEO contract:
`<meta name="robots" content="noindex, nofollow">` only — the page is publicly
reachable but not promoted (decision `4918d74ff18e8b0f`); the meta description
no longer calls it private.

**Owner UI.** A signed-in owner gets the same line without the Strava link:
"Runs missing from Strava? Import Garmin CSV" (same 44 px target and
`--accent` ink). Every visitor can import since US-011 (see
`garmin-csv-import.md`).

**Connect screen.** When no athlete can be displayed, `strava/connect.blade.php`
says "Connect Strava or import a Garmin Connect CSV to build your 16-week
heatmap." and offers, under the Strava-orange button, a full-width secondary
"Import Garmin CSV" control (`btn btn--secondary`, 44 px, the neutral outline
of the import screens). Strava orange stays on the Strava button only. A
guest's first confirmed import creates the CSV-only athlete, and the next
visit to `/` shows that athlete's map instead of this screen.

A confirmed import retires every cached window grid of the written athlete (`HeatmapGridCache::forgetAthlete()`), so the map shows the imported runs on
the next request in whichever window is opened, and "Import cancelled. Nothing was written." arrives through
`<x-session-notices />`. Garmin-imported runs are ordinary rows to the grid:
`DailyRunningDistance` sums every source alike.

**Sync health precedence (`SyncStatus::from`)**: no run → `NeverSynced`; last run
failed → `Failed`; no successful run → `NeverSynced`; last success finished more
than `strava.stale_after_hours` (48) ago → `Stale`; else `Healthy`. Every
non-healthy state shows the banner (title, HTML-escaped reason, "Reconnect
Strava" link to `strava.redirect`) and hides the redundant chip from screen
readers.

**CSV-only status (`SyncStatus::forCsvOnly`, `SyncHealth::CsvOnly`)**: never
warns, never shows the skeleton. The chip reads "Imported {relative}" ("Never
imported" without a stamp), the detail card "Import / Last import / Outcome:
Garmin CSV / Runs in window", and the empty-window line "…after your next
import." instead of "…after your next activity syncs.". Headings and that line
come from `SyncStatus` (`detailHeading()`, `lastRunHeading()`,
`emptyWindowNote()`), so Blade stays markup-only and the Strava copy is
unchanged.

**Intensity scale**: fewer than 4 running days → fixed bands (5 / 10 / 20 km)
with a "too few" note; p25 = p75 → fixed bands with a "too alike" note; otherwise
calibrated bands, upper-inclusive.

## Technical design

| Piece | Where | Role |
| --- | --- | --- |
| Route | `routes/web.php` → `dashboard` (`/`, `strava.fresh`) | The only screen |
| Controller | `app/Http/Controllers/DashboardController.php` | Signed-in athlete or displayed athlete → grid + status; nobody → connect screen |
| Displayed athlete | `app/Support/Strava/ResolveDisplayedAthlete.php` | Guest path only; read-only, ≤ 2 queries; allowlist first, then the stored-athlete fallback |
| Window | `app/Support/Heatmap/HeatmapWindow.php` | ISO-week anchored 112-day window: `current()`, `endingInWeekOf()`, strict `fromRequestedEnd()`, `previous()`, `next()` (clamped to the current window), `isCurrent()` |
| Daily totals | `app/Support/Heatmap/DailyRunningDistance.php` | One grouped aggregate query |
| Day runs | `app/Support/Heatmap/DailyRuns.php`, `app/Data/Heatmap/DayRun.php` | One query per cache miss for the window's runs, grouped by day in start order; `DayRun` is a scalars-only run with its formatting (`kilometres()`, `movingTime()`, `pace()`, `ascent()`, `heartRate()` — null when not recorded — and the Strava link from `Activity::stravaUrl()`) |
| Navigation | `app/Support/Heatmap/OldestRunDate.php`, `app/Data/Heatmap/WindowNavigation.php` | The oldest stored run (one `MIN`, outside the cache) and the heading, title, empty note and Previous / Next URLs of the shown window |
| Weekly trend | `app/Support/Heatmap/BuildWeeklyTrend.php`, `app/Data/Heatmap/WeeklyTrend.php`, `TrendBar.php`, `TrendTick.php` | `US-014`: turns the window's `WeekRow`s into the chart's bars, 4-week average, y axis and every string it prints (below) |
| Lead-in | `app/Support/Heatmap/LeadInDistance.php` | `US-014`: the three weeks before the window in one grouped query, feeding the first averages |
| Grid builder | `app/Support/Heatmap/HeatmapBuilder.php` | Cells, weeks, totals; attaches each Ran day's runs to its `DayCell` |
| Scale | `app/Support/Heatmap/CalibrateIntensityScale.php`, `app/Data/Heatmap/IntensityScale.php` | Percentiles (R-7, pinned by `.ai/rules/heatmap.md`) and fallbacks |
| Grid DTOs | `app/Data/Heatmap/HeatmapGrid.php`, `WeekRow.php`, `DayCell.php`, `LegendRow.php`, `app/Enums/DayCellState.php` | All formatting and accessible names |
| Grid cache | `app/Support/Heatmap/HeatmapGridCache.php`, `config/cache.php` (`serializable_classes`) | Key `heatmap:grid:v4:{user id}:g{generation}:{window start}:{today}`, the generation read from `heatmap:grid:generation:{user id}`; `forgetAthlete()` bumps it; TTL `heatmap.grid_cache_ttl`; the grid's classes are the cache unserialize allowlist |
| Sync status | `app/Support/Sync/ResolveSyncStatus.php`, `app/Data/SyncStatus.php`, `app/Enums/SyncHealth.php` | Fresh on every request; every user-facing sync string; the CSV-only branch (`forCsvOnly()`, `SyncHealth::CsvOnly`) |
| Views | `resources/views/dashboard.blade.php`, `layouts/app.blade.php`, `components/heatmap-grid`, `window-nav`, `day-detail`, `run-metric`, `heatmap-legend`, `weekly-trend`, `weekly-trend-skeleton`, `heatmap-skeleton`, `sync-chip`, `sync-warning`, `sync-detail`, `session-notices` | Markup only |
| Theme | inline head script in `layouts/app.blade.php`, `resources/js/app.js`, `resources/css/app.css` | Light / dark / system, persisted in `localStorage` |
| Config | `config/heatmap.php` | Literals `window_weeks` (16), `navigation_step_weeks` (4) and `grid_cache_ttl` (86 400) |
| Tests | `tests/Feature/Dashboard/*`, `tests/Unit/Support/Heatmap/*`, `tests/Unit/Data/Heatmap/*`, `tests/Unit/Data/SyncStatusTest.php`, `tests/Unit/Support/Sync/ResolveSyncStatusTest.php` | |

A dashboard request costs one grouped query, one run query and one lead-in query on a cache miss, zero on a hit, plus
three small indexed queries for the sync status and one `MIN()` for the oldest run (`US-013`).

### Weekly trend (`US-014`, FR-020)

`BuildWeeklyTrend` receives the 16 `WeekRow`s, the three lead-in weekly totals in metres, the athlete's oldest run and
the grid's date-range label, and returns a `WeeklyTrend` of numbers and strings only; Blade does no arithmetic.

- **Bars** are the grid's own totals: the bar's tenths of a kilometre are read back from `WeekRow::kilometres()`, and
  geometry (`y = 100 − km / top × 100`, two decimals) is derived from that printed value, so a bar cannot disagree
  with its row by a rounding step.
- **Rolling average**: week *i* is the mean of weeks *i−3 … i*, summed in integer metres and divided by 4 with half-up
  rounding to 0.1 km (`intdiv($metres + 200, 400)`); weeks 0–2 take their missing weeks from the lead-in.
- **History rule**: no point for the in-progress week, with no stored run, or before the week of the oldest run plus three
  weeks. An unknown week is never averaged as 0 km, so the line starts late rather than dipping to a figure never run.
- **Y axis**: `max` covers the bars **and** the line points; `step` is the smallest of 5, 10, 20, 25, 30, 40, 50, 75, 100,
  150, 200 km that is ≥ max ÷ 3 (200 when none); `top = step × ⌈max ÷ step⌉`; only the top label carries the unit.
- **Line**: returned as polyline segments, one per unbroken run of points, a lone point becoming a 4-unit tick.
- **Empty window**: no run → no bars, ticks, or line, and `isEmpty()` is true.

The card (`components/weekly-trend.blade.php`, markup only) is the third rail card, beside the grid from `lg` and stacked
under the legend below it. It has four states: the chart; the empty window ("No runs in this window, so there is
nothing to chart.", heading kept, no axis); the first-sync skeleton (`weekly-trend-skeleton`, 16 `aria-hidden` bars,
shown whenever the grid shows its own); and absent, because the connect screen renders no dashboard.

Accessibility contract the markup must keep: the `<section>` is named by its `h3`; the SVG is a `role="group"` named by its
`<title>`; each bar is a `<g role="img" aria-label="…">` carrying its accessible name, with the same text in a `<title>` for the pointer tooltip (a `<title>` alone is exposed inconsistently); gridlines,
baseline and line are `aria-hidden`; the visible, collapsed-by-default `<details>` "Weekly figures" table is the text
alternative. Partial is never colour alone (washed fill, dashed outline, a `partial` tag, "partial week" in the name),
nor is the average (a line against bars), nor a 0 km week (a stub the key names).

The y labels, x labels and `partial` tag are HTML positioned in percent **outside** the SVG. The SVG is stretched with
`preserveAspectRatio="none"` so one element fills a phone and a wide rail with no resize script; text or circles inside
it would distort, and every stroke needs `vector-effect: non-scaling-stroke` or lines smear on a wide rail.
- **Lead-in query**: `LeadInDistance` is one grouped query over the 21 days before the window, bounded `>= start` and
  `< window start`.

## Architectural choices

| Choice | Alternatives considered | Why this one |
| --- | --- | --- |
| Server-rendered Blade, no SPA, no starter kit, no Filament | Livewire, Inertia, Filament | One screen, one user; any of them ships more scaffolding than the product |
| Grid as a real `<table>` with an accessible name per cell | `<div>` grid / canvas / SVG chart | FR-009: table semantics, screen-reader names, 375 px without page scroll |
| Today's date in the cache key | Key on window only | Yesterday's grid would survive midnight and render today as a future cell — stale data shown as current (decision `4a7f0fb3e9bb61ac`) |
| Sync status resolved outside the cache | Cache it with the grid | A cached "synced 6 hours ago" is exactly what FR-007 forbids |
| Cache the grid DTOs, with `cache.serializable_classes` listing exactly the classes the grid is made of (US-010) | `serializable_classes => true`; caching a plain array through `toArray()` / `fromArray()` mappers | Laravel 13 defaults the list to `false`, so a serializing store (`database`, `file`, `redis`) restored the grid as `__PHP_Incomplete_Class` and every cache hit answered 500. `true` reopens the gadget-chain risk the default closes; mappers on five DTOs add code for no gain over the framework's allowlist |
| A cached entry that cannot be restored is reported, rebuilt and overwritten (US-010) | Return it as is; `Cache::remember()`; `cache:clear` in the deploy notes | An entry written by an older shape of the grid — a class not yet allowlisted, a renamed `DayCellState` case, a changed property type — would otherwise fail every request until midnight. `unserialize` throws for nested failures, so the read catches `Throwable`, not only `__PHP_Incomplete_Class` |
| The day's runs live inside the cached grid (`DayCell::$runs`, US-012) | A JSON endpoint fetched when a day is opened; a second cache entry for the detail | The detail then shares the grid's one key and its one invalidation — the sync's and the import's `forgetAthlete()` covers it with no new call site, and the two can never disagree. Opening a day costs no request, so a signed-out visitor still triggers no write and no Strava call (FR-017). The price is ~19 runs' worth of scalars per week in the entry, a few tens of KB for a 112-day window |
| A per-athlete generation counter in the cache key; one `forgetAthlete()` bumps it (US-013) | Cache tags; forgetting each window's key in a loop; one entry holding every window | Navigation means an athlete has one cached grid per window they opened, and `cache.default` is `database`, which has no tags. A loop would have to guess which windows were cached; a single entry would rebuild every window on any miss. A counter makes one write retire every window at once; the orphans expire through `grid_cache_ttl`. The counter is created with `add()` then `increment()`d, because a bare `increment()` on a missing key answers `false` on the database store and a `forever(…, 1)` fallback lets two overlapping writes both land on 1. If the counter cannot be read the request builds the grid and leaves the cache alone, so a grid is never served or stored under a guessed generation; a failed bump is reported, not thrown, since the write it follows is already committed |
| Only windows that can hold a run are cached (US-013) | Cache every requested window; clamp `to` to the oldest run; throttle `/` | `to` takes any past date and each week is a key, so a guest could fill the cache table with empty grids back to year 0. A window that ends before the oldest run is built uncached (two small queries); the current window is always cached. Clamping would change what a shared link shows, and a throttle would hit the owner too |
| A `v2` segment in the cache key (US-012; `v3` since US-013, when the key gained the generation; `v4` since US-014, when the grid gained its `trend`) | Reuse the key and rely on the unserialize `Throwable` catch | A readonly property an old entry never saw comes back *uninitialized*, not as a failed read: the entry passes `instanceof HeatmapGrid` and then throws on first access, outside the catch. A new key means old entries are never read |
| Run formatting reuses `GarminImportFormat` | A second formatter in the heatmap layer | Pace, ascent, heart rate, distance and duration print the same on the review and in the day detail; one copy cannot drift |
| The URL names the window's **end** week, as a `Y-m-d` query value (`/?to=2026-09-06`, `US-013`) | A path segment; the window's start date in the URL; a page number | A query value keeps `/` the one route and the one cache key shape; the end is what the reader navigates by and survives a change of `window_weeks`, where a start date would shift every shared link; a page number says nothing in a shared link |
| A bad `to` renders the current window silently (`US-013`) | 404; 422; a redirect to `/` | The spec asks for a bad or future link to render "without an error"; an old bookmark is better served by today's map than by an error page, and the guest path stays free of any new response |
| `previous()` is not clamped to the oldest run; `next()` is clamped to the current window | Clamp both inside `HeatmapWindow` | The window is a value object that never sees the data, so how far back Previous may go is the controller's question (it disables the control); a window past the current one would hold only future cells |
| The unavailable Previous / Next control is a disabled `<button>` (US-013) | The mockup's `<span>`; an `<a>` without `href`; `aria-disabled` on a link | A span is announced as text and an `<a>` with no `href` is neither focusable nor announced as a control; `aria-disabled` on a link still looks and tabs like one. A native disabled button is announced as disabled, is not a link, and is skipped by Tab — the acceptance criterion exactly. The look is the mockup's |
| Previous is disabled from the athlete's oldest stored run, not from a fixed horizon (US-013) | A fixed number of windows; clamping inside `HeatmapWindow`; never disabling it | The reader should never page into a run of empty windows, and the value object cannot know the data. The cost is one `MIN()` per request, which the `(user_id, activity_date)` index serves, read outside the cache because the oldest run belongs to the athlete, not to any cached window |
| The sync status always reads the current window (US-013) | Read the shown window | The status describes the sync, not the period on screen; a past window would otherwise show a different "Runs in window", a different empty note, and could flip the skeleton |
| The lead-in weeks come from their own query, `LeadInDistance` (US-014) | Widen `DailyRunningDistance` by 21 days | The intensity scale is calibrated over the shown window's daily totals only (FR-006); widening that query would let weeks the reader cannot see move the legend and every cell's level. One more grouped aggregate keeps the two concerns apart |
| Rolling average in integer metres, half-up (US-014) | Float mean and `round()` | A float mean of exact half-way values depends on summation order: the US-014 mockup README prints 24.5, 33.0 and 39.0 for weeks whose exact means are 24.55, 33.05 and 39.05 km (its generator rounded `98.19999999999999 / 4` down). Integer math reads 24.6, 33.1 and 39.1 and cannot drift; `BuildWeeklyTrendTest` pins this |
| The chart is server-rendered inline SVG in a Blade component (US-014) | Chart.js / ApexCharts; a client-rendered canvas | A charting library is a new frontend dependency the Technical Architecture rules out, would need its own accessible text alternative, and would draw nothing for a guest with scripts off. One SVG of about 20 elements costs no script, no font, no service |
| The line is drawn as segments, not one polyline (US-014) | A single `points` string | The history and partial-week rules only cut the ends of the line today, but a single polyline would draw straight across any future gap; splitting at every missing point makes that impossible by construction |
| R-7 percentiles | Nearest-rank | Only R-7 reproduces the mockup's validated legend (7.2 / 9.9 / 14.1 km) |
| CSV-only athlete: latest import = newest `updated_at` of its `garmin_csv` activities (US-011) | A `users.garmin_imported_at` column | No migration for one stamp; the cost is that a re-import that changes nothing does not move it |
| CSV-only athlete ignores `sync_runs` | Show the instance-wide sync state | `sync_runs` describe the Strava sync, which never selects this athlete — a failed or stale sync of someone else would put a false warning over a map only imports change |
| All copy and arithmetic in DTOs / `SyncStatus` | Logic in Blade | Testable without rendering; components stay markup-only |
| `@yield('header-status')` in the layout | View composer / shared variable | The connect and rejected screens extend the same layout and must render no chip |
| Public read-only home page for guests (FR-017) | Keep it sign-in only | The owner checks it on any device and shares the link; the owner accepted the Strava API Agreement risk (decisions `f71daaa7e976c188`, `88f04507cbc7e573`) |
| One shared `<dialog>` filled from a `<template>` per day (US-012) | One `<dialog>` per ran day; a bottom-sheet library; `<details>` | Same markup weight as the per-day dialogs but a single focus-return path; native `showModal()` gives the trap, Esc and inert page for free, where a library is a new dependency the app, which has no Alpine or Livewire, does not carry. `<details>` cannot be a modal sheet |
| The Strava link in the detail opens in the same tab | `target="_blank" rel="noopener"` | The approved mockup and the US-008 review link do the same; the visible behaviour is the mockup's |
| Guests and owner share the per-athlete grid cache | A separate guest cache | Same data, same key; a separate cache would double the rebuilds and could disagree with the owner's view |
| Guests see the same sync warning, reason included | A reduced guest warning | Decision `f75e292def892fad`; the reason is already non-secret and HTML-escaped |
| "Import Garmin CSV" for every visitor as a text line under the map (US-011) | A header button; a menu; owner-only | The same reasoning as the guest line: discreet, no header budget at 375 px, one screen with no nav. Open to guests by the owner's choice so the map works with CSV imports only |
| Connect screen: Garmin import as a secondary outline button below the Strava one | A second orange button; a text link | Strava's brand guidelines put orange on the connect button only; a full-width 44 px control reads as an equal way in on a phone |
| Guest sign-in as a footer text link | Header button; Strava-orange button on the map | Discreet, fits the 375 px header budget, keeps orange reserved for the connect screen |
| Shared `session-notices` component | Duplicate the notices in both views | One copy of the cancellation and expiry copy; the caller only decides the width |
| Two-letter weekday `<abbr>` | Single letters from the PRD prose | Matches the mockup (decision log) |

## Key decisions & invariants

- **Invariant — a CSV-only athlete never gets a sync warning.** _Breaks if:_
  `ResolveSyncStatus` reads `sync_runs` for it, or `needsWarning()` stops
  excluding `CsvOnly`. Its "Imported …" stamp means "data last changed by an
  import": a re-import that changes nothing does not move it.

- **Invariant — a guest view of `/` is strictly read-only.** Only a confirmed
  Garmin import writes (US-011), never the page itself. _Breaks if:_ anything on
  the guest path calls `EnsureFreshAccessToken`, `StravaClient`, or loads a token
  column — a guest could then trigger a refresh, an HTTP call, or a write.
- **Invariant — a connected allowlisted athlete beats the fallback.** The
  fallback (lowest `users.id` with an activity) applies only while no allowlisted
  athlete has connected, so it can show an athlete removed from the allowlist —
  an accepted risk (PRD "Accepted risk — fallback display"). The grid cache is
  keyed per athlete, so switching from the fallback to an allowlisted athlete
  never serves the fallback's cached grid.

- **Invariant — the sync status is never cached, and never reads the shown window.**
  _Breaks if:_ it moves into the `remember()` closure, or `ResolveSyncStatus` is
  called with `$window` instead of `HeatmapWindow::current($today)` — the chip and
  the sync card would then change as the reader navigates.
- **Invariant — every class serialized inside a `HeatmapGrid` is listed in
  `cache.serializable_classes`.** _Breaks if:_ a DTO, enum, or value object is
  nested into the grid without joining the list — the grid then fails to
  restore and is rebuilt (and reported) on every request, with no cache
  benefit. `WeeklyTrend`, `TrendBar` and `TrendTick` are listed (`US-014`).
  `LegendRow` is not: `IntensityScale` holds scalars and derives legend rows on
  render. The list never becomes `true`.
- **Invariant — every write that changes an athlete's runs calls
  `HeatmapGridCache::forgetAthlete()`.** _Breaks if:_ a new write path (a sync, an
  import, an edit) skips it — every window already cached keeps the old data until
  midnight or the TTL.
- **Invariant — the grid cache key carries today's date.** _Breaks if:_ the key
  drops it; the cache then outlives midnight.
- **Invariant — the key's shape version changes whenever a cached class gains a
  property.** `DayCell` gained `runs` in `US-012`, so the key went from
  `heatmap:grid:{…}` to `heatmap:grid:v2:{…}`; `US-013` made it `v3` when the
  generation joined the key, and `US-014` made it `v4` when `HeatmapGrid` gained
  `trend`. _Breaks if:_ a property is added
  without bumping it — an entry from the previous deploy then restores with an
  uninitialized readonly property and every request fails until midnight.
- **Invariant — runs ride only on Ran cells.** `HeatmapBuilder` attaches a day's
  runs only when the grid's own rule made it Ran (distance above 0), so a Rest or
  Future cell never opens a detail and the detail never lists a run the colour
  does not account for. `DailyRuns` and `DailyRunningDistance` read the same
  window of the same rows.
- **Invariant — the weekly trend lives inside the cached grid, never in a cache of
  its own.** _Breaks if:_ the chart is cached or built outside `HeatmapGridCache::remember()`
  — a sync or an import that calls `forgetAthlete()` would then retire the grid but leave
  a chart that disagrees with its rows. Its lead-in query runs inside the build closure, so
  a cache hit costs the chart no query.
- **Invariant — the scale is calibrated once per grid build**, so cells and legend
  cannot disagree.
- **Invariant — the failure reason is escaped** (`{{ }}`), never `{!! !!}`: it can
  carry a raw Strava error string.
- **Invariant — `noindex, nofollow` and the "Powered by Strava" footer link stay
  in the layout** (FR-008), so no view change can drop them.

## Extension points & gotchas

- The test suite runs the `array` store without serialization; a test that
  needs the real round trip sets `cache.default` to `database`
  (`HeatmapGridCacheTest`).
- `HeatmapBuilder` takes `$today` as an argument: test date rules by naming a
  date, not by travelling time.
- The first-sync skeleton shows only while the first run is in flight and no
  success exists (`SyncStatus::showsSkeleton()`).
- `sync_runs` is instance-wide; the status is the same for every athlete.
- Several allowlisted athletes: each signed-in athlete sees their own map, guests
  see the first connected one in allowlist order. With none connected, guests see
  the lowest-id stored athlete with activities — on a fresh
  `migrate:fresh --seed`, the seeded demo athlete.
- **Demo dataset (`TrainingBlockSeeder`).** The 16-week block is computed from the
  current week, so it always fills exactly the current window. Since `US-013` it is
  preceded by seven weeks of Garmin-imported history (28.4, 31.0, 33.5, 36.2, 22.0,
  **0.0**, 18.6 km, oldest first; `source = garmin_csv`, no metrics, each week summing to
  its total in metres), so Previous is demonstrable after `migrate:fresh --seed`.
  The totals are the ones the `US-014` mockup draws, so the two specs share one fixture and
  the 0 km week gives the trend a rest week to show. The block itself is untouched and
  the legend over the current window does not change. Do not run `migrate:fresh --seed` on a
  database that holds real imports — point `DB_DATABASE` at a scratch file.
- Strava orange (`#fc4c02`) is reserved for the connect button and never used as
  a data colour.

## Related

- Specs: `US-004`, `US-005`, `US-006`, `US-007`, `US-008`, `US-009`, `US-010`, `US-011`, `US-012`, `US-013`
- Plans: `.larapilot/plans/US-004-plan.yaml`, `US-005-plan.yaml`, `US-006-plan.yaml`, `US-007-plan.yaml`, `US-009-plan.yaml`, `US-010-plan.yaml`, `US-013-plan.yaml`
- Review: `.larapilot/docs/review/US-004.md`, `US-005.md`, `US-006.md`, `US-007.md`, `US-009.md`, `US-010.md`
- Other domains: `strava-connection.md`, `activity-sync.md`, `garmin-csv-import.md`
