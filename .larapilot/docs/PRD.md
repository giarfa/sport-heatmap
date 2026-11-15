# Product Requirements Document

**Author:** Larapilot
**Date:** 2026-09-05

## Elevator Pitch

`sport-heatmap` is a personal training dashboard that turns a runner's Garmin Connect history into a single GitHub-style heatmap: 16 week-rows by 7 weekday-columns, plus a trailing column with each week's total mileage in kilometres. Runs arrive by a daily sync with the Garmin Connect Personal API, or by uploading the "Activities" CSV exported from Garmin Connect, and the colour scale calibrates itself against the athlete's own last 16 weeks — so a dark cell always means "hard for me, right now", not "hard by some fixed rule". One glance answers the only question that matters mid-block: am I actually training, or does it just feel like it?

## Vision

Training load is easy to feel and hard to see. Strava shows individual activities beautifully but buries the shape of a training block under an infinite feed; its own weekly bars reset attention every seven days. The heatmap makes sixteen weeks legible at once — the consistency, the gaps, the taper, the week the flu hit — in a layout runners already know how to read from GitHub contribution graphs.

The product deliberately stays personal and small: one athlete, one screen, no social layer, no coaching advice, no scores. It is a mirror, not a coach. Its long-term value is that it never grows a feed.

## User Personas

### The Committed Amateur Runner _(primary — the only persona in this release)_

- **Role:** Self-coached runner following multi-week training blocks (base, build, taper), logging every run to Garmin Connect from a watch. Owner and sole user of this instance.
- **Goals:** See at a glance whether the current block is on track; spot consistency gaps and unplanned rest clusters early; compare this week's volume against the recent norm without exporting anything to a spreadsheet; check it in five seconds on a phone after a run.
- **Pain Points:** Strava's feed is optimised for individual activities and social interaction, not for the shape of a block. Weekly totals live behind taps and reset the mental frame every week. Fixed distance thresholds in third-party tools misrepresent effort — 8 km is a hard day in a recovery week and a shakeout in a peak week. Manual spreadsheets get abandoned by week three.

## Functional Requirements

### FR-001: Strava OAuth connection

**Withdrawn on 2026-10-26 (US-021).** Strava is no longer a data source and the instance holds no Strava connection, token, or athlete id. Before the withdrawal the signed-in owner could connect a Strava account through the OAuth2 authorization-code flow, restricted to an athlete-id allowlist, and the daily sync (FR-002) ran on the stored tokens.

**MoSCoW:** Won't

### FR-002: Once-daily automated Garmin sync

**MoSCoW:** Must · **Persona:** The Committed Amateur Runner
**Actor & trigger:** the scheduler, once a day, when `GARMIN_API_KEY` is set (the same sync runs on demand through FR-010)
**Behavior:** exchanges the owner's Garmin Connect Personal API key for an access token and reads the owner's running activities from the Garmin Connect Personal API. They are stored as runs on the owner's record with the same values the CSV import stores (FR-003, FR-018), plus the Garmin activity id. The first sync reads the whole history; later syncs re-read at least the last 7 days, so a run edited on Garmin is updated. A synced activity on the same date within ±2 minutes of a stored run that has no Garmin id is the same run: it is linked and refreshed from the API, with no review and no duplicate. Every attempt is recorded with its outcome for FR-007.
**Done means:**
- only running types are stored; other types are ignored and counted; a future-dated activity is skipped
- a second sync right after a first one changes nothing; a run deleted on Garmin stays stored
- a CSV-imported run matching a synced activity gains the Garmin id and the API values; no second run is created
- after a sync that writes, every window and the intensity scale show the new data
- a failed or partial sync keeps every run written before the failure and records the failure and its cause
- with no `GARMIN_API_KEY` the scheduled run calls nothing and changes nothing
- the key and the access token never appear in logs, responses, or the database
**Out of this FR:** wellness data (sleep, HRV, body battery, VO2max — Future Phases); deleting runs absent from Garmin; webhooks or push; non-running sports (FR-016)
**Depends on:** FR-003, FR-018 (shared matching rule), FR-007
**Source:** change request larapilot-feature US-022, 2026-11-14 (before: withdrawn 2026-10-26 with Strava, US-021 — "No scheduled job reads runs from anywhere") · Garmin Connect Personal API (developer.garmin.com/gc-developer-program/personal)

### FR-003: Run-only filtering and kilometre normalisation

Only Garmin CSV rows and Garmin API activities whose activity type is a running type contribute to the map; they are stored as `Run`, `TrailRun`, or `VirtualRun`. The API's running types are `running`, `street_running`, `track_running`, `indoor_running`, `treadmill_running`, `ultra_run`, `obstacle_run` (→ `Run`), `trail_running` (→ `TrailRun`), and `virtual_run` (→ `VirtualRun`). Every other activity type is ignored at import or sync time, never stored, and counted in the import or sync summary (FR-018, FR-002).

Distances are stored as integer metres to avoid floating-point drift across aggregation. All presentation is metric: day cells and week totals are rendered in kilometres to one decimal place. Miles appear nowhere in the interface.

Each run also stores, when its source carries them, its average pace (seconds per kilometre), total ascent (whole metres), and average heart rate (whole bpm). A value the source does not carry stays empty. They are shown in the day cell detail (FR-011). The extended run metrics are FR-022.

**MoSCoW:** Must

### FR-004: The 16 × 7 heatmap grid

The dashboard renders a fixed grid of 16 week-rows by 7 weekday-columns:

- Columns are weekdays in ISO-8601 order, Monday through Sunday, with persistent day-of-week headers.
- Rows are consecutive calendar weeks, oldest at the top; the bottom row is the current, in-progress week. The window is rolling and opens on the current week; window navigation (FR-019) moves it to earlier periods.
- Each cell represents one calendar day and carries that day's total running distance.
- A day's date is derived from the run's *local* start time as recorded by Garmin (the CSV export or the API's `startTimeLocal`), so a run is attributed to the day the athlete ran it regardless of server timezone.
- Days in the current week that have not happened yet are rendered as out-of-range and are visually distinct from a completed 0 km rest day.

**MoSCoW:** Must

### FR-005: Weekly total column

An eighth column closes every row with that week's total running distance in kilometres — the sum of its seven day cells. It is styled as a distinct summary column, not as another heat cell, so it never competes with the map for attention. For the current in-progress week the total covers the days elapsed so far and is marked as partial.

**MoSCoW:** Must

### FR-006: Self-calibrating intensity scale with legend

Cell shading uses five levels: level 0 for a day with no running, and four intensity levels derived from the athlete's own data in the visible window. Thresholds are the 25th, 50th, and 75th percentiles of the *non-zero* daily distances across the 112 days, recomputed after every confirmed import or sync that writes.

A legend is always visible and states the actual kilometre range of each level, so the scale is never a mystery. When the window holds too few non-zero days for percentiles to be meaningful (fewer than four running days), the application falls back to a documented fixed band set and says so in the legend rather than rendering a degenerate map.

**MoSCoW:** Must

### FR-007: Last sync and import visibility

**MoSCoW:** Must · **Persona:** The Committed Amateur Runner
**Actor & trigger:** the signed-in owner (FR-021) opening any window
**Behavior:** the dashboard states, in relative terms, the more recent of the last successful Garmin sync ("last sync 3 hours ago", FR-002, FR-010) and the last confirmed CSV import that wrote a row ("last import 3 days ago", FR-018), so the owner knows how fresh the map is. While the latest sync attempt failed, a warning names the cause until a sync succeeds.
**Done means:**
- after a successful sync — even one that wrote nothing — the status reads "last sync" with its relative time, unless a later import wrote a row
- after a confirmed import that writes at least one row, the status reads "last import" with the relative time of that import, unless a later sync succeeded
- a re-import that writes nothing does not move the import time
- with no run stored and no successful sync, the status says no runs are imported yet
- while the latest sync failed, a warning reads "Garmin key rejected — renew it" when Garmin refused the key, or "Garmin unreachable" when it timed out or answered with a server error; the next successful sync removes it
- with no `GARMIN_API_KEY` configured, no sync status or warning appears and the status behaves as an import-only instance
**Out of this FR:** reminders to import or renew the key by email or notification; a sync history page
**Depends on:** FR-002, FR-018
**Source:** change request larapilot-feature US-022, 2026-11-14 (before: "Last import visibility" — the last CSV import only, "no stale, failed, or reconnect warning appears in any state") · change request US-021, 2026-10-26

### FR-008: Strava attribution and activity deep-links

**Withdrawn on 2026-10-26 (US-021).** With no Strava data on the instance, no page carries the "Powered by Strava" attribution and no run links to Strava.

**MoSCoW:** Won't

### FR-009: Mobile-first responsive layout and accessibility

The grid is legible and usable on a phone held one-handed — the intended moment of use is immediately after a run. The 8-column layout adapts down to a 375 px viewport without horizontal scrolling of the page, day headers abbreviate, and cells keep a minimum touch-friendly size.

Colour is never the sole carrier of meaning: every cell exposes its date and exact distance as an accessible name, the legend states numeric ranges, and the intensity ramp is a single-hue sequential scale that stays ordered under common colour-vision deficiencies. Light and dark themes are both delivered, each meeting WCAG 2.2 AA contrast for text and non-text indicators.

**MoSCoW:** Must

### FR-010: Sync now

**MoSCoW:** Must · **Persona:** The Committed Amateur Runner
**Actor & trigger:** the signed-in owner (FR-021) pressing "Sync now" on the dashboard, when `GARMIN_API_KEY` is set
**Behavior:** runs the Garmin sync (FR-002) at once and reports what it did, so a run finished minutes ago reaches the map without waiting for the nightly job.
**Done means:**
- the dashboard shows "Sync now" only when `GARMIN_API_KEY` is set
- after it finishes, a summary reports new, updated, linked, ignored, and future-dated counts; on failure it states the cause (FR-007)
- only one sync runs at a time: pressing it while a sync runs is refused with a message and starts nothing
- it is a CSRF-protected `POST`, throttled, and a signed-out request is redirected to the sign-in page and calls nothing
**Out of this FR:** syncing a chosen date range; progress reporting during a long first sync
**Depends on:** FR-002, FR-007, FR-021
**Source:** change request larapilot-feature US-022, 2026-11-14 (before: withdrawn 2026-10-26 with the Strava sync, US-021)

### FR-011: Day cell detail

**MoSCoW:** Must · **Persona:** The Committed Amateur Runner
**Actor & trigger:** the signed-in owner (FR-021) tapping, clicking, or keyboard-activating a day cell that holds at least one run
**Behavior:** reveals what made up that day's distance without leaving the dashboard: for each run, its name, distance (km, one decimal), moving time, average pace (min:ss per km), total ascent (metres), and average heart rate (bpm), with `—` for a value the export did not record. No run links to an external page. A day with two runs no longer reads as one number only.
**Done means:**
- a day with runs opens its detail by tap, click, Enter, or Space; Esc closes it and returns focus to the cell
- days with no run and future days open no detail
- every value is labelled with its unit; a missing pace, ascent, or heart rate reads `—`, never `0`
- no run carries an external link
- opening the detail writes nothing (FR-017)
- after a confirmed import the detail agrees with the grid
**Out of this FR:** charts, splits, or per-kilometre data; training-load scores (Out of Scope) or any derived metric (FR-023); editing an activity
**Depends on:** FR-003, FR-018 (stored metrics), FR-009, FR-017
**Source:** interview — larapilot-feature US-012, 2026-10-12 · change request US-021, 2026-10-26

### FR-012: Disconnect and purge

**Withdrawn on 2026-10-26 (US-021):** no Strava authorisation is held, so there is nothing to revoke from the app. The owner revokes the former connection by hand on Strava's settings page, as the README states.

**MoSCoW:** Won't

### FR-013: Configurable fixed-band mode

A settings surface to switch the intensity scale from self-calibrating percentiles to fixed kilometre bands, and to edit those bands. Useful for comparing training blocks against an absolute reference rather than against themselves.

**MoSCoW:** Could

### FR-014: Adjustable window and rolling aggregates

Switching the visible window between 4, 8, and 16 weeks, plus rolling 4/8/16-week distance totals and consecutive-week streak counters alongside the map.

**MoSCoW:** Could

### FR-015: Strava webhook push subscriptions

**Withdrawn on 2026-10-26 (US-021)** with Strava itself. It was Strava's push subscriptions replacing the daily poll.

**MoSCoW:** Won't

### FR-016: Multi-sport and multi-athlete

Displaying non-running sports (rides, swims, strength) on the same map, and supporting more than one athlete per instance with public signup.

**MoSCoW:** Won't

### FR-017: Private owner dashboard

**MoSCoW:** Must · **Persona:** The Committed Amateur Runner
**Actor & trigger:** the signed-in owner (FR-021) opening the home page
**Behavior:** the home page renders the signed-in owner's heatmap, weekly totals, legend, and last sync or import status from the stored runs. A signed-out visitor is sent to the sign-in page and sees no data. The owner's record carries the Garmin-synced (FR-002) and Garmin-imported (FR-018) runs, so there is no displayed-athlete selection.
**Done means:**
- a signed-out request to the home page, or to any window URL, redirects to the sign-in page and returns no heatmap data
- signed in, the home page shows the owner's own runs
- when the owner has no run, the home page shows an empty state instead of an error, offering the Garmin CSV import, and "Sync now" when `GARMIN_API_KEY` is set
- the owner sees the "Import Garmin CSV" entry point on the dashboard, and "Sync now" when `GARMIN_API_KEY` is set (FR-010)
- viewing the home page writes no row; only a confirmed Garmin import or a Garmin sync writes
- the status reflects the latest sync or import, and the sync warning while the latest sync failed (FR-007)
- the page keeps `noindex, nofollow`
**Out of this FR:** SEO and any marketing surface; sharing the dashboard with other people; per-visitor maps (FR-016)
**Depends on:** FR-021 (sign-in), FR-002 and FR-018 (data), FR-007 (last sync or import), FR-010
**Source:** interview — larapilot-feature, 2026-10-11 · change request larapilot-feature US-020, 2026-10-18 · change request US-021, 2026-10-26 · change request US-022, 2026-11-14 (before: "offering the Garmin CSV import only", "never shows a sync warning")

### FR-018: Garmin Connect CSV import

**MoSCoW:** Must · **Persona:** The Committed Amateur Runner
**Actor & trigger:** the signed-in owner (FR-021) uploading an "Activities" CSV exported from the Garmin Connect website — one of the two ways runs enter the instance, with the Garmin sync (FR-002)
**Behavior:** parses Italian or English Garmin Connect exports, keeps only running types (FR-003) dated today or earlier, at any age, so older runs fill the windows FR-019 navigates to — and stores them on the signed-in owner's record. A signed-out visitor is sent to the sign-in page and cannot upload, review, or confirm. A CSV row whose start falls on the same date within ±2 minutes of a stored run is a conflict: before anything is written, the importer reviews every conflict side by side and chooses Overwrite or Skip per row, with apply-to-all shortcuts. Each run also stores its average pace, total ascent, and average heart rate from the optional `Passo medio` / `Avg Pace`, `Ascesa totale` / `Total Ascent`, and `FC Media` / `Avg HR` columns; a missing column, a `--` placeholder, or an unreadable value leaves that metric empty without rejecting the row. On Overwrite a `--` metric keeps the stored value; an already-imported run gains only the metrics it lacks.
**Done means:**
- the sample export (`client-materials/Activities (1).csv`) imports its `Corsa` rows as runs and ignores its `Escursionismo` rows
- an unrecognised header set, a missing required column, or a non-CSV file is rejected with an explicit message and nothing written
- no conflicting row is written until the importer confirms the review; abandoning it writes nothing
- every import route redirects a signed-out request to the sign-in page and writes nothing; the pending upload is bound to the owner's session
- Skip leaves the stored run untouched; Overwrite replaces it with the CSV values and creates no duplicate
- re-importing the same file changes nothing
- `"5:23"` pace is stored as 323 seconds per km, ascent and heart rate as whole numbers; the sample's `--` metrics import as empty, never as zero
- a run dated before the current window is stored and goes through the same conflict review; a future-dated row is skipped
- a summary reports imported, overwritten, skipped, already-imported, ignored, future-dated, and invalid counts
- a CSV row matching a run stored by the Garmin sync goes through the same review; Overwrite keeps that run's Garmin id
**Out of this FR:** the automatic sync (FR-002, FR-010); FIT/GPX/TCX files; exports in languages other than Italian and English
**Depends on:** FR-021 (sign-in), FR-003 (run-only filter)
**Source:** interview — larapilot-feature, 2026-10-11 · client-materials/Activities (1).csv · change request US-021, 2026-10-26 · change request US-022, 2026-11-14 (before: "the only way runs enter the instance"; out of this FR: "Garmin Connect API or any automatic sync")

### FR-019: Window navigation

**MoSCoW:** Must · **Persona:** The Committed Amateur Runner
**Actor & trigger:** the signed-in owner (FR-021) choosing Previous or Next above the grid
**Behavior:** the 16-week window (FR-004) moves 4 weeks back or forward and the page renders that window's grid, weekly totals, and legend. The home page opens on the current window; each window has its own URL, so a reload or a bookmark keeps it. Next never goes past the current window; Previous stops once the shown window holds the athlete's oldest stored run. The intensity scale is computed from the shown window (FR-006). All history, current and older, comes from Garmin CSV imports (FR-018).
**Done means:**
- Previous shows the window ending 4 weeks earlier; Next moves 4 weeks forward and lands on the current window at most
- Next is disabled on the current window; Previous is disabled when the shown window holds the oldest stored run, or no run is stored
- the shown period is stated as a date range above the grid
- a future, malformed, or unparseable window in the URL renders the current window without an error; a mid-week date is read as that week
- a past window renders no out-of-range future cells and no partial week total
- after a confirmed import, every window shows the new data; no cached window survives the write
- navigating writes nothing (FR-017)
- both controls are keyboard-reachable links named "Previous 4 weeks" and "Next 4 weeks"; a disabled control is announced as disabled and is not a link; no horizontal page scroll at 375 px (FR-009)
**Out of this FR:** changing the window length (FR-014); a date picker or jump-to-date
**Depends on:** FR-004, FR-006, FR-009, FR-017, FR-018
**Source:** interview — larapilot-feature US-013, 2026-10-12

### FR-020: Weekly mileage trend

**MoSCoW:** Should · **Persona:** The Committed Amateur Runner
**Actor & trigger:** the signed-in owner (FR-021) opening any window
**Behavior:** a server-rendered chart beside the grid (stacked below it on narrow screens) draws one bar per week of the shown 16-week window, oldest on the left, each bar equal to that week's total (FR-005), with a 4-week rolling average line over the bars. The chart follows window navigation (FR-019).
**Done means:**
- each bar's value equals the weekly total of the same row, to 0.1 km; the y-axis starts at 0 km
- each line point is the mean of that week and the three before it, including stored weeks before the shown window; on the current window the line stops at the last completed week; a week with fewer than three weeks of history since the oldest stored run has no point
- the in-progress week's bar is marked partial, never by colour alone
- a 0 km week shows a zero-height bar with a visible baseline; a window with no stored run shows an empty-state message instead of the chart
- the chart has an accessible name and a text alternative with each week's dates, total, and average; no horizontal page scroll at 375 px; WCAG 2.2 AA in light and dark themes (FR-009)
- after a confirmed import the chart shows the new data; rendering it writes nothing (FR-017)
**Out of this FR:** changing the window length, rolling 4/8/16-week totals and streak counters (FR-014); targets, goals, or training advice (Out of Scope); any JavaScript charting library
**Depends on:** FR-005, FR-009, FR-017, FR-019
**Source:** interview — larapilot-feature US-014, 2026-10-12

### FR-021: Single-owner password login

**MoSCoW:** Must · **Persona:** The Committed Amateur Runner
**Actor & trigger:** the owner opening any page of the instance without a session
**Behavior:** every page except the sign-in page and the health check requires a session. The sign-in page asks for a username and password in the app's own look and feel — no HTTP basic auth. There is exactly one account, `giarfa`, created by a migration on the owner's existing record (or on a new one when no record exists) with no password; there is no registration, no second account, and no user management. While the account has no password, `giarfa` with an empty password signs in and every page except changing the password and signing out leads to a forced password change. Once a password is set, only that password signs in.
**Done means:**
- a signed-out request to any route other than `/login` and `/up` redirects to `/login`; after sign-in the owner lands on the page first requested
- a wrong username or password shows one generic error that does not say which was wrong, and the username stays filled in
- more than 5 failed attempts per username and IP within a minute are refused with the seconds to wait
- sign-in regenerates the session and issues no remember-me cookie; sign-out returns to `/login`
- the migration gives the owner's record the username `giarfa` and no password without touching its runs, or creates the record when the database has none
- while no password is set, `giarfa` with an empty password signs in and is redirected to the change-password page from every other route except sign-out
- a new password needs at least 12 characters and a matching confirmation; an empty, short, or mismatched one is refused with a message at the field; the stored password is hashed
- once a password is set, the empty password is refused and the change is no longer forced
- the sign-in and change-password pages use the app layout, meet FR-009 (375 px, light and dark, WCAG 2.2 AA, labelled fields, announced errors), and keep `noindex, nofollow` and the security headers
**Out of this FR:** registration or a second account; password reset by email; a voluntary password change after the first one; two-factor authentication; HTTP basic auth
**Depends on:** FR-009 (layout and accessibility)
**Source:** interview — larapilot-feature US-020, 2026-10-18

### FR-022: Extended run metrics

**MoSCoW:** Must · **Persona:** The Committed Amateur Runner
**Actor & trigger:** the Garmin sync (FR-002, FR-010) reading an activity, or the signed-in owner importing a Garmin CSV (FR-018)
**Behavior:** each run also stores, when its source carries them: calories, maximum heart rate, aerobic training effect, average and maximum running cadence, best pace, total descent, average stride length, average vertical oscillation, average vertical ratio, average ground contact time and balance, steps, lap count, elapsed time, and minimum and maximum elevation. The CSV also carries Training Stress Score. The API also carries time in heart-rate zones 1–5, the fastest 1 km, 1 mile, and 5 km splits, and the location name. A value the source does not carry stays empty, never zero. Re-importing an older export fills the extended metrics of runs imported before they existed. The metrics are stored for later use; no page shows them yet.
**Done means:**
- the sample export's `Corsa` rows store the extended values of their Italian columns; an English export stores the same values from its English headers
- a missing column, a `--` placeholder, or an unreadable value leaves that metric empty and never rejects the row
- re-importing an export over runs that lack extended metrics fills them without a conflict review and reports them as enriched; a second re-import changes nothing
- on Overwrite a `--` metric keeps the stored value
- a Garmin sync stores the extended metrics of every run it reads, a linked CSV run included
- no start or end coordinates are stored
- the day cell detail, grid, legend, and trend are unchanged
**Out of this FR:** showing the extended metrics (Future Phases); training-load scores (Out of Scope) or any derived metric the product computes itself (FR-023); laps, splits, or samples beyond the summary values
**Depends on:** FR-002, FR-003, FR-018
**Source:** interview — larapilot-feature US-023, 2026-11-14 · client-materials/Activities (1).csv headers · Garmin Connect Personal API activity search response (probed 2026-11-14)

### FR-023: Aerobic efficiency

**MoSCoW:** Should · **Persona:** The Committed Amateur Runner
**Actor & trigger:** the signed-in owner (FR-021) opening any window
**Behavior:** a tile and a server-rendered chart beside the weekly mileage trend (FR-020) show how fast the owner runs at their usual heart rate, and how that changed across the shown 16-week window (FR-019) and against the same weeks a year earlier. A run qualifies when it is at least 3 km long, has both an average pace and an average heart rate, and climbs at most 10 m per km. The reference heart rate is the median average heart rate of the window's qualifying runs, rounded to whole bpm — one value for the whole window, used on both sides of every comparison; a run more than 10 bpm above it is left out. A run's efficiency is its speed divided by its average heart rate; a run climbing 5–10 m per km uses its flat-equivalent distance (distance plus 8 × total ascent). A run's pace at the reference heart rate is one kilometre divided by its efficiency times the reference heart rate. Each week's line point is the median over that week and the three before it, weeks before the window included: flat runs (under 5 m per km) only, unless those four weeks hold fewer than 4 flat runs, when the adjusted hilly runs join.
**Done means:**
- the tile reads the latest line point as "m:ss /km at N bpm" and the difference from the window's first line point in seconds per km, worded "faster" or "slower" — never a colour, verdict, or score
- every qualifying run is a dot at its pace at the reference heart rate; adjusted hilly runs are drawn hollow; runs above 10 m per km are drawn with a third marker and never move the line; faster paces sit higher on the chart
- a four-week span with fewer than 3 usable runs has no line point; the line breaks there and is never interpolated
- the same weeks 52 weeks earlier are drawn as a dashed line computed with the shown window's reference heart rate, broken where it has no point, and hidden when no run is stored a year earlier
- with fewer than 8 qualifying runs in the window, the tile and the chart state that there are not enough runs to measure efficiency, with the count; a window with no stored run shows the empty state
- a run without a pace or a heart rate is never drawn and never reads as 0
- after an import or sync that writes, the tile and chart show the new data; rendering them writes nothing (FR-017)
- the chart has an accessible name and a text alternative with each week's dates, pace, last year's pace, and run count; markers and lines are never told apart by colour alone; no horizontal page scroll at 375 px; WCAG 2.2 AA in light and dark themes (FR-009)
**Out of this FR:** total descent or point-to-point adjustment; a calibration readout for the hill factor; temperature or weather; VO2max or race predictions; training-load scores, targets, or advice (Out of Scope); changing the window length (FR-014); any JavaScript charting library
**Depends on:** FR-003, FR-009, FR-017, FR-019, FR-020
**Source:** interview — larapilot-feature US-024, 2026-11-14 (hill factor checked on the owner's whole history against the flat runs within two weeks of each run: 8 m per m of climb lands within 0.3% on runs climbing 5–10 m per km and over-corrects runs climbing 10–15 m per km by ~8%, so the adjustment stops at 10 m per km — larapilot-design US-024, 2026-11-14)

## MVP Scope

**Project Kind:** Personal
**Project Origin:** Greenfield
**Delivery Target:** MVP
**Deadlines:** None — no fixed delivery date or milestone recorded.

### In Scope

- Single-owner username and password sign-in, with a forced password change at the first sign-in (FR-021).
- Run-only ingestion from Garmin CSV rows (`Run`, `TrailRun`, `VirtualRun`), metres stored, kilometres displayed (FR-003).
- The 16 × 7 grid, ISO-8601 Monday-first weekday columns, oldest week at top, current week at the bottom, future days rendered as out-of-range (FR-004).
- Trailing weekly-total column with partial marking for the in-progress week (FR-005).
- Percentile-based self-calibrating five-level intensity scale, recomputed after each confirmed import, with a numeric legend and a documented fallback for sparse windows (FR-006).
- Last sync or import indicator, with a warning while the latest Garmin sync failed (FR-007).
- Daily Garmin Connect Personal API sync of runs, the whole history on the first run, plus a "Sync now" button (FR-002, FR-010).
- Extended run metrics stored from the Garmin API and the CSV, not yet displayed (FR-022).
- Mobile-first responsive layout down to 375 px, light and dark themes, WCAG 2.2 AA, no colour-only encoding (FR-009).
- Private home page showing the signed-in owner's heatmap (FR-017).
- Garmin Connect CSV import, with per-conflict Overwrite/Skip review, alongside the Garmin sync (FR-018).
- Day-cell detail with per-run distance, moving time, average pace, total ascent, and average heart rate (FR-011).
- Previous / Next navigation moving the 16-week window by 4 weeks, back to the oldest stored run (FR-019).
- Weekly mileage trend chart — weekly-total bars with a 4-week rolling average, beside the grid (FR-020).
- Aerobic efficiency tile and chart — pace at the owner's usual heart rate across the window, with last year's same weeks (FR-023).

### Out of Scope

- **Multi-sport display and multi-athlete support (FR-016)** — the intensity scale is only meaningful across comparable efforts, and a 60 km ride in the same cell as an 8 km run destroys it. Multi-athlete would turn a personal dashboard into an application with signup, quotas, and a full privacy surface; that is a different product.
- **Any social, coaching, or advisory layer** — no training-load scores, no targets, no recommendations, no comparisons against other athletes. The product is a mirror: descriptive trends computed from the owner's own runs (FR-023) are allowed, and they state numbers, never verdicts.
- **Strava** (FR-001, FR-008, FR-012, FR-015 — withdrawn 2026-10-26, US-021) — no Strava connection, token, webhook, attribution, or deep-link remains, and the runs once synced from Strava were deleted. Runs enter through the Garmin sync (FR-002, FR-010) and the Garmin CSV import (FR-018).
- **Garmin webhooks and push** — the Personal API is pulled once a day and on demand; nothing listens for Garmin.
- **Miles, or any imperial unit anywhere in the interface.**
- **SEO and any marketing surface** — the dashboard is private (FR-017); the sign-in page is the only page a visitor reaches, served `noindex, nofollow`.
- **User management** — registration, a second account, password reset by email, and two-factor authentication (FR-021). One owner, one account.

### Future Phases

- Configurable fixed-band intensity mode (FR-013).
- Adjustable window and rolling aggregates / streaks (FR-014).
- Showing the extended run metrics (FR-022) in the day cell detail — stored since release 3.1.0, kept out of the detail by the owner's choice on 2026-11-14.
- Garmin wellness data — sleep, HRV, body battery, VO2max, stress — which the Personal API offers per day; a new product area beyond the running heatmap.
- **Sophia:** support model is self-support — issues are tracked in the repository, there is no SLA and no external intake channel.

## Technical Architecture

**Budget Sensitivity:** Relaxed — Personal project; Aurora ran no budget round. Advisory note only: this workload is a single SQLite file, a CSV upload, and a static page. It fits the smallest tier of any host; nothing here justifies managed database, cache, or queue spend.
**Frontend Topology:** Laravel-coupled
**Frontend stack (in-repo):** Blade + Tailwind CSS 4 via Vite — no JavaScript framework, no new frontend dependency.

### Stack

- **Laravel 13.30.1 on PHP 8.4**, SQLite. Blade templates with Tailwind CSS 4 and Vite 8, already installed in the repository.
- **Frontend topology:** Laravel-coupled. The heatmap is a server-rendered CSS grid; interactivity in the MVP is limited to what CSS and native HTML provide. **John:** no Livewire, no Inertia, no SPA — the page renders once a day's worth of new data and has no client state worth managing.
- **Admin panel:** none. Filament and the Laravel starter kits were considered and rejected — there is one screen and one user, and both would ship more scaffolding than the entire product.
- **Auth:** a username and password sign-in on Laravel's built-in session guard (FR-021) — no Fortify, no starter kit, no HTTP basic auth. One account, `giarfa`, created by a migration; no registration route. Every route but `/login` and `/up` requires a session (FR-017). No third-party sign-in or connection exists. **Andrew:** prior art — built-in `Auth::attempt` plus one middleware forcing the first password change; Fortify would add registration, reset, and 2FA scaffolding a one-account instance does not use.
- **Third-party credentials:** one — the owner's Garmin Connect Personal API key, `GARMIN_API_KEY` in `.env` only (empty in `.env.example`). It is created and renewed by the owner at connect.garmin.com → Personal API token, expires after 30 days to one year, and is exchanged for a 24-hour access token that is never stored in the database.
- **Local dev:** **Laravel Herd** (macOS, native PHP, `.test` domain) — chosen, not defaulted. No Sail, no Docker scaffolding.
- **Deploy / cloud / edge / observability:** **Not defined yet** — deferred to `larapilot-ship` by explicit choice. No deploy scaffolding enters the MVP backlog. The hard hosting requirements are a host that keeps the SQLite file on persistent storage and runs Laravel's scheduler every minute (`schedule:run` cron), which drives the daily Garmin sync (FR-002), and outbound HTTPS to `personal.connectapi.garmin.com`. **Jack:** when the choice is made, record it here; a public deployment also pulls in TLS and an edge/WAF decision that this private-instance MVP does not yet need.
- **Data store:** SQLite — **Mike:** correct for one athlete and roughly a hundred rows in the window; concurrency is the owner's own imports.
- **Hierarchy:** N/A — no tree structures.
- **Search:** none.
- **CLI tooling:** Artisan only — **Sarah:** one custom command, `garmin:sync`, scheduled once a day and guarded so only one sync runs at a time (FR-002). No Bash or Go CLI, no CI pipeline scripts beyond the standard test/lint job.
- **Packages:** none beyond the framework and its first-party tooling. The Garmin Personal API client is hand-rolled on Laravel's HTTP client — **Andrew:** prior art, Garmin's only official client is Python (`garmin/connect-personal-api-python`) and no maintained PHP package exists; the Garmin CSV is read with PHP's built-in CSV reader.

### Data model _(Mike)_

- **`users`** — extended with `username` (unique — the sign-in identifier, FR-021). Nullable password — empty until the first sign-in sets it; no registration. One row: the owner. The Strava athlete id and token columns were dropped (US-021).
- **`activities`** — every row comes from a Garmin CSV import (FR-018, `source` `garmin_csv`) or the Garmin sync (FR-002, `source` `garmin_api`): `user_id`, `sport_type`, `name`, `distance_meters` (integer), `moving_time_seconds` (integer), `start_date_local` (datetime), `activity_date` (date, indexed), the nullable metrics `average_pace_seconds_per_km`, `total_ascent_meters`, `average_heart_rate` (integers; empty when the source did not record them, never zero), a nullable unique `garmin_activity_id`, and the nullable extended metrics of FR-022. The index on `(user_id, activity_date)` serves the only query the product makes.
- **`garmin_syncs`** — one row per sync attempt (FR-002, FR-010): trigger, start and end time, outcome, failure cause, and the counts. It feeds the last-sync time and the warning (FR-007). The last-import time is still read from the newest CSV-imported activity's `updated_at`.
- **No materialised daily aggregate.** Grouping roughly a hundred rows by `activity_date` costs nothing; the assembled grid is cached and invalidated after every confirmed import. A `daily_mileage` table would be denormalisation without a problem to solve.

### Data import _(Matt)_

- **Garmin Connect Personal API (FR-002, FR-010, FR-022):** base `https://personal.connectapi.garmin.com`. `POST /personalapitoken-service/personal-api/personal-api-key/token` with `Authorization: ApiKey {key}` answers a raw JWT (not JSON) valid 24 hours, no refresh token; it is reused until one hour before its `exp`. Data calls send `Authorization: Bearer {token}`. Runs are read with `GET /activitylist-service/activities/search/activities` (`start`, `limit`, `startDate`, `endDate`), paged for the first full-history sync. The Personal program is for one person's own data, which is what this single-owner instance is. No rate limit is published: the sync pages politely and runs once a day plus on demand.
- **Garmin CSV (FR-018):** a manual upload of the Garmin Connect "Activities" export, parsed with PHP's built-in CSV reader (no package). The raw file is never kept.
- **Shared matching rule:** a run from either source on the same date within ±2 minutes of a stored run is the same run — the sync links and refreshes it, the CSV import sends it to the review.
- **Strava** (FR-001) was withdrawn on 2026-10-26 (US-021); the owner revokes the former Strava authorisation by hand at strava.com/settings/apps.

### SEO & discoverability _(Emma)_

Not applicable — the dashboard is private (FR-017) and the sign-in page is the only page a visitor reaches. Served with `noindex, nofollow`; no sitemap, no `robots.txt` allowances, no `llms.txt`, no other public pages.

### UX & frontend _(Elise + Joe)_

- **Visual language:** quiet and dense. The grid is the page; chrome is minimal. The GitHub contribution-graph idiom is adopted deliberately because the target user already knows how to read it without a tutorial.
- **Intensity ramp:** a single-hue sequential scale with five steps, ordered by luminance so the ordering survives deuteranopia and protanopia. Level 0 (rest) is a neutral tint clearly distinguishable from level 1 (shortest runs) and from out-of-range future days, which use a third, distinctly non-heat treatment.
- **Themes:** light and dark, both meeting WCAG 2.2 AA — including non-text contrast for cell borders and the legend swatches.
- **Mobile first:** designed at 375 px first, then 768 px and 1280 px. The 8-column row must fit the narrow viewport; day headers abbreviate to single letters, and the week-total column keeps a fixed width so the numbers stay aligned down the page.
- **Accessibility:** the grid is exposed as a table with row and column headers, every cell carries its date and exact kilometres as an accessible name, and no information is conveyed by colour alone (FR-009).
- **Brand assets:** no brand assets are commissioned for this release — a favicon is sufficient. No third-party mark appears.

### Copy & tone _(Marika)_

English interface. Terse and factual — numbers, dates, and states, no motivational voice. The dashboard never congratulates or scolds; "last import 3 days ago" and "18.4 km" are the register.

### Internationalization

Out of scope — single locale (English), metric only, dates in ISO-8601 week order. Activity dates come from the local start time in the Garmin export, which sidesteps server-timezone handling entirely; no configuration is timezone-sensitive.

### Privacy & legal _(Violet)_

Single-athlete instance holding the owner's own data, shown only to the signed-in owner (FR-017, FR-021). No third-party analytics, no error-reporting SaaS, no marketing tooling, no subprocessors. No cookie banner is required — the only cookie is the first-party session cookie. Retention is unbounded by design. Since Strava's removal (US-021) the Strava-sourced runs and tokens are deleted. The Garmin Connect Personal API (FR-002) is used under Garmin's personal terms: the owner's own data, read with the owner's own key, for a non-commercial single-user instance. No start or end coordinates are stored (FR-022). **Any change that lets a second athlete use this instance re-opens this section in full.**

**Resolved — Strava API Agreement, fallback display, public heart rate (FR-017, FR-011):** the three risks the owner accepted on 2026-10-11 and 2026-10-12 came from showing the heatmap, its fallback athlete, and average heart rate to anyone with the link. Since the sign-in (FR-021, US-020) the data is shown only to the signed-in owner, which is what the agreement's "display only to that user" clause asks, and heart rate is no longer disclosed to visitors.

### Security _(Lars)_

- **One third-party credential (FR-002):** the Garmin key lives only in `.env`; the access token lives only in the application cache until it expires. Neither is written to the database, a log line, an exception message, or a response. The only outbound call is HTTPS to `personal.connectapi.garmin.com`. The Strava token columns stay dropped.
- **Sync now (FR-010):** a CSRF-protected, throttled `POST` behind the session; it cannot start a second concurrent sync.
- **No developer tooling on a deployed host:** development-only packages (the Larapilot dashboard, Boost, Pail) are `require-dev` and every deployment runs `composer install --no-dev`. On a deployed host no `/larapilot/*` route is registered and no request — signed in or not — can read `.env`, download the database, or write files through developer tooling, whatever `APP_ENV` says.
- **Patched dependencies at ship:** `composer audit` reports no open advisory on the shipped `composer.lock`, and `.env.example` ships production-safe values (`APP_DEBUG=false`, `LOG_STACK=daily`, `LOG_LEVEL=warning`, `SESSION_SECURE_COOKIE=true`, an `https` `APP_URL`), so a host built from it shows no debug page and keeps rotated logs.
- **Security headers:** every web response carries `Content-Security-Policy: frame-ancestors 'none'`, `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, and `Referrer-Policy: same-origin`, plus `Strict-Transport-Security` on HTTPS — no page, the Garmin import review included, can be framed by another site.
- **Sign-in (FR-021):** a CSRF-protected username and password form; failed attempts throttled per username and IP; the session is regenerated on sign-in; one generic error for a wrong username or password. Passwords are hashed with the `hashed` cast and need at least 12 characters.
- **Sign-out:** a signed-in owner can sign out from the dashboard; a CSRF-protected `POST` ends the session and returns to `/login`. Sign-in issues no remember-me cookie, so a session ends with the session lifetime or on sign-out.
- **Accepted risk — empty password until the first sign-in (FR-021):** the migration creates `giarfa` with no password, by the owner's choice on 2026-10-18. Until the owner signs in and sets one, anyone who reaches the instance can sign in as `giarfa` with an empty password, set a password, and lock the owner out. Mitigation: the owner signs in right after every deploy that runs the migration. Recovery from a lost password is clearing the column, which re-arms the first sign-in.
- **Every Garmin import route that does work is throttled (FR-018):** upload, review, and confirm share the `garmin-import` limit, and reloading the review does not match the upload against the stored runs again while neither has changed.
- `SECURITY.md` and a `security.txt` are deferred while the instance is private; both become required before any public deployment.
- **Resolved — open Garmin import (FR-018):** the risk accepted on 2026-10-12 (any visitor could import and overwrite runs) is closed by the sign-in (FR-021, US-020): every import route requires a session. CSRF, the 5 MB limit, the throttle, and session-bound pending rows stay.
- **Oliver:** no red-team pass at inception — deferred to `larapilot-ship`, and only if the instance is ever exposed publicly.

### Documentation _(Albert)_

Baseline only: `README.md` covering Herd setup, the required `.env` keys, the first sign-in, creating and renewing the Garmin Personal API key, the scheduler cron entry, exporting and importing the Garmin Connect CSV, and the one-time revocation of the former Strava authorisation. No OpenAPI — there is no public API. No diagrams, no manuals.

### Maintenance & support _(Sophia)_

Self-supported. Repository issues are the intake; no SLA, no runbook beyond the README.

### Development & delivery

- **Git mode:** `GITFLOW` — one `feature/US-XXX-*` branch per spec off `develop`, one atomic Conventional Commit per task, internal PR description prepared toward `develop`. **No automatic push** and no remote PR unless explicitly requested.
- **Testing bar:** `NORMAL` — feature and unit tests, no browser or E2E suite. **Anne:** the load-bearing tests are the percentile bucketing (including the sparse-window fallback), the grid builder (week boundaries, the current partial week, future-day cells, ISO-8601 Monday start), the Garmin CSV import (parsing, run-only filter, conflict review, idempotent re-import), and the Garmin sync against faked HTTP responses (token exchange, paging, type mapping, linking, idempotence, failure causes) — no test calls Garmin. Factories for `User` and `Activity`; a seeder producing a realistic 16-week block of imported runs so the UI can be developed without a CSV.
- **Quality gates:** Pint formatting and Larastan level 5+ via `php artisan larapilot:quality`.
- **CI:** a single workflow running tests, Pint, and Larastan. No deploy stage until a target is chosen.

### Core Components

- Garmin CSV parser and import review — reads the export, filters runs, detects conflicts, and applies the owner's Overwrite/Skip choices (FR-018).
- Garmin Personal API client and `garmin:sync` — exchanges the key, pages the activity search, maps running types and metrics, links matching runs, and records each attempt (FR-002, FR-010, FR-022).
- `HeatmapBuilder` — assembles the 16 × 7 grid plus week totals from daily aggregates; owns week boundaries, the partial current week, and out-of-range future days.
- `IntensityScale` — computes percentile thresholds over non-zero days, applies the sparse-window fallback, and produces the legend ranges.
- `DashboardController` + Blade view — renders the grid, legend, and last-import indicator.
- Aerobic efficiency calculator — qualifies runs, derives the reference heart rate, hill-adjusts, and builds the rolling-median line for the window and the year before (FR-023).
- Sign-in and change-password controllers + Blade views, and the middleware that forces the first password change (FR-021).

### Performance & Scalability

Not a constraint at this scale: one athlete, ~100 rows in the window, one page, one daily scheduled sync. No queue worker is required — an import and a Sync now run synchronously inside their request, and the scheduled sync runs in the scheduler. Only the first full-history sync reads more than a page or two. The assembled grid is cached and invalidated after every confirmed import or sync that writes, so the page does no aggregation on a normal request. **Aurora:** the entire workload fits the smallest tier of any host; if scale ever changes, it changes because FR-016 was reversed, and that is a new product.

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
| 2026-10-18 | larapilot-bug → Security gap (v2.0.1 deferred findings) | Security clarified: clean `composer audit` and production-safe `.env.example` at ship; security headers on every response; sign-out with no remember-me cookie (FR-001); every working Garmin import route throttled and the review not re-matched on reload (FR-018). From the v2.0.0 assessment's Medium and Low findings deferred out of v2.0.1 |
| 2026-10-18 | larapilot-feature US-020 | Added FR-021 Single-owner password login (MoSCoW: Must): one `giarfa` account seeded by a migration with no password, forced change at first sign-in. Change request FR-001: Strava OAuth connects the signed-in owner and never signs in (before: "on callback … establishes the local session"). Change request FR-017, renamed Private owner dashboard: every page needs a session and shows the owner's runs (before: public read-only, displayed-athlete fallback rules). Change request FR-018: only the signed-in owner imports (before: any visitor; guest imports into the displayed or a new CSV-only athlete). FR-011, FR-019, FR-020 actors become the signed-in owner. Auth, Data model (`username`), SEO, Out of Scope, Core Components updated; Privacy: the three public-display risks resolved; Security: open-import risk resolved, empty-password-until-first-sign-in risk accepted (release 3.0.0) |
| 2026-10-26 | larapilot-feature US-021 | Strava removed; the Garmin CSV import is the only way runs enter the instance. Withdrawn (MoSCoW → Won't): FR-001 Strava OAuth connection, FR-002 Once-daily sync (before: Must), FR-008 Strava attribution and deep-links (before: Must), FR-010 Manual re-sync, FR-012 Disconnect and purge (before: Should), FR-015 Strava webhooks (before: Could). Change request FR-007, renamed Last import visibility (before: "Sync status visibility" — last successful sync with a stale/failed warning). FR-003, FR-004, FR-006, FR-011, FR-017, FR-018, FR-019, FR-020, FR-021 lose their Strava clauses (no Strava link, no "Connect with Strava", no sync). Existing Strava-sourced runs, Strava token columns, `sync_runs`, and `activities.strava_activity_id` are deleted. Elevator Pitch, Persona, MVP Scope, Technical Architecture (Auth, data model, Strava integration → Data import, security, privacy, docs, testing, components, performance) updated (release 3.0.0) |
| 2026-11-14 | larapilot-feature US-022, US-023 | Garmin Connect Personal API sync. Change request FR-002, renamed Once-daily automated Garmin sync (Won't → Must; before: withdrawn with Strava — "No scheduled job reads runs from anywhere"). Change request FR-010, renamed Sync now (Won't → Must). Change request FR-007, renamed Last sync and import visibility (before: last CSV import only, "no stale, failed, or reconnect warning appears in any state"). Change request FR-017 (Sync now entry point and sync warning; before: "offering the Garmin CSV import only", "never shows a sync warning") and FR-018 (before: "the only way runs enter the instance", API out of scope). FR-003, FR-004, FR-006 name the API source. Added FR-022 Extended run metrics (MoSCoW: Must), stored from the API and the CSV, not displayed. Elevator Pitch, MVP Scope (In/Out/Future Phases: extended-metric display and wellness data), Technical Architecture (credential, scheduler cron, `garmin:sync`, data model `garmin_activity_id` and `garmin_syncs`, Garmin Personal API integration, privacy, security, docs, testing, components, performance) updated (release 3.1.0) |
| 2026-11-14 | larapilot-feature US-024 | Added FR-023 Aerobic efficiency (MoSCoW: Should): pace at the owner's median heart rate across the window, hill-adjusted, with a 4-week rolling median and last year's same weeks. Out of Scope narrowed (before: no derived metric the product computes itself; now descriptive trends are allowed, training-load scores, targets, and advice stay out). FR-011 and FR-022 out-of-FR lines point to FR-023. In Scope and Core Components updated (release 3.2.0) |
| 2026-11-14 | larapilot-design US-024 | FR-023: hill adjustment capped at 10 m per km (before: 15). Runs climbing 10–15 m per km over-corrected by ~8% at the 8 m factor; they are now drawn as steep runs and never counted |
