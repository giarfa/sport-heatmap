# Activity sync

| | |
| --- | --- |
| **Status** | Deprecated — folded into `garmin-csv-import.md` by `US-021` |
| **Introduced by** | `US-003` |
| **Last updated by** | `US-021` — 2026-10-26 |
| **Owner persona** | 🔗 Matt |

## Purpose

This domain used to keep `activities` equal to what Strava reported, once a night.
`US-021` removed Strava: there is no sync, no `strava:sync` command, no schedule, no
`sync_runs` table and no OAuth. What stays is the **`activities` data model**, which
the Garmin import writes and the dashboard reads. This file holds that model until
the schema cleanup of `US-021` (TASK-03) moves it into `garmin-csv-import.md` and
deletes this file.

## Functional flow

None. Nothing runs in the background (`php artisan schedule:list` is empty); a run
enters `activities` only when the owner confirms a Garmin CSV import
(`garmin-csv-import.md`) and leaves only with the migration that deleted the
Strava-sourced rows.

## Technical design

| Piece | Where | Role |
| --- | --- | --- |
| Model / table | `app/Models/Activity.php` (`activities`) | One row per run; `activity_date` is the local calendar date, indexed with `user_id` as `[user_id, activity_date]` — the shape of the dashboard aggregate and the import's conflict lookup |
| Source | `app/Enums/ActivitySource.php` | `source` says whose values a row holds |
| Sports | `app/Enums/SportType.php` | `Run`, `TrailRun`, `VirtualRun`: the product's whole sport vocabulary (FR-003) |
| Start-time matcher | `app/Support/Garmin/StartTimeMatcher.php` | Same date, ±120 s inclusive, closest start, ties to the lowest id — used by the import |
| Window | `app/Support/Heatmap/HeatmapWindow.php` | The shared 112-day window |
| Factories / seeders | `database/factories/ActivityFactory.php`, `database/seeders/TrainingBlockSeeder.php` | Demo data |

### Data model

Three nullable integer columns hold the per-run metrics: `average_pace_seconds_per_km`
(whole seconds per km), `total_ascent_meters` (whole metres) and `average_heart_rate`
(whole bpm). **NULL means "not recorded"** and is shown as an em dash; a stored `0`
would read as a measurement, so there is no column default and no writer may store
`0` for a missing value. They are plain `#[Fillable]` values cast to `integer`, read
only with the run's own row, so they carry **no index**: every query filters on
`(user_id, activity_date)` first.

`activities.user_id` is not mass-assignable; write through `$user->activities()`.

The legacy Strava columns (`strava_activity_id`) and the `source = strava` value are
still in the schema until TASK-03 of `US-021` drops them; no code reads or writes them
any more.

## Architectural choices

| Choice | Alternatives considered | Why this one |
| --- | --- | --- |
| Window anchored to the ISO week | Rolling "today − 112 days" | The bottom row must always be the current week |
| NULL, never `0`, for a missing metric | A column default of `0` | `0` would render as a real measurement |
| No background job at all (`US-021`) | Keep a nightly refresh of imported runs | There is one data source and it is a file the owner uploads; nothing can go stale between imports |

## Key decisions & invariants

- **Invariant — a missing metric is NULL, never `0`.** _Breaks if:_ a writer stores `0`
  for "not recorded": the day detail would print a measurement that was never taken.
- **Invariant — one definition of "the same run".** Same local date, starts within ±2
  minutes **inclusive** (120 s matches, 121 s does not), closest wins. The import calls
  `StartTimeMatcher`; never inline a second copy of the rule.
- **Invariant — window queries use `Activity::onDaysBetween()`** (`US-015`; see
  `heatmap-dashboard.md`, **how `activity_date` is stored**).

## Extension points & gotchas

- Start times are compared as **wall-clock** readings: the Garmin row builds them in
  UTC while Eloquent reads the column back in the app timezone, so comparing instants
  would shift one side by the app's UTC offset.

## Related

- Specs: `US-003`, `US-008`, `US-011`, `US-012`, `US-021` (removed the sync)
- Other domains: `heatmap-dashboard.md`, `garmin-csv-import.md`
