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

Signing in is how the owner connects Strava, not how the dashboard is viewed: the dashboard itself is public and read-only (FR-017). A single "Connect with Strava" action starts the official Strava OAuth2 authorization-code flow requesting the read-only `activity:read_all` scope; on callback the application stores the athlete id, access token, refresh token, and token expiry, then establishes the local session. The stored tokens are what the daily sync (FR-002) runs on, with or without a session.

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

**MoSCoW:** Must

### FR-004: The 16 × 7 heatmap grid

The dashboard renders a fixed grid of 16 week-rows by 7 weekday-columns:

- Columns are weekdays in ISO-8601 order, Monday through Sunday, with persistent day-of-week headers.
- Rows are consecutive calendar weeks, oldest at the top; the bottom row is the current, in-progress week. The window is rolling and always ends on the current week.
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

Interacting with a day cell reveals what made up that day's distance: the activity names, their individual distances and moving times, and links to each on Strava. A day with two runs currently reads as one number; this makes the underlying activities inspectable without leaving the dashboard.

**MoSCoW:** Should

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
**Behavior:** the home page renders the displayed athlete's last synced heatmap, weekly totals, legend, and sync status without a Strava sign-in. The displayed athlete is the first id in the allowlist, in configured order, that has a connected user record. A signed-out visit is strictly read-only.
**Done means:**
- a signed-out visit to the home page returns the same grid, legend, and sync status the signed-in owner sees
- an athlete removed from the allowlist is never displayed, even while their rows remain stored
- when no allowlisted athlete has connected yet, the home page shows the connect screen instead of an error
- a signed-out visit writes no row and makes no call to Strava, and never triggers a token refresh
- the page keeps `noindex, nofollow` and the "Powered by Strava" attribution
**Out of this FR:** SEO and any marketing surface; per-visitor maps (FR-016); any write action for signed-out visitors
**Depends on:** FR-001 (connected athlete and stored tokens), FR-002 (data), FR-007 (sync status)
**Source:** interview — larapilot-feature, 2026-10-11

### FR-018: Garmin Connect CSV import

**MoSCoW:** Must · **Persona:** The Committed Amateur Runner
**Actor & trigger:** the signed-in owner, uploading an "Activities" CSV exported from the Garmin Connect website
**Behavior:** parses Italian or English Garmin Connect exports, keeps only running types inside the 112-day window (FR-003), and stores them as Garmin-sourced activities. A CSV row whose start falls on the same date within ±2 minutes of a stored activity of either source is a conflict: before anything is written, the owner reviews every conflict side by side and chooses Overwrite or Skip per row, with apply-to-all shortcuts. An overwritten activity is Garmin's permanently (FR-002).
**Done means:**
- the sample export (`client-materials/Activities (1).csv`) imports its `Corsa` rows as runs and ignores its `Escursionismo` rows
- an unrecognised header set, a missing required column, or a non-CSV file is rejected with an explicit message and nothing written
- no conflicting row is written until the owner confirms the review; abandoning it writes nothing
- Skip leaves the stored activity untouched; Overwrite replaces it with the CSV values, and the next sync neither reverts nor duplicates it
- re-importing the same file changes nothing
- a summary reports imported, overwritten, skipped, already-imported, ignored, outside-window, and invalid counts
**Out of this FR:** Garmin Connect API or automatic sync; FIT/GPX/TCX files; exports in languages other than Italian and English; history beyond the 112-day window
**Depends on:** FR-001 (signed-in owner), FR-002 (source-aware reconciliation), FR-003 (run-only filter)
**Source:** interview — larapilot-feature, 2026-10-11 · client-materials/Activities (1).csv

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

### Out of Scope

- **Multi-sport display and multi-athlete support (FR-016)** — the intensity scale is only meaningful across comparable efforts, and a 60 km ride in the same cell as an 8 km run destroys it. Multi-athlete would turn a personal dashboard into an application with signup, quotas, and a full privacy surface; that is a different product.
- **Any social, coaching, or advisory layer** — no training-load scores, no recommendations, no comparisons against other athletes. The product is a mirror.
- **Historical archive beyond the visible window** — activities outside the rolling 112 days are not retained; the window is the product. Revisit if FR-014 is ever built.
- **Miles, or any imperial unit anywhere in the interface.**
- **SEO and any marketing surface** — the dashboard is publicly reachable by link (FR-017) but served `noindex, nofollow`, with no other public pages.

### Future Phases

- Manual on-demand re-sync (FR-010).
- Day-cell detail with per-activity breakdown and Strava links (FR-011).
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
- **`activities`** — `source` (Strava or Garmin CSV), `strava_activity_id` (unique, nullable — absent on Garmin-imported rows, kept on an overwritten Strava twin so the sync can recognise and leave it alone), `user_id`, `sport_type`, `name`, `distance_meters` (integer), `moving_time_seconds` (integer), `start_date_local` (datetime), `activity_date` (date, indexed). The index on `(user_id, activity_date)` serves the only query the product makes.
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

### Security _(Lars)_

- OAuth tokens encrypted at rest; client credentials in `.env` only, never committed.
- Athlete-id allowlist enforced server-side on the OAuth callback before any token or user record is persisted — the OAuth flow authenticates *a* Strava athlete, not *the* athlete.
- Rate limiting on the OAuth callback route and on the manual re-sync trigger (FR-010).
- Read-only Strava scope; no write capability is ever requested.
- `SECURITY.md` and a `security.txt` are deferred while the instance is private; both become required before any public deployment.
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
