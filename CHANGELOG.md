# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
