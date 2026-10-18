# Product Requirements Document

**Author:** Larapilot
**Date:** 2026-09-05

## Elevator Pitch

`sport-heatmap` is a personal training dashboard that turns a runner's Strava history into a single GitHub-style heatmap: 16 week-rows by 7 weekday-columns, plus a trailing column with each week's total mileage in kilometres. A once-a-day sync pulls runs from the official Strava API, and the colour scale calibrates itself against the athlete's own last 16 weeks — so a dark cell always means "hard for me, right now", not "hard by some fixed rule". One glance answers the only question that matters mid-block: am I actually training, or does it just feel like it?

## Vision

Training load is easy to feel and hard to see. Strava shows individual activities beautifully but buries the shape of a training block under an infinite feed; its own weekly bars reset attention every seven days. The heatmap makes sixteen weeks legible at once — the consistency, the gaps, the taper, the week the flu hit — in a layout runners already know how to read from GitHub contribution graphs.

The product deliberately stays personal and small: one athlete, one screen, no social layer, no coaching advice, no scores. It is a mirror, not a coach. Its long-term value is that it never grows a feed.

## User Personas

### The Committed Amateur Runner _(primary — the only persona in this release)_

- **Role:** Self-coached runner following multi-week training blocks (base, build, taper), logging every run to Strava from a watch. Owner and sole user of this instance.
- **Goals:** See at a glance whether the current block is on track; spot consistency gaps and unplanned rest clusters early; compare this week's volume against the recent norm without exporting anything to a spreadsheet; check it in five seconds on a phone after a run.
- **Pain Points:** Strava's feed is optimised for individual activities and social interaction, not for the shape of a block. Weekly totals live behind taps and reset the mental frame every week. Fixed distance thresholds in third-party tools misrepresent effort — 8 km is a hard day in a recovery week and a shakeout in a peak week. Manual spreadsheets get abandoned by week three.

## Functional Requirements

### FR-001: Strava OAuth connection and sign-in

Signing in is how the owner connects Strava, not how the dashboard is viewed: the dashboard itself is public (FR-017), and runs can reach it from a Garmin CSV import with no Strava sign-in at all (FR-018). A single "Connect with Strava" action starts the official Strava OAuth2 authorization-code flow requesting the read-only `activity:read_all` scope; on callback the application stores the athlete id, access token, refresh token, and token expiry, then establishes the local session. The stored tokens are what the daily sync (FR-002) runs on, with or without a session.

Access is restricted to an allowlist of Strava athlete ids configured in the environment: any other athlete completing the flow is rejected with an explicit message and no session, no stored tokens, and no persisted athlete record. The access token is refreshed transparently via the refresh token whenever it is expired or within a safety margin of expiring.

**MoSCoW:** Must

### FR-002: Once-daily automated activity sync

A scheduled Artisan command runs once per day at a configured local hour and reconciles the visible window against Strava:

- It requests the athlete's activities from the official Strava API for the full 112-day window currently displayed, paginating until exhausted.
- Activities inside the window are upserted, keyed on the Strava activity id, so edits made on Strava (corrected distance, changed sport type, renamed) propagate.
- Locally stored **Strava-sourced** activities that fall inside the window and are absent from the response are deleted, so activities removed on Strava disappear from the map. Activities imported from a Garmin CSV (FR-018) are never deleted by the sync.
- An activity the owner overwrote with a Garmin CSV row (FR-018) belongs to Garmin from then on: the sync never reverts its values, never re-adds its Strava twin, and never deletes it.
- Each run is recorded with start time, end time, outcome, counts of upserted and deleted activities, and any error message.

Reconciling the whole window rather than only new activities keeps the job stateless and self-healing at a cost of a handful of API requests per day, comfortably inside Strava's published rate limits.

**MoSCoW:** Must

### FR-003: Run-only filtering and kilometre normalisation

Only Strava activities whose sport type is a running type — `Run`, `TrailRun`, `VirtualRun` — contribute to the map. Every other sport is ignored at sync time and never stored.

Distances arrive from Strava in metres and are stored as integer metres to avoid floating-point drift across aggregation. All presentation is metric: day cells and week totals are rendered in kilometres to one decimal place. Miles appear nowhere in the interface.

Each run also stores, when Strava reports them, its average pace (seconds per kilometre, derived from `average_speed`), total ascent (whole metres, from `total_elevation_gain`), and average heart rate (whole bpm, from `average_heartrate`). A value Strava does not report stays empty. They are shown in the day cell detail (FR-011).

**MoSCoW:** Must

### FR-004: The 16 × 7 heatmap grid

The dashboard renders a fixed grid of 16 week-rows by 7 weekday-columns:

- Columns are weekdays in ISO-8601 order, Monday through Sunday, with persistent day-of-week headers.
- Rows are consecutive calendar weeks, oldest at the top; the bottom row is the current, in-progress week. The window is rolling and opens on the current week; window navigation (FR-019) moves it to earlier periods.
- Each cell represents one calendar day and carries that day's total running distance.
- A day's date is derived from the activity's Strava *local* start date, so an activity is attributed to the day the athlete ran it regardless of server timezone.
- Days in the current week that have not happened yet are rendered as out-of-range and are visually distinct from a completed 0 km rest day.

**MoSCoW:** Must

### FR-005: Weekly total column

An eighth column closes every row with that week's total running distance in kilometres — the sum of its seven day cells. It is styled as a distinct summary column, not as another heat cell, so it never competes with the map for attention. For the current in-progress week the total covers the days elapsed so far and is marked as partial.

**MoSCoW:** Must

### FR-006: Self-calibrating intensity scale with legend

Cell shading uses five levels: level 0 for a day with no running, and four intensity levels derived from the athlete's own data in the visible window. Thresholds are the 25th, 50th, and 75th percentiles of the *non-zero* daily distances across the 112 days, recomputed after every sync.

A legend is always visible and states the actual kilometre range of each level, so the scale is never a mystery. When the window holds too few non-zero days for percentiles to be meaningful (fewer than four running days), the application falls back to a documented fixed band set and says so in the legend rather than rendering a degenerate map.

**MoSCoW:** Must

### FR-007: Sync status visibility

The dashboard always shows when the data was last successfully refreshed, in relative terms ("synced 6 hours ago"). If the most recent sync failed or has not run for more than 48 hours, the dashboard shows an explicit warning state with the failure reason — stale data presented silently as current is the one failure mode that would make the whole product untrustworthy.

**MoSCoW:** Must

### FR-008: Strava attribution and activity deep-links

The interface carries the "Powered by Strava" attribution required by the Strava API Agreement and brand guidelines. Where individual activities are surfaced, each Strava-sourced activity links back to that activity on Strava; activities imported from a Garmin CSV (FR-018) have no Strava page and carry no deep-link.

**MoSCoW:** Must

### FR-009: Mobile-first responsive layout and accessibility

The grid is legible and usable on a phone held one-handed — the intended moment of use is immediately after a run. The 8-column layout adapts down to a 375 px viewport without horizontal scrolling of the page, day headers abbreviate, and cells keep a minimum touch-friendly size.

Colour is never the sole carrier of meaning: every cell exposes its date and exact distance as an accessible name, the legend states numeric ranges, and the intensity ramp is a single-hue sequential scale that stays ordered under common colour-vision deficiencies. Light and dark themes are both delivered, each meeting WCAG 2.2 AA contrast for text and non-text indicators.

**MoSCoW:** Must

### FR-010: Manual re-sync

An explicit control triggers the sync job on demand, for the case where a run was uploaded late or corrected on Strava and the athlete does not want to wait for the nightly job. It is rate-limited to protect the Strava quota and reports its outcome inline.

**MoSCoW:** Should

### FR-011: Day cell detail

**MoSCoW:** Must · **Persona:** The Committed Amateur Runner
**Actor & trigger:** any visitor of the dashboard, signed in or not, tapping, clicking, or keyboard-activating a day cell that holds at least one run
**Behavior:** reveals what made up that day's distance without leaving the dashboard: for each run, its name, distance (km, one decimal), moving time, average pace (min:ss per km), total ascent (metres), and average heart rate (bpm), with `—` for a value the source did not record. Strava-sourced runs link to their Strava page; Garmin-imported runs carry no link (FR-008). A day with two runs no longer reads as one number only.
**Done means:**
- a day with runs opens its detail by tap, click, Enter, or Space; Esc closes it and returns focus to the cell
- days with no run and future days open no detail
- every value is labelled with its unit; a missing pace, ascent, or heart rate reads `—`, never `0`
- a Strava-sourced run links to its Strava activity; a Garmin-imported run has no link
- a signed-out visitor sees the same detail, average heart rate included; opening it writes nothing and calls no Strava endpoint (FR-017)
- after a sync or a confirmed import the detail agrees with the grid
**Out of this FR:** charts, splits, or per-kilometre data; training-load scores or any derived metric (Out of Scope); editing an activity
**Depends on:** FR-003 (stored metrics from Strava), FR-018 (stored metrics from Garmin), FR-008, FR-009, FR-017
**Source:** interview — larapilot-feature US-012, 2026-10-12

### FR-012: Disconnect and purge

An explicit action revokes the stored Strava authorisation through Strava's deauthorisation endpoint and deletes all locally stored activities and tokens. Required by the Strava API Agreement when an athlete withdraws access, and the clean way to reset the instance.

**MoSCoW:** Should

### FR-013: Configurable fixed-band mode

A settings surface to switch the intensity scale from self-calibrating percentiles to fixed kilometre bands, and to edit those bands. Useful for comparing training blocks against an absolute reference rather than against themselves.

**MoSCoW:** Could

### FR-014: Adjustable window and rolling aggregates

Switching the visible window between 4, 8, and 16 weeks, plus rolling 4/8/16-week distance totals and consecutive-week streak counters alongside the map.

**MoSCoW:** Could

### FR-015: Strava webhook push subscriptions

Replacing or supplementing the daily poll with Strava's push subscription webhooks, so activities appear within seconds of upload instead of within a day.

**MoSCoW:** Could

### FR-016: Multi-sport and multi-athlete

Displaying non-running sports (rides, swims, strength) on the same map, and supporting more than one athlete per instance with public signup.

**MoSCoW:** Won't

### FR-017: Public read-only dashboard

**MoSCoW:** Must · **Persona:** The Committed Amateur Runner
**Actor & trigger:** anyone opening the home page, signed in or not — typically the runner on a device with no session, or someone the runner shared the link with
**Behavior:** the home page renders the displayed athlete's last synced heatmap, weekly totals, legend, and sync status without a Strava sign-in. The displayed athlete is the first id in the allowlist, in configured order, that has a connected user record; when no allowlisted athlete has connected, it is the first stored athlete (lowest id) with at least one activity of either source, whatever the allowlist says. Viewing is read-only; the one write a signed-out visitor can make is the Garmin CSV import (FR-018), offered on the dashboard and on the connect screen.
**Done means:**
- a signed-out visit to the home page returns the same grid, legend, and sync status the signed-in owner sees
- a connected allowlisted athlete is always displayed ahead of any other stored athlete
- when no allowlisted athlete has connected, the first stored athlete with at least one activity is displayed — including one removed from the allowlist
- when no stored athlete has any activity, the home page shows the connect screen instead of an error, and the connect screen offers the Garmin CSV import
- every visitor, signed in or not, sees the "Import Garmin CSV" entry point on the dashboard
- viewing the home page signed out writes no row and makes no call to Strava, and never triggers a token refresh; only a confirmed Garmin import writes
- an athlete with no Strava connection (CSV-only) shows no stale or failed sync warning; the status reflects the latest import
- the page keeps `noindex, nofollow` and the "Powered by Strava" attribution
**Out of this FR:** SEO and any marketing surface; per-visitor maps (FR-016); any signed-out write other than the Garmin CSV import (FR-018)
**Depends on:** FR-001 (connected athlete and stored tokens), FR-002 (data), FR-007 (sync status)
**Source:** interview — larapilot-feature, 2026-10-11

### FR-018: Garmin Connect CSV import

**MoSCoW:** Must · **Persona:** The Committed Amateur Runner
**Actor & trigger:** any visitor, signed in or not, uploading an "Activities" CSV exported from the Garmin Connect website — typically the runner using the dashboard with CSV imports only
**Behavior:** parses Italian or English Garmin Connect exports, keeps only running types (FR-003) dated today or earlier, at any age — not only inside the current 112-day window, so older runs fill the windows FR-019 navigates to — and stores them as Garmin-sourced activities. A signed-in owner imports into their own athlete; a signed-out visitor imports into the displayed athlete (FR-017), and when none is displayed the first confirmed import creates a local CSV-only athlete with no Strava connection. A signed-out import never calls Strava. A CSV row whose start falls on the same date within ±2 minutes of a stored activity of either source is a conflict: before anything is written, the importer reviews every conflict side by side and chooses Overwrite or Skip per row, with apply-to-all shortcuts. An overwritten activity is Garmin's permanently (FR-002). Each run also stores its average pace, total ascent, and average heart rate from the optional `Passo medio` / `Avg Pace`, `Ascesa totale` / `Total Ascent`, and `FC Media` / `Avg HR` columns; a missing column, a `--` placeholder, or an unreadable value leaves that metric empty without rejecting the row. On Overwrite a `--` metric keeps the stored value; an already-imported run gains only the metrics it lacks.
**Done means:**
- the sample export (`client-materials/Activities (1).csv`) imports its `Corsa` rows as runs and ignores its `Escursionismo` rows
- an unrecognised header set, a missing required column, or a non-CSV file is rejected with an explicit message and nothing written
- no conflicting row is written until the importer confirms the review; abandoning it writes nothing
- a signed-out visitor completes the whole import without a Strava sign-in; the pending upload is bound to that visitor's session and never visible to another
- with no athlete stored, the first confirmed import creates a CSV-only athlete that the home page then displays, and the nightly sync skips it without an error
- Skip leaves the stored activity untouched; Overwrite replaces it with the CSV values, and the next sync neither reverts nor duplicates it
- re-importing the same file changes nothing
- `"5:23"` pace is stored as 323 seconds per km, ascent and heart rate as whole numbers; the sample's `--` metrics import as empty, never as zero
- a run dated before the current window is stored and goes through the same conflict review; a future-dated row is skipped
- a summary reports imported, overwritten, skipped, already-imported, ignored, future-dated, and invalid counts
**Out of this FR:** Garmin Connect API or automatic sync; FIT/GPX/TCX files; exports in languages other than Italian and English
**Depends on:** FR-002 (source-aware reconciliation), FR-003 (run-only filter), FR-017 (displayed athlete)
**Source:** interview — larapilot-feature, 2026-10-11 · client-materials/Activities (1).csv

### FR-019: Window navigation

**MoSCoW:** Must · **Persona:** The Committed Amateur Runner
**Actor & trigger:** any visitor of the dashboard, signed in or not, choosing Previous or Next above the grid
**Behavior:** the 16-week window (FR-004) moves 4 weeks back or forward and the page renders that window's grid, weekly totals, and legend. The home page opens on the current window; each window has its own URL, so a reload or a shared link keeps it. Next never goes past the current window; Previous stops once the shown window holds the athlete's oldest stored run. The intensity scale is computed from the shown window (FR-006). Older history comes from Garmin CSV imports (FR-018); the Strava sync still covers only the current 112 days (FR-002).
**Done means:**
- Previous shows the window ending 4 weeks earlier; Next moves 4 weeks forward and lands on the current window at most
- Next is disabled on the current window; Previous is disabled when the shown window holds the oldest stored run, or no run is stored
- the shown period is stated as a date range above the grid
- a future, malformed, or unparseable window in the URL renders the current window without an error; a mid-week date is read as that week
- a past window renders no out-of-range future cells and no partial week total
- after a sync or a confirmed import, every window shows the new data; no cached window survives the write
- a signed-out visitor navigates the same way; navigating writes nothing and calls no Strava endpoint (FR-017)
- both controls are keyboard-reachable links named "Previous 4 weeks" and "Next 4 weeks"; a disabled control is announced as disabled and is not a link; no horizontal page scroll at 375 px (FR-009)
**Out of this FR:** changing the window length (FR-014); a date picker or jump-to-date; fetching Strava history older than the current 112 days
**Depends on:** FR-004, FR-006, FR-009, FR-017, FR-018
**Source:** interview — larapilot-feature US-013, 2026-10-12

### FR-020: Weekly mileage trend

**MoSCoW:** Should · **Persona:** The Committed Amateur Runner
**Actor & trigger:** any visitor of the dashboard, signed in or not, opening any window
**Behavior:** a server-rendered chart beside the grid (stacked below it on narrow screens) draws one bar per week of the shown 16-week window, oldest on the left, each bar equal to that week's total (FR-005), with a 4-week rolling average line over the bars. The chart follows window navigation (FR-019).
**Done means:**
- each bar's value equals the weekly total of the same row, to 0.1 km; the y-axis starts at 0 km
- each line point is the mean of that week and the three before it, including stored weeks before the shown window; on the current window the line stops at the last completed week; a week with fewer than three weeks of history since the oldest stored run has no point
- the in-progress week's bar is marked partial, never by colour alone
- a 0 km week shows a zero-height bar with a visible baseline; a window with no stored run shows an empty-state message instead of the chart
- the chart has an accessible name and a text alternative with each week's dates, total, and average; no horizontal page scroll at 375 px; WCAG 2.2 AA in light and dark themes (FR-009)
- after a sync or a confirmed import the chart shows the new data; rendering it writes nothing and calls no Strava endpoint (FR-017)
**Out of this FR:** changing the window length, rolling 4/8/16-week totals and streak counters (FR-014); targets, goals, or training advice (Out of Scope); any JavaScript charting library
**Depends on:** FR-005, FR-009, FR-017, FR-019
**Source:** interview — larapilot-feature US-014, 2026-10-12

## MVP Scope

**Project Kind:** Personal
**Project Origin:** Greenfield
**Delivery Target:** MVP
**Deadlines:** None — no fixed delivery date or milestone recorded.

### In Scope

- Strava OAuth2 sign-in with athlete-id allowlist, encrypted token storage, and transparent token refresh (FR-001).
- Daily scheduled reconciliation of the 112-day window against the official Strava API, with per-run outcome logging (FR-002).
- Run-only ingestion (`Run`, `TrailRun`, `VirtualRun`), metres stored, kilometres displayed (FR-003).
- The 16 × 7 grid, ISO-8601 Monday-first weekday columns, oldest week at top, current week at the bottom, future days rendered as out-of-range (FR-004).
- Trailing weekly-total column with partial marking for the in-progress week (FR-005).
- Percentile-based self-calibrating five-level intensity scale, recomputed each sync, with a numeric legend and a documented fallback for sparse windows (FR-006).
- Last-successful-sync indicator with an explicit stale/failed warning state (FR-007).
- "Powered by Strava" attribution and activity deep-links (FR-008).
- Mobile-first responsive layout down to 375 px, light and dark themes, WCAG 2.2 AA, no colour-only encoding (FR-009).
- Public, read-only home page showing the owner's last synced heatmap without a Strava sign-in (FR-017).
- Garmin Connect CSV import with per-conflict Overwrite/Skip review; Garmin wins permanently on overwrite (FR-018).
- Day-cell detail with per-run distance, moving time, average pace, total ascent, average heart rate, and Strava links for Strava-sourced runs (FR-011).
- Previous / Next navigation moving the 16-week window by 4 weeks, back to the oldest stored run (FR-019).
- Weekly mileage trend chart — weekly-total bars with a 4-week rolling average, beside the grid (FR-020).

### Out of Scope

- **Multi-sport display and multi-athlete support (FR-016)** — the intensity scale is only meaningful across comparable efforts, and a 60 km ride in the same cell as an 8 km run destroys it. Multi-athlete would turn a personal dashboard into an application with signup, quotas, and a full privacy surface; that is a different product.
- **Any social, coaching, or advisory layer** — no training-load scores, no recommendations, no comparisons against other athletes. The product is a mirror.
- **Strava history beyond the current window** — the Strava sync fetches only the current 112 days; older runs reach the dashboard through Garmin CSV imports (FR-018) and window navigation (FR-019).
- **Miles, or any imperial unit anywhere in the interface.**
- **SEO and any marketing surface** — the dashboard is publicly reachable by link (FR-017) but served `noindex, nofollow`, with no other public pages.

### Future Phases

- Manual on-demand re-sync (FR-010).
- Disconnect and purge flow (FR-012) — **Violet:** this becomes a Must, not a Should, the moment a second athlete can ever authorise this instance.
- Configurable fixed-band intensity mode (FR-013).
- Adjustable window and rolling aggregates / streaks (FR-014).
- Strava webhook push subscriptions replacing the daily poll (FR-015).
- **Sophia:** support model is self-support — issues are tracked in the repository, there is no SLA and no external intake channel.

## Technical Architecture

**Budget Sensitivity:** Relaxed — Personal project; Aurora ran no budget round. Advisory note only: this workload is a single SQLite file, one nightly HTTP job, and a static page. It fits the smallest tier of any host; nothing here justifies managed database, cache, or queue spend.
**Frontend Topology:** Laravel-coupled
**Frontend stack (in-repo):** Blade + Tailwind CSS 4 via Vite — no JavaScript framework, no new frontend dependency.

### Stack

- **Laravel 13.30.1 on PHP 8.4**, SQLite. Blade templates with Tailwind CSS 4 and Vite 8, already installed in the repository.
- **Frontend topology:** Laravel-coupled. The heatmap is a server-rendered CSS grid; interactivity in the MVP is limited to what CSS and native HTML provide. **John:** no Livewire, no Inertia, no SPA — the page renders once a day's worth of new data and has no client state worth managing.
- **Admin panel:** none. Filament and the Laravel starter kits were considered and rejected — there is one screen and one user, and both would ship more scaffolding than the entire product.
- **Auth:** Strava OAuth2 is the sole identity provider (FR-001); there is no password login and no registration route. Sign-in connects the owner's Strava account and is not required to view the dashboard (FR-017). **Lars:** `Password::defaults()` is not applicable here; the security surface is the OAuth callback, the allowlist check, and token storage. The users table keeps a nullable password column and no public registration.
- **Token storage:** access and refresh tokens use Eloquent `encrypted` casts on `TEXT` columns, so they are encrypted at rest with the application key and never queryable. Client id and secret live only in `.env`, documented in `.env.example`.
- **Local dev:** **Laravel Herd** (macOS, native PHP, `.test` domain) — chosen, not defaulted. Herd's HTTPS `.test` URL doubles as the Strava OAuth callback origin in development. No Sail, no Docker scaffolding.
- **Deploy / cloud / edge / observability:** **Not defined yet** — deferred to `larapilot-ship` by explicit choice. No deploy scaffolding enters the MVP backlog. The only hard hosting requirement is a host that can run `schedule:run` every minute via cron and keep the SQLite file on persistent storage. **Jack:** when the choice is made, record it here; a public deployment also pulls in TLS and an edge/WAF decision that this private-instance MVP does not yet need.
- **Data store:** SQLite — **Mike:** correct for one athlete and roughly a hundred rows in the window; concurrency is a single nightly writer.
- **Hierarchy:** N/A — no tree structures.
- **Search:** none.
- **CLI tooling:** Artisan only — **Sarah:** one sync command, invoked by the scheduler and (later) by FR-010. No Bash or Go CLI, no CI pipeline scripts beyond the standard test/lint job, no server shell scripting until a deploy target exists.
- **Packages:** two new dependencies are **proposed and pending approval** at implementation time — `laravel/socialite` and the community `socialiteproviders/strava` driver (Socialite ships no first-party Strava provider). **Andrew:** the alternative is hand-rolling the authorization-code exchange against Strava's `/oauth/authorize` and `/oauth/token` endpoints with Laravel's HTTP client, which is roughly sixty lines and zero dependencies; decide at planning. No other packages are added.

### Data model _(Mike)_

- **`users`** — extended with `strava_athlete_id` (unique), `strava_access_token` (encrypted text), `strava_refresh_token` (encrypted text), `strava_token_expires_at`. Nullable password; no registration.
- **`activities`** — `source` (Strava or Garmin CSV), `strava_activity_id` (unique, nullable — absent on Garmin-imported rows, kept on an overwritten Strava twin so the sync can recognise and leave it alone), `user_id`, `sport_type`, `name`, `distance_meters` (integer), `moving_time_seconds` (integer), `start_date_local` (datetime), `activity_date` (date, indexed), and the nullable metrics `average_pace_seconds_per_km`, `total_ascent_meters`, `average_heart_rate` (integers; empty when the source did not record them, never zero). The index on `(user_id, activity_date)` serves the only query the product makes.
- **`sync_runs`** — `started_at`, `finished_at`, `status`, `activities_upserted`, `activities_deleted`, `error_message`. Powers FR-007.
- **No materialised daily aggregate.** Grouping roughly a hundred rows by `activity_date` costs nothing; the assembled grid is cached and invalidated on sync completion. A `daily_mileage` table would be denormalisation without a problem to solve.

### Strava integration _(Matt)_

- **Flow:** authorization code against Strava's official OAuth2 endpoints, scope `activity:read_all` (read-only — no write scope is requested). Tokens are short-lived and refreshed from the stored refresh token before any API call when expired or near expiry.
- **Ingestion:** the athlete activities endpoint, filtered by an `after` timestamp at the start of the 112-day window, paginated at the maximum page size until the response is short.
- **Rate limits:** the reconciliation strategy costs a small number of requests per day, far inside Strava's published per-15-minute and daily application limits. Confirm the exact limits applying to this application in the Strava developer dashboard before implementation — Strava has changed the default allowance for new applications.
- **Resilience:** retry with backoff on `429` and `5xx`; a failed run is recorded and surfaced by FR-007 rather than failing silently. The daily job is idempotent — running it twice changes nothing.
- **Compliance:** attribution and deep-links per FR-008; deauthorisation per FR-012 when built.
- **Second source — Garmin CSV (FR-018):** a manual upload, parsed with PHP's built-in CSV reader (no package). The raw file is never kept; Garmin-sourced activities are outside the Strava reconciliation, and an overwritten Strava activity is never reverted by it.

### SEO & discoverability _(Emma)_

Not applicable — the dashboard is publicly reachable (FR-017) but not promoted. Served with `noindex, nofollow`; no sitemap, no `robots.txt` allowances, no `llms.txt`, no other public pages.

### UX & frontend _(Elise + Joe)_

- **Visual language:** quiet and dense. The grid is the page; chrome is minimal. The GitHub contribution-graph idiom is adopted deliberately because the target user already knows how to read it without a tutorial.
- **Intensity ramp:** a single-hue sequential scale with five steps, ordered by luminance so the ordering survives deuteranopia and protanopia. Level 0 (rest) is a neutral tint clearly distinguishable from level 1 (shortest runs) and from out-of-range future days, which use a third, distinctly non-heat treatment.
- **Themes:** light and dark, both meeting WCAG 2.2 AA — including non-text contrast for cell borders and the legend swatches.
- **Mobile first:** designed at 375 px first, then 768 px and 1280 px. The 8-column row must fit the narrow viewport; day headers abbreviate to single letters, and the week-total column keeps a fixed width so the numbers stay aligned down the page.
- **Accessibility:** the grid is exposed as a table with row and column headers, every cell carries its date and exact kilometres as an accessible name, and no information is conveyed by colour alone (FR-009).
- **Brand assets:** no brand assets are commissioned for this release — a favicon is sufficient. Strava's marks are used only as the API Agreement permits.

### Copy & tone _(Marika)_

English interface. Terse and factual — numbers, dates, and states, no motivational voice. The dashboard never congratulates or scolds; "synced 6 hours ago" and "18.4 km" are the register.

### Internationalization

Out of scope — single locale (English), metric only, dates in ISO-8601 week order. Activity dates come from Strava's local start date, which sidesteps server-timezone handling entirely; the scheduler's run hour is the only timezone-sensitive configuration.

### Privacy & legal _(Violet)_

Single-athlete instance holding the owner's own data, displayed publicly and read-only by the owner's choice (FR-017). No third-party analytics, no error-reporting SaaS, no marketing tooling, no subprocessors beyond Strava itself. No cookie banner is required — the only cookie is the first-party session cookie. Retention is unbounded within the rolling window by design. Strava's API Agreement obligations that bind this release are attribution (FR-008) and, once FR-012 ships, deletion on deauthorisation. **Any change that lets a second athlete authorise this instance re-opens this section in full.**

**Accepted risk — Strava API Agreement (FR-017):** since 11 November 2024 the agreement allows a third-party app to display a user's Strava activity data only to that user. Showing the owner's heatmap to anyone with the link goes against that clause. The owner accepted the risk on 2026-10-11: the realistic consequence is that Strava revokes this application's API access, which stops the daily sync (FR-002). The sync-failure reason is shown to visitors exactly as it is shown to the owner, also by the owner's choice.

**Accepted risk — fallback display (FR-017):** when no allowlisted athlete has connected, the home page publicly displays the first stored athlete with activities, even one removed from the allowlist. Removing an athlete from the allowlist therefore no longer hides their stored data on its own; deleting their rows does. The owner accepted this on 2026-10-11.

**Accepted risk — public heart rate (FR-011):** the day cell detail shows each run's average heart rate to every visitor. Heart rate is health data — a special category under GDPR Art. 9 — and the public dashboard (FR-017) discloses it to anyone with the link, including for a CSV-only athlete. The owner accepted this on 2026-10-12; deleting the runs, or a later change hiding the metric, is the remedy.

### Security _(Lars)_

- OAuth tokens encrypted at rest; client credentials in `.env` only, never committed.
- Athlete-id allowlist enforced server-side on the OAuth callback before any token or user record is persisted — the OAuth flow authenticates *a* Strava athlete, not *the* athlete.
- Rate limiting on the OAuth callback route and on the manual re-sync trigger (FR-010).
- Read-only Strava scope; no write capability is ever requested.
- **No developer tooling on a deployed host:** development-only packages (the Larapilot dashboard, Boost, Pail) are `require-dev` and every deployment runs `composer install --no-dev`. On a deployed host no `/larapilot/*` route is registered and no request — signed in or not — can read `.env`, download the database, or write files through developer tooling, whatever `APP_ENV` says.
- `SECURITY.md` and a `security.txt` are deferred while the instance is private; both become required before any public deployment.
- **Accepted risk — open Garmin import (FR-018):** the CSV import is open to any visitor, with no sign-in and no passphrase, by the owner's choice on 2026-10-12. Anyone who reaches the site can import runs and overwrite stored ones. Kept: CSRF, the 5 MB limit, throttling on upload and confirm, and pending rows bound to the visitor's session. Acceptable only while the instance is private; it must be re-decided before any public deployment.
- **Oliver:** no red-team pass at inception — deferred to `larapilot-ship`, and only if the instance is ever exposed publicly.

### Documentation _(Albert)_

Baseline only: `README.md` covering Herd setup, Strava application registration, the required `.env` keys, running the sync command manually, and the cron entry for the scheduler. No OpenAPI — there is no public API. No diagrams, no manuals.

### Maintenance & support _(Sophia)_

Self-supported. Repository issues are the intake; no SLA, no runbook beyond the README.

### Development & delivery

- **Git mode:** `GITFLOW` — one `feature/US-XXX-*` branch per spec off `develop`, one atomic Conventional Commit per task, internal PR description prepared toward `develop`. **No automatic push** and no remote PR unless explicitly requested.
- **Testing bar:** `NORMAL` — feature and unit tests, no browser or E2E suite. **Anne:** the load-bearing tests are the percentile bucketing (including the sparse-window fallback), the grid builder (week boundaries, the current partial week, future-day cells, ISO-8601 Monday start), and the sync reconciliation (upsert, delete-on-absence, pagination, token refresh, `429` handling) — all against a faked Strava HTTP client. Factories for `User`, `Activity`, and `SyncRun`; a seeder producing a realistic 16-week block so the UI can be developed without touching Strava.
- **Quality gates:** Pint formatting and Larastan level 5+ via `php artisan larapilot:quality`.
- **CI:** a single workflow running tests, Pint, and Larastan. No deploy stage until a target is chosen.

### Core Components

- `StravaClient` — authenticated HTTP wrapper over the Strava API: token refresh, pagination, retry/backoff, rate-limit handling.
- `SyncStravaActivities` — the daily Artisan command; reconciles the window and writes a `SyncRun` record.
- `HeatmapBuilder` — assembles the 16 × 7 grid plus week totals from daily aggregates; owns week boundaries, the partial current week, and out-of-range future days.
- `IntensityScale` — computes percentile thresholds over non-zero days, applies the sparse-window fallback, and produces the legend ranges.
- `DashboardController` + Blade view — renders the grid, legend, and sync-status indicator.

### Performance & Scalability

Not a constraint at this scale: one athlete, ~100 rows in the window, one page, one nightly job. No queue worker is required — the sync runs synchronously inside the scheduled command in well under the default timeout. The assembled grid is cached and invalidated when a sync completes, so the page does no aggregation on a normal request. **Aurora:** the entire workload fits the smallest tier of any host; if scale ever changes, it changes because FR-016 was reversed, and that is a new product.

## PRD Revision History

| Date | Trigger | Summary |
| --- | --- | --- |
| 2026-09-05 | larapilot-inception | Initial PRD |
| 2026-10-11 | larapilot-feature US-007 | Change request FR-001: sign-in no longer gates viewing (before: "Signing in to the dashboard *is* authorising Strava"). Added FR-017 Public read-only dashboard (MoSCoW: Must); Out of Scope, SEO, Auth, and Privacy & legal updated; Strava API Agreement risk accepted |
| 2026-10-11 | larapilot-feature US-008 | Added FR-018 Garmin Connect CSV import (MoSCoW: Must). Change request FR-002: delete-on-absence now Strava-sourced only and Garmin-overwritten activities are never reverted or re-added (before: every local activity absent from the response was deleted). FR-008 clarified: no deep-link for imported activities. Data model gains activity source |
| 2026-10-11 | larapilot-feature US-009 | Change request FR-017: when no allowlisted athlete has connected, the home page displays the first stored athlete with activities (before: "an athlete removed from the allowlist is never displayed" and "when no allowlisted athlete has connected yet, the home page shows the connect screen"). Privacy & legal: fallback-display risk accepted |
| 2026-10-12 | larapilot-feature US-011 | Change request FR-017: the Garmin CSV import is the one signed-out write, offered on the dashboard and connect screen; CSV-only athletes show no stale-sync warning (before: "A signed-out visit is strictly read-only", no write action for signed-out visitors). Change request FR-018: actor is any visitor (before: "the signed-in owner"); signed-out imports go to the displayed athlete or a new CSV-only athlete. FR-001 clarified. Security: open-import risk accepted |
| 2026-10-12 | larapilot-feature US-012 | FR-011 Day cell detail rewritten and raised Should → Must (before: one line — names, distances, moving times, Strava links); it now also shows average pace, total ascent, and average heart rate, with Strava links only on Strava-sourced runs. FR-003: the Strava sync stores the three metrics. FR-018: optional pace, ascent, and heart-rate columns. Data model gains three nullable metrics; FR-011 moved from Future Phases to In Scope; public heart-rate risk accepted |
| 2026-10-12 | larapilot-feature US-013 | Added FR-019 Window navigation (MoSCoW: Must): Previous / Next move the 16-week window by 4 weeks, back to the oldest stored run. Change request FR-004: the window opens on the current week (before: "always ends on the current week"). Change request FR-018: runs of any past date are imported, only future-dated rows skipped (before: "keeps only running types inside the 112-day window"; summary "outside-window" count becomes "future-dated"). Out of Scope: historical archive line narrowed to Strava history beyond the current window |
| 2026-10-12 | larapilot-feature US-014 | Added FR-020 Weekly mileage trend (MoSCoW: Should): weekly-total bars with a 4-week rolling average beside the grid, following window navigation; listed in In Scope (assigned to release 2.0.0) |
| 2026-10-18 | larapilot-bug → Security gap (US-016) | Security clarified: development-only packages are `require-dev`, deployments run `composer install --no-dev`, and no `/larapilot/*` route exists on a deployed host (v2.0.0 assessment, High finding) |
