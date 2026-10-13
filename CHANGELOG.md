# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **Pace, ascent and heart rate per run**, and a **day cell detail**. Tap, click, or press Enter or
  Space on a day with runs on the heatmap to open its detail: each run's name, distance, moving
  time, average pace (`5:23 /km`), total ascent (`45 m`) and average heart rate (`152 bpm`), with
  "View on Strava" on runs that came from Strava. A bottom sheet on a phone, a centred dialog
  from 640 px, Esc closes it. The nightly Strava sync stores the three metrics, runs already
  stored gain them on the next sync, and the Garmin CSV import reads them from the optional
  `Passo medio` / `Ascesa totale` / `FC Media` (or `Avg Pace` / `Total Ascent` / `Avg HR`)
  columns. A metric that is not recorded reads "—", never 0. The review shows the metrics of the
  stored run and the file side by side; Overwrite keeps a stored value where the file has "--",
  and a run that was already imported gains only the metrics it lacked. Signed-out visitors see
  the same detail, heart rate included (US-012).
- **Previous / Next navigation** on the heatmap. "Previous 4 weeks" moves the 16-week window four
  weeks back, "Next 4 weeks" moves it forward and stops on the current window. The period is
  always stated above the grid ("18 May – 6 Sep 2026"), and each window has its own address
  (`/?to=2026-08-09`, the Sunday of its last week), so a reload or a shared link keeps it. A
  malformed, impossible, or future date opens the current window instead of an error. Previous is
  disabled once the window holds your oldest stored run. Each window computes its own legend, the
  sync status is the same on every window, and a signed-out visitor navigates the same way
  (US-013).

### Changed

- The **Garmin CSV import keeps runs of any age**. Until now it skipped every run outside the last
  16 weeks; now only rows dated after today are skipped, and the review and summary count them as
  "Future-dated" in place of "Outside 16 weeks". Older runs go through the same conflict review,
  Overwrite / Skip, and re-import rules, and the limit per import rises from 1,000 to 5,000 runs.
  After a confirmed import or a Strava sync, every window shows the new data. The Strava sync is
  unchanged: it fetches only the last 16 weeks and never deletes an older run (US-013).

- The **Garmin CSV import is open to signed-out visitors**: every visitor sees "Import Garmin CSV"
  on the home page, and the connect screen offers it beside "Connect with Strava". A signed-out
  import lands on the displayed athlete's map; on a fresh install the first confirmed import
  creates a local CSV-only athlete (no Strava connection) that the home page then displays, with
  an "Imported …" status instead of a sync warning. The nightly sync skips that athlete. CSRF,
  the 5 MB limit, the per-IP throttle and the review step still apply. A signed-out visitor's
  review shows only the start of a clashing stored run (US-011).

## [1.0.0] - 2026-10-12

A public, read-only dashboard and a Garmin Connect CSV import alongside the Strava sync. Covers
US-007 through US-010 (EP-002 Activity sync, EP-003 Heatmap, EP-004 Maintenance).

### Added

- An owner-only **Garmin Connect CSV import**: upload the Activities export (Italian or English
  headers, metric units, max 5 MB), review every run that clashes with a stored one — same date,
  starts within ±2 minutes — side by side, choose **Overwrite** or **Skip** per run (or for all),
  and confirm. New runs are added, non-running types are counted and listed, and a summary shows
  every count. Nothing is written before confirming, the uploaded file is never stored, and
  re-importing the same file changes nothing (US-008).

### Changed

- The nightly sync deletes only Strava-sourced activities that Strava no longer returns: runs
  imported from a Garmin CSV are never deleted, runs overwritten with Garmin's values are never
  reverted or re-added, and a Strava activity that turns up later for a run imported as new is
  linked to it instead of duplicated (US-008).

- The home page is public: it shows the owner's last synced heatmap, weekly totals, legend and
  sync status read-only, without a Strava sign-in. The displayed athlete is the first connected id
  of `STRAVA_ALLOWED_ATHLETE_IDS`, in configured order; the connect screen only appears while
  nobody has connected yet. Signing in stays allowlist-only (US-007).

### Fixed

- The home page no longer answers with a server error on every visit after the first of the day:
  with a serializing cache store (`CACHE_STORE=database`, the default), the cached heatmap grid
  could not be restored and each cache hit failed. The cache now restores only the grid's own
  classes, and an entry it cannot restore is rebuilt instead of failing the page (US-010).

## [0.1.0] - 2026-09-07

First release: the complete MVP scope — a private, single-athlete Strava running heatmap. Covers
US-001 through US-006 (EP-001 Foundation, EP-002 Activity sync, EP-003 Heatmap).

### Added

- A sync-status indicator on the dashboard: a relative "synced {time}" chip when healthy, an
  explicit warning naming the reason and offering **Reconnect Strava** when the last sync failed
  or no sync has succeeded in over 48 hours, and a loading skeleton — in the grid's exact
  geometry — while a first sync is in flight. A connected account with no runs in the window
  renders the empty grid with an explanatory line rather than a blank page. Stale data is never
  presented as current: the grid still renders what it has, under the warning (US-006).
- A self-calibrating, five-level intensity scale for the heatmap: levels 1–4 are the 25th, 50th
  and 75th percentiles of the window's own non-zero daily distances (R-7 linear interpolation),
  recomputed on every request that misses the grid cache. An always-visible legend states the
  exact kilometre range of every level. Windows with fewer than four non-zero days, or whose
  percentiles collapse, fall back to the documented fixed bands (`0 / ≤ 5 / ≤ 10 / ≤ 20 / 20+ km`)
  instead (US-005).
- The 16-week heatmap dashboard: 112 daily cells laid out as 16 week-rows by 7 weekday-columns,
  Monday first, each row closed by its week total in kilometres; the current week's total is
  partial and future days in it render with a dashed, out-of-range treatment (US-004).
  Accessible from a real `<table>` with a screen-reader caption, column/row headers, and an
  accessible name per cell.
- A light/dark theme toggle in the header: first visit follows the operating system, an explicit
  choice then persists across reloads with no flash of the wrong theme (US-004).
  Includes the "Powered by Strava" attribution required by the Strava API Agreement.
- The assembled grid is cached per athlete, window and calendar day, and cleared automatically by
  a successful sync (US-004).
- A daily `strava:sync` command that reconciles the full 112-day activity window: upserts on the
  Strava activity id, deletes local activities Strava no longer returns, and keeps only running
  sport types (US-003).
- A `sync_runs` audit trail — every attempt records its start, finish, status, upserted/deleted
  counts, and the error on failure — which the dashboard's sync-status indicator will read
  (US-003).
- Strava OAuth sign-in: the dashboard is reached by authorising Strava once, with no password and
  no registration (US-002).
- An athlete allowlist read from `STRAVA_ALLOWED_ATHLETE_IDS`, which is what keeps an instance
  private (US-002).
- Transparent access-token refresh, including a configurable safety margin
  (`STRAVA_TOKEN_REFRESH_MARGIN`) so a token cannot expire mid-request (US-002).
- A reconnect state on the connect screen for when the stored refresh token is no longer
  accepted (US-002).
- The foundation schema: athlete, activity and sync-run tables, with factories and a 16-week
  demo training block (US-001).

### Security

- The athlete allowlist is enforced **before** any token, user record, or session is created, so a
  rejected attempt leaves the database untouched.
- The allowlist is re-checked on **every** authenticated request, so removing an athlete ends their
  session immediately rather than whenever it happens to expire.
- A callback granting any write scope is refused and never exchanged: read-only is a property of
  what is stored, not only of what was asked for.
- Only the `activity:read_all` scope is ever requested. The scope is a literal in
  `config/strava.php`, not an environment value, so no deployment can widen it.
- Both OAuth routes are rate limited to 10 requests per minute per IP.
- Access and refresh tokens are stored through Eloquent `encrypted` casts; the client id and
  secret live only in the environment and never reach the database or the repository.
- The OAuth callback is guarded by a single-use `state` parameter compared with `hash_equals`, and
  the session id is regenerated on login.
