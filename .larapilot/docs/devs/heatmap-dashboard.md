# Heatmap dashboard

| | |
| --- | --- |
| **Status** | Active |
| **Introduced by** | `US-004` |
| **Last updated by** | `US-021` — 2026-10-26 |
| **Owner persona** | ✨ Joe |

## Purpose

The single screen of the product, served at `/` and **private to the signed-in
owner** (`US-020`): a 16-week × 7-day grid of daily running distance with weekly
totals, a self-calibrating intensity legend, an import status ("last import N
ago": header chip, footer line, detail card), and a day detail that lists each run of a tapped day with
its pace, ascent and heart rate. A signed-out browser is redirected to `/login`
(see `owner-authentication.md`); there is no public view and no displayed-athlete
selection. It must never present stale data as current. If it stops working, the
owner loses the only view of their training.

## Functional flow

0. **Every web response**, this screen's and a 404's alike, leaves through the global
   `SetSecurityHeaders` middleware (`US-018`): `Content-Security-Policy: frame-ancestors
   'none'`, `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy:
   same-origin`, and `Strict-Transport-Security: max-age=31536000` on secure requests only. No
   page can be framed by another site, so the Garmin review's Overwrite controls cannot be
   trick-clicked. A header the route already set is kept.
1. `GET /` runs the `auth` and `password.set` middleware (a signed-out request goes
   to `/login`, an owner with no password yet to the first-password page), then
   `DashboardController`.
2. The athlete to render is the signed-in owner (`$request->user()`): their
   imported runs. Other `users` rows are never displayed. An owner with no stored
   run gets the `dashboard-empty` view, 200 — the dashboard is where a redirect
   would loop. It offers one thing, the "Import Garmin CSV" button, and still
   carries the import chip ("No runs imported yet") and the footer line.
3. For that owner:
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
   3. `ResolveImportStatus` reads the newest `updated_at` among the owner's
      imported activities and the activity count of the **current** window —
      never the shown one — **outside** the cache and returns an `ImportStatus`,
      so the chip, the footer line and the import card are the same on every
      window (`US-013`). There is no warning state: nothing runs in the
      background, so nothing can fall behind (`US-021`).
   4. `OldestRunDate` (one `MIN(activity_date)`, read fresh) and the window feed
      `WindowNavigation::for()`: the heading ("Last 16 weeks" on the current
      window, "16 weeks to 6 Sep" on an earlier one), the page title, the empty
      note, and the Previous and Next URLs. Previous is a link only while the
      shown window starts after the oldest stored run (none stored → none);
      Next is a link only on an earlier window, and points at plain `/` when it
      reaches the current one, so the current window has one address.
   5. `dashboard.blade.php` renders the chip, the grid, `<x-day-detail>`, and the
      rail: legend → weekly trend → import detail card (`US-014`). An empty
      current window says "No runs found in the last 16 weeks. The map fills in
      after your next import."; an empty earlier window says "No runs stored in
      these 16 weeks." (`$grid->hasRuns()`).

### Window navigation (`US-013`, FR-019)

`<x-window-nav>` sits between the heading's range line and the grid: a
`<nav aria-label="Window">` with two 44 px controls, "Previous 4 weeks" and "Next 4
weeks" (the visible word is "Previous" / "Next"; "4 weeks" is `sr-only`, so the visible
label is contained in the accessible name and the arrows are `aria-hidden`). An
available control is a plain `<a>` to `WindowNavigation::$previousUrl` / `$nextUrl`;
an unavailable one is a `<button type="button" disabled>` with a dashed border and
muted ink — a disabled button is announced as disabled and is not a link, which a
span or an `<a>` with no `href` is not. Navigation is full page loads: no script, no
live region, and the request triggers no write and no outbound call (FR-017).
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
sub-line and a list of runs — name and a `<dl>` of Distance,
Moving time, Avg pace, Ascent, Heart rate (`<x-run-metric>` prints a missing
metric as an em dash read aloud as "not recorded", never `0`). The detail carries
no link and no source label (`US-021`): the only source is the import, and nothing
leaves the page.

`resources/js/app.js` does the rest, with no dependency: a delegated click on
`[data-day]` clones the day's template into the dialog and calls `showModal()`
(Enter and Space click a `<button>`); the close button, Esc, and a click on the
backdrop close it; on `close` the opening cell gets `is-open` removed and focus
back. Opening reads only the page, so it writes no row and calls no endpoint for
(FR-017). Without JS the cells are buttons that do
nothing; their screen-reader text still names the day, distance and run count.

Layout follows `.larapilot/mockups/US-012/`: a bottom sheet below 640 px
(`max-height: 85dvh`), a centred dialog from `sm`, slide/fade only under
`prefers-reduced-motion: no-preference`, light and dark from the existing tokens
plus one `--backdrop`. Tailwind's preflight zeroes `margin`, which the browser
uses to centre a modal, so `.day-dlg` restores `margin: auto` from `sm`. The
button fills the whole `<td>` (padding 2 / 3 / 4 px by breakpoint, the gap the
`<td>` had) so the target is the full column width — 25.8 px at 320 px — while
the visible square, the `<colgroup>` widths and the ramp are untouched.

The page is **read-only**: a request writes no row to `users` or `activities` and
sends no HTTP request. The session store and, on a grid-cache miss, the cache store
still write.

`<x-session-notices />` renders above the heading on both the dashboard and the
empty state: the one-off `status` flash (for example "Import cancelled. Nothing was
written."). Both views carry it because the import redirects to `dashboard`, which
the owner sees as the map once there is a run, and as the empty state otherwise.

**Owner UI.** One line under the map: "Import Garmin CSV · Sign out" — text links
(44 px tap targets; `--accent` ink, global `focus-visible` ring; the `·` separator
is `aria-hidden`). It is not a header button: the 375 px header has no room for a
second control and the line must stay discreet on a page whose job is the map.
Sign out is a native `<form method="POST">` with `@csrf` and a submit button, so
it is keyboard reachable without script and cannot be triggered by a link or
prefetch (US-019, see `owner-authentication.md`). SEO contract:
`<meta name="robots" content="noindex, nofollow">` only (decision
`4918d74ff18e8b0f`).

**Empty state (`US-021`).** When the owner has no run, `dashboard-empty.blade.php`
shows the logo mark, "See your training at a glance", "Import a Garmin Connect CSV
export to build your 16-week heatmap.", one full-width primary "Import Garmin CSV"
button (44 px) and a secondary "Sign out" form button. The owner's first confirmed
import gives them a run, and the next visit to `/` shows the map instead.

A confirmed import retires every cached window grid of the written athlete (`HeatmapGridCache::forgetAthlete()`), so the map shows the imported runs on
the next request in whichever window is opened, and "Import cancelled. Nothing was written." arrives through
`<x-session-notices />`. Garmin-imported runs are ordinary rows to the grid:
`DailyRunningDistance` sums every run alike.

**Import status (`ImportStatus`, `US-021`, FR-007)**: the chip reads "Last import
{relative}" from the newest imported row's `updated_at`, or "No runs imported yet"
with none; the footer prints the same text in lower case below 640 px, where the
chip is hidden. The detail card is "Import / Last import / Runs in window": the
absolute stamp is printed in `config('heatmap.display_timezone')`
(`HEATMAP_DISPLAY_TIMEZONE`, default `Europe/Rome`) with the relative time under
it, "Never" without one. Headings and every string come from `ImportStatus`
(`chipLabel()`, `footerLabel()`, `detailHeading()`, `lastImportLabel()`,
`lastImportRelative()`, `emptyWindowNote()`), so Blade stays markup-only. There is
no stale, failed, reconnect or first-sync state, and no outcome row: one source,
nothing to report.

**Intensity scale**: fewer than 4 running days → fixed bands (5 / 10 / 20 km)
with a "too few" note; p25 = p75 → fixed bands with a "too alike" note; otherwise
calibrated bands, upper-inclusive.

## Technical design

| Piece | Where | Role |
| --- | --- | --- |
| Route | `routes/web.php` → `dashboard` (`/`, in the `auth` + `password.set` group) | The only screen |
| Controller | `app/Http/Controllers/DashboardController.php` | The signed-in owner → grid + status; no run → the empty state |
| Window | `app/Support/Heatmap/HeatmapWindow.php` | ISO-week anchored 112-day window: `current()`, `endingInWeekOf()`, strict `fromRequestedEnd()`, `previous()`, `next()` (clamped to the current window), `isCurrent()` |
| Daily totals | `app/Support/Heatmap/DailyRunningDistance.php` | One grouped aggregate query, ranged by `Activity::onDaysBetween()` |
| Day runs | `app/Support/Heatmap/DailyRuns.php`, `app/Data/Heatmap/DayRun.php` | One query per cache miss for the window's runs, grouped by day in start order; `DayRun` is a scalars-only run with its formatting (`kilometres()`, `movingTime()`, `pace()`, `ascent()`, `heartRate()` — null when not recorded — and the Strava link from `Activity::stravaUrl()`) |
| Navigation | `app/Support/Heatmap/OldestRunDate.php`, `app/Data/Heatmap/WindowNavigation.php` | The oldest stored run (one `MIN`, outside the cache) and the heading, title, empty note and Previous / Next URLs of the shown window |
| Weekly trend | `app/Support/Heatmap/BuildWeeklyTrend.php`, `app/Data/Heatmap/WeeklyTrend.php`, `TrendBar.php`, `TrendTick.php` | `US-014`: turns the window's `WeekRow`s into the chart's bars, 4-week average, y axis and every string it prints (below) |
| Lead-in | `app/Support/Heatmap/LeadInDistance.php` | `US-014`: the three weeks before the window in one grouped query, feeding the first averages |
| Grid builder | `app/Support/Heatmap/HeatmapBuilder.php` | Cells, weeks, totals; attaches each Ran day's runs to its `DayCell` |
| Scale | `app/Support/Heatmap/CalibrateIntensityScale.php`, `app/Data/Heatmap/IntensityScale.php` | Percentiles (R-7, pinned by `.ai/rules/heatmap.md`) and fallbacks |
| Grid DTOs | `app/Data/Heatmap/HeatmapGrid.php`, `WeekRow.php`, `DayCell.php`, `LegendRow.php`, `app/Enums/DayCellState.php` | All formatting and accessible names |
| Grid cache | `app/Support/Heatmap/HeatmapGridCache.php`, `config/cache.php` (`serializable_classes`) | Key `heatmap:grid:v5:{user id}:g{generation}:{window start}:{today}`, the generation read from `heatmap:grid:generation:{user id}`; `forgetAthlete()` bumps it; TTL `heatmap.grid_cache_ttl`; the grid's classes are the cache unserialize allowlist |
| Import status | `app/Support/Garmin/ResolveImportStatus.php`, `app/Data/ImportStatus.php` | Fresh on every request; every user-facing import string |
| Views | `resources/views/dashboard.blade.php`, `layouts/app.blade.php`, `dashboard-empty.blade.php`, `components/heatmap-grid`, `window-nav`, `day-detail`, `run-metric`, `heatmap-legend`, `weekly-trend`, `import-chip`, `import-detail`, `session-notices` | Markup only |
| Theme | inline head script in `layouts/app.blade.php`, `resources/js/app.js`, `resources/css/app.css` | Light / dark / system, persisted in `localStorage` |
| Security headers | `app/Http/Middleware/SetSecurityHeaders.php`, registered with `$middleware->prepend()` in `bootstrap/app.php` (`US-018`) | Global: the four headers on every response, HSTS when `isSecure()`; a header already on the response is left alone |
| Config | `config/heatmap.php` | Literals `window_weeks` (16), `navigation_step_weeks` (4) and `grid_cache_ttl` (86 400); `display_timezone` from `HEATMAP_DISPLAY_TIMEZONE` (a deployment choice) |
| Tests | `tests/Feature/Dashboard/*`, `tests/Feature/Routing/SecurityHeadersTest.php`, `tests/Unit/Support/Heatmap/*`, `tests/Unit/Data/Heatmap/*`, `tests/Unit/Data/ImportStatusTest.php`, `tests/Unit/Support/Garmin/ResolveImportStatusTest.php` | |

A dashboard request costs one grouped query, one run query and one lead-in query on a cache miss, zero on a hit, plus
two small indexed queries for the import status and one `MIN()` for the oldest run (`US-013`).

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
under the legend below it. It has three states: the chart; the empty window ("No runs in this window, so there is
nothing to chart.", heading kept, no axis); and absent, because the empty state renders no dashboard.

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
| Import status resolved outside the cache | Cache it with the grid | A cached "last import 6 hours ago" would quietly lie (FR-007) |
| Cache the grid DTOs, with `cache.serializable_classes` listing exactly the classes the grid is made of (US-010) | `serializable_classes => true`; caching a plain array through `toArray()` / `fromArray()` mappers | Laravel 13 defaults the list to `false`, so a serializing store (`database`, `file`, `redis`) restored the grid as `__PHP_Incomplete_Class` and every cache hit answered 500. `true` reopens the gadget-chain risk the default closes; mappers on five DTOs add code for no gain over the framework's allowlist |
| A cached entry that cannot be restored is reported, rebuilt and overwritten (US-010) | Return it as is; `Cache::remember()`; `cache:clear` in the deploy notes | An entry written by an older shape of the grid — a class not yet allowlisted, a renamed `DayCellState` case, a changed property type — would otherwise fail every request until midnight. `unserialize` throws for nested failures, so the read catches `Throwable`, not only `__PHP_Incomplete_Class` |
| The day's runs live inside the cached grid (`DayCell::$runs`, US-012) | A JSON endpoint fetched when a day is opened; a second cache entry for the detail | The detail then shares the grid's one key and its one invalidation — the import's `forgetAthlete()` covers it with no new call site, and the two can never disagree. Opening a day costs no request, so it triggers no write (FR-017). The price is ~19 runs' worth of scalars per week in the entry, a few tens of KB for a 112-day window |
| A per-athlete generation counter in the cache key; one `forgetAthlete()` bumps it (US-013) | Cache tags; forgetting each window's key in a loop; one entry holding every window | Navigation means an athlete has one cached grid per window they opened, and `cache.default` is `database`, which has no tags. A loop would have to guess which windows were cached; a single entry would rebuild every window on any miss. A counter makes one write retire every window at once; the orphans expire through `grid_cache_ttl`. The counter is created with `add()` then `increment()`d, because a bare `increment()` on a missing key answers `false` on the database store and a `forever(…, 1)` fallback lets two overlapping writes both land on 1. If the counter cannot be read the request builds the grid and leaves the cache alone, so a grid is never served or stored under a guessed generation; a failed bump is reported, not thrown, since the write it follows is already committed |
| Only windows that can hold a run are cached (US-013) | Cache every requested window; clamp `to` to the oldest run; throttle `/` | `to` takes any past date and each week is a key, so a request could fill the cache table with empty grids back to year 0. A window that ends before the oldest run is built uncached (two small queries); the current window is always cached. Clamping would change what a shared link shows, and a throttle would hit the owner too |
| A `v2` segment in the cache key (US-012; `v3` since US-013, when the key gained the generation; `v4` since US-014, when the grid gained its `trend`; `v5` since US-015, when a fix changed what a grid contains) | Reuse the key and rely on the unserialize `Throwable` catch; prove the TTL covers a content fix | A readonly property an old entry never saw comes back *uninitialized*, not as a failed read: the entry passes `instanceof HeatmapGrid` and then throws on first access, outside the catch. A new key means old entries are never read. A content fix needs the same: the key carries `$today` and the TTL is 86 400 s, so a past window cached before the deploy would keep hiding its Sunday until midnight |
| Run formatting reuses `GarminImportFormat` | A second formatter in the heatmap layer | Pace, ascent, heart rate, distance and duration print the same on the review and in the day detail; one copy cannot drift |
| The URL names the window's **end** week, as a `Y-m-d` query value (`/?to=2026-09-06`, `US-013`) | A path segment; the window's start date in the URL; a page number | A query value keeps `/` the one route and the one cache key shape; the end is what the reader navigates by and survives a change of `window_weeks`, where a start date would shift every shared link; a page number says nothing in a shared link |
| A bad `to` renders the current window silently (`US-013`) | 404; 422; a redirect to `/` | The spec asks for a bad or future link to render "without an error"; an old bookmark is better served by today's map than by an error page, and the guest path stays free of any new response |
| `previous()` is not clamped to the oldest run; `next()` is clamped to the current window | Clamp both inside `HeatmapWindow` | The window is a value object that never sees the data, so how far back Previous may go is the controller's question (it disables the control); a window past the current one would hold only future cells |
| The unavailable Previous / Next control is a disabled `<button>` (US-013) | The mockup's `<span>`; an `<a>` without `href`; `aria-disabled` on a link | A span is announced as text and an `<a>` with no `href` is neither focusable nor announced as a control; `aria-disabled` on a link still looks and tabs like one. A native disabled button is announced as disabled, is not a link, and is skipped by Tab — the acceptance criterion exactly. The look is the mockup's |
| Previous is disabled from the athlete's oldest stored run, not from a fixed horizon (US-013) | A fixed number of windows; clamping inside `HeatmapWindow`; never disabling it | The reader should never page into a run of empty windows, and the value object cannot know the data. The cost is one `MIN()` per request, which the `(user_id, activity_date)` index serves, read outside the cache because the oldest run belongs to the athlete, not to any cached window |
| The import status always reads the current window (US-013) | Read the shown window | The status describes the data, not the period on screen; a past window would otherwise show a different "Runs in window" and a different empty note |
| The lead-in weeks come from their own query, `LeadInDistance` (US-014) | Widen `DailyRunningDistance` by 21 days | The intensity scale is calibrated over the shown window's daily totals only (FR-006); widening that query would let weeks the reader cannot see move the legend and every cell's level. One more grouped aggregate keeps the two concerns apart |
| Rolling average in integer metres, half-up (US-014) | Float mean and `round()` | A float mean of exact half-way values depends on summation order: the US-014 mockup README prints 24.5, 33.0 and 39.0 for weeks whose exact means are 24.55, 33.05 and 39.05 km (its generator rounded `98.19999999999999 / 4` down). Integer math reads 24.6, 33.1 and 39.1 and cannot drift; `BuildWeeklyTrendTest` pins this |
| The chart is server-rendered inline SVG in a Blade component (US-014) | Chart.js / ApexCharts; a client-rendered canvas | A charting library is a new frontend dependency the Technical Architecture rules out, would need its own accessible text alternative, and would draw nothing for a guest with scripts off. One SVG of about 20 elements costs no script, no font, no service |
| The line is drawn as segments, not one polyline (US-014) | A single `points` string | The history and partial-week rules only cut the ends of the line today, but a single polyline would draw straight across any future gap; splitting at every missing point makes that impossible by construction |
| Security headers on a **global**, outermost middleware (US-018) | The `web` group; each controller; the server config | A URL that matches no route is a 404 that never enters group middleware, and it must be unframeable too; prepended, so the 503 of maintenance mode and the 413 of an oversized upload, which other global middleware end the request with, carry the headers as well. The host is not defined yet, so a server-level header cannot be relied on, and the headers must travel with the code |
| Strict values (`DENY`, `frame-ancestors 'none'`), each set only when absent (US-018) | Overwrite always; `SAMEORIGIN` for everything | The Larapilot development dashboard frames its own mockups on purpose and sets `SAMEORIGIN` and `frame-ancestors 'self'`. A second CSP would be enforced beside it and its `'none'` would win, so the design viewer would stop loading. Global middleware runs after the route's, so "absent" is decidable. The app has no framing need of its own, so the strict values cost nothing |
| CSP limited to `frame-ancestors` (US-018) | A full `default-src` / `script-src` policy | The layout carries an inline theme script and Vite serves the assets; a wider policy would block them. The finding was framing, and `frame-ancestors` alone closes it |
| HSTS only on secure requests, without `includeSubDomains` or `preload` (US-018) | Always send it; add both directives | Browsers ignore HSTS over HTTP, but a header sent where TLS is not guaranteed misleads. The other subdomains of the host are unknown and both directives are close to irreversible once cached |
| R-7 percentiles | Nearest-rank | Only R-7 reproduces the mockup's validated legend (7.2 / 9.9 / 14.1 km) |
| Last import = newest `updated_at` of the owner's imported activities (US-011, kept by US-021) | A `users.garmin_imported_at` column; an import log table | No migration and no extra write for one stamp; the cost is that a re-import that changes nothing does not move it — the label means "data last changed by an import" |
| No warning state of any kind (US-021) | Warn when the last import is old | Nothing runs in the background, so the data cannot fall behind; an old import is the owner's own pace, not a fault. The Strava stale / failed / reconnect / first-sync states were retired with the sync (accepted mockup deviation, decision `c022654e858f07e5`) |
| The absolute import stamp is printed in `display_timezone` (US-021) | The app timezone (UTC); the browser's zone | Timestamps are stored in UTC and an evening import is the owner's next morning in UTC; a server-rendered page has no browser zone, so the owner's wall clock is a deployment setting (`HEATMAP_DISPLAY_TIMEZONE`) |
| All copy and arithmetic in DTOs / `ImportStatus` | Logic in Blade | Testable without rendering; components stay markup-only |
| `@yield('header-status')` in the layout | View composer / shared variable | Screens that extend the same layout may render no chip |
| Private home page: every route behind the session, the dashboard shows the signed-in owner only (`US-020`, FR-017 superseded) | The public read-only page with a displayed-athlete rule | The instance is the owner's alone; runs and heart rate are visible only after sign-in. The displayed-athlete selection (allowlist order, lowest-id fallback) was deleted with the public page |
| One shared `<dialog>` filled from a `<template>` per day (US-012) | One `<dialog>` per ran day; a bottom-sheet library; `<details>` | Same markup weight as the per-day dialogs but a single focus-return path; native `showModal()` gives the trap, Esc and inert page for free, where a library is a new dependency the app, which has no Alpine or Livewire, does not carry. `<details>` cannot be a modal sheet |
| No link and no source label in the day detail (US-021) | Keep a "Garmin import" source line | With one source the label says nothing, and a run never leaves the page |
| The grid cache is per athlete (`users.id`) | One global key | The key stays per user id; with one account it is one key, and it keeps working unchanged |
| "Sign out" for the signed-in owner as a POST form button in the same line (US-019) | A header button; a `GET` link; a menu | Same header-width reasoning as the line itself; a `GET` link could be triggered cross-site or by a prefetch, so it has to be a form posting with a CSRF token |
| "Import Garmin CSV" as a text line under the map (US-011) | A header button; a menu | Discreet, no header budget at 375 px, one screen with no nav. It lets the map work with CSV imports only |
| Empty state: one primary "Import Garmin CSV" button (US-021) | Keep a secondary control beside a Strava one; a text link | With one data source the import is the only action, so it is the primary control; a full-width 44 px button reads as the way in on a phone |
| Shared `session-notices` component | Duplicate the notices in both views | One copy of the cancellation and expiry copy; the caller only decides the width |
| Two-letter weekday `<abbr>` | Single letters from the PRD prose | Matches the mockup (decision log) |

## Key decisions & invariants

- **Invariant — no state of the dashboard warns.** _Breaks if:_ a stale, failed
  or reconnect state is reintroduced without a background job that can fail. The
  "Last import …" stamp means "data last changed by an import": a re-import that
  changes nothing does not move it.

- **Invariant — a view of `/` is strictly read-only.** Only a confirmed Garmin
  import writes (US-011), never the page itself. _Breaks if:_ anything on the
  path writes a domain row or makes an HTTP call — opening the page could then
  change data or send a request.
- **Invariant — `/` shows the signed-in user's runs only.** The athlete is
  `$request->user()`; no allowlist or lowest-id rule picks another one. _Breaks
  if:_ a displayed-athlete selection returns — a second `users` row (a CSV-only
  athlete left by the pre-3.0 import) would then surface on the owner's map.

- **Invariant — the import status is never cached, and never reads the shown window.**
  _Breaks if:_ it moves into the `remember()` closure, or `ResolveImportStatus` is
  called with `$window` instead of `HeatmapWindow::current($today)` — the chip and
  the import card would then change as the reader navigates.
- **Invariant — every class serialized inside a `HeatmapGrid` is listed in
  `cache.serializable_classes`.** _Breaks if:_ a DTO, enum, or value object is
  nested into the grid without joining the list — the grid then fails to
  restore and is rebuilt (and reported) on every request, with no cache
  benefit. `WeeklyTrend`, `TrendBar` and `TrendTick` are listed (`US-014`).
  `LegendRow` is not: `IntensityScale` holds scalars and derives legend rows on
  render. The list never becomes `true`.
- **Invariant — every write that changes an athlete's runs calls
  `HeatmapGridCache::forgetAthlete()`.** _Breaks if:_ a new write path (an
  import, an edit) skips it — every window already cached keeps the old data until
  midnight or the TTL.
- **Invariant — the grid cache key carries today's date.** _Breaks if:_ the key
  drops it; the cache then outlives midnight.
- **Invariant — the key's shape version changes whenever a cached class gains a
  property.** `DayCell` gained `runs` in `US-012`, so the key went from
  `heatmap:grid:{…}` to `heatmap:grid:v2:{…}`; `US-013` made it `v3` when the
  generation joined the key, `US-014` made it `v4` when `HeatmapGrid` gained
  `trend`, and `US-015` made it `v5` when a query fix changed the content of every
  cached grid (bump for a content fix too, not only a shape change). _Breaks if:_ a property is added
  without bumping it — an entry from the previous deploy then restores with an
  uninitialized readonly property and every request fails until midnight.
- **Invariant — how `activity_date` is stored, and how a window range compares it
  (`US-015`).** The column is cast `date`, so every write path — Eloquent and the
  factory, the Garmin import — stores it as `Y-m-d 00:00:00`, and
  SQLite compares it as TEXT. A range over days goes through
  `Activity::onDaysBetween($firstDay, $lastDay)`, which is half-open: `>=` the first
  day and `<` the day after the last, both as plain `Y-m-d` strings. That holds for a
  row stored as `Y-m-d` and as `Y-m-d 00:00:00` alike, so the comparison does not
  depend on the write format. `DailyRunningDistance`, `DailyRuns`, `LeadInDistance`,
  `ResolveImportStatus` all use it; the
  lead-in ends on the Sunday before the window, so a run lands on exactly one side of
  the boundary. _Breaks if:_ a range is written as `whereBetween('activity_date',
  [toDateString(), toDateString()])` — `'2026-10-11 00:00:00' <= '2026-10-11'` is false,
  so the window's last day (always a Sunday, the day of the long run) silently drops out
  of its cell, its detail, the week total, the trend's last bar and the legend's scale.
  Rejected: `whereDate()` (wraps the column in `strftime()`, so the `(user_id,
  activity_date)` index stops serving the query), an `endOfDay()` bound (hard-codes the
  `00:00:00` suffix, the same coupling to the write format), and storing plain `Y-m-d`
  with a data migration (a custom cast, mixed formats while it runs, and it breaks the
  Garmin import's Carbon-bound `whereIn`). An *exact-date* match is the one place a
  Carbon value, not a string, must be bound — see `PlanGarminImport`.
- **Invariant — runs ride only on Ran cells.** `HeatmapBuilder` attaches a day's
  runs only when the grid's own rule made it Ran (distance above 0), so a Rest or
  Future cell never opens a detail and the detail never lists a run the colour
  does not account for. `DailyRuns` and `DailyRunningDistance` read the same
  window of the same rows.
- **Invariant — the weekly trend lives inside the cached grid, never in a cache of
  its own.** _Breaks if:_ the chart is cached or built outside `HeatmapGridCache::remember()`
  — an import that calls `forgetAthlete()` would then retire the grid but leave
  a chart that disagrees with its rows. Its lead-in query runs inside the build closure, so
  a cache hit costs the chart no query.
- **Invariant — the scale is calibrated once per grid build**, so cells and legend
  cannot disagree.
- **Invariant — `noindex, nofollow` stays in the layout**, so no view change can
  drop it. The footer carries no third-party mark (`US-021` removed "Powered by
  Strava").

## Extension points & gotchas

- **Behind a TLS-terminating proxy, `isSecure()` is false until the proxy is trusted**
  (`TrustProxies`), so `Strict-Transport-Security` would silently never be sent. Deployment
  is not defined yet: configure trusted proxies when it is (`US-018`).
- A new header that must differ for one route is set by that route's middleware; the global
  one leaves an existing header alone. A page that legitimately needs framing must set both
  `X-Frame-Options` and `Content-Security-Policy`, or the stricter pair is added.
- The test suite runs the `array` store without serialization; a test that
  needs the real round trip sets `cache.default` to `database`
  (`HeatmapGridCacheTest`).
- A new query over `activity_date` ranges uses `Activity::onDaysBetween()`, never
  `whereBetween` with date strings and never `whereDate()` (`US-015`).
  `tests/Feature/Dashboard/LastDayOfWindowTest.php` stores a run through Eloquent on a
  window's last Sunday, because the bug only shows for rows written through the cast.
- `HeatmapBuilder` takes `$today` as an argument: test date rules by naming a
  date, not by travelling time.
- The empty state is a rendered view, never a redirect: a redirect from `/` to the
  import form would loop through any page that sends the owner home.
- Other `users` rows (for instance a pre-3.0 CSV-only athlete) are left in the database and are no longer displayed anywhere; merging
  them into the owner is not part of `US-020`. On a fresh `migrate:fresh --seed`
  the seeded demo athlete is the owner `giarfa` (no password until the first
  sign-in).
- **Demo dataset (`TrainingBlockSeeder`).** The 16-week block is computed from the
  current week, so it always fills exactly the current window. Since `US-013` it is
  preceded by seven weeks of Garmin-imported history (28.4, 31.0, 33.5, 36.2, 22.0,
  **0.0**, 18.6 km, oldest first; `source = garmin_csv`, no metrics, each week summing to
  its total in metres), so Previous is demonstrable after `migrate:fresh --seed`.
  The totals are the ones the `US-014` mockup draws, so the two specs share one fixture and
  the 0 km week gives the trend a rest week to show. The block itself is untouched and
  the legend over the current window does not change. Do not run `migrate:fresh --seed` on a
  database that holds real imports — point `DB_DATABASE` at a scratch file.


## Related

- Specs: `US-004`, `US-005`, `US-006`, `US-007`, `US-008`, `US-009`, `US-010`, `US-011`, `US-012`, `US-013`, `US-018`, `US-020`
- Plans: `.larapilot/plans/US-004-plan.yaml`, `US-005-plan.yaml`, `US-006-plan.yaml`, `US-007-plan.yaml`, `US-009-plan.yaml`, `US-010-plan.yaml`, `US-013-plan.yaml`, `US-020-plan.yaml`
- Review: `.larapilot/docs/review/US-004.md`, `US-005.md`, `US-006.md`, `US-007.md`, `US-009.md`, `US-010.md`
- Other domains: `owner-authentication.md`, `activity-sync.md`, `garmin-csv-import.md`
