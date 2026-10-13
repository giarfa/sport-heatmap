# Activity sync

| | |
| --- | --- |
| **Status** | Active |
| **Introduced by** | `US-003` |
| **Last updated by** | `US-012` — 2026-10-13 |
| **Owner persona** | 🔗 Matt |

## Purpose

Keeps the local `activities` table equal to what Strava reports for the 112-day
heatmap window, once a night. It fetches every running activity in the window,
upserts it, deletes local Strava-sourced rows Strava no longer returns, leaves
runs imported or won by a Garmin CSV import alone, and records each attempt
in `sync_runs` so the dashboard can say whether the map is current. If it stops
working, the map freezes at the last good sync and the dashboard warns about it.

## Functional flow

1. The scheduler runs `strava:sync` daily at `strava.sync_hour`:00 in
   `strava.sync_timezone` (defaults 3 and `Europe/Rome`), `withoutOverlapping()`.
2. The command selects **connected, allowlisted** athletes: Strava id and both
   tokens present, and `AthleteAllowlist::allows()` true. The **CSV-only
   athlete** a guest's Garmin import creates (US-011, no Strava id, no tokens)
   is never selected: nothing is fetched, nothing is logged, and its runs are
   never reconciled or deleted. With only that athlete stored, step 3 applies.
3. No eligible athlete → prints an info line and exits successfully **without**
   writing a `sync_runs` row.
4. Otherwise it creates a `sync_runs` row with status `running`, then for each
   athlete:
   1. `StravaClient::activitiesInWindow()` obtains a fresh token, then pages
      through `GET /athlete/activities?after={window start}&per_page=200`
      until a short page, capped at `max_activity_pages` (10). Hitting the cap
      logs a warning but is not a failure.
   2. Each item becomes a `StravaActivity` DTO; non-running sports and dates
      outside the window are dropped.
   3. `ReconcileStravaWindow` applies the complete fetch in one transaction.
      It first loads, in **one** query, every stored row it may touch (the
      window, plus any row already carrying a fetched id), then decides each
      fetched activity in this order:
      1. **Skip** — its id sits on a `garmin_csv` row (a Garmin-won twin).
         Nothing is written: Garmin wins permanently.
      2. **Link** — its id is not stored anywhere and `StartTimeMatcher` finds
         an unlinked Garmin-new row (NULL Strava id) on the same date within
         ±2 minutes. Only `strava_activity_id` is set on that row; its Garmin
         values stay, nothing is created, and the row is claimed so a second
         Strava activity cannot link to it.
      3. **Upsert** — otherwise, `updateOrCreate` on `strava_activity_id` with
         `source = strava`.

      Then delete-on-absence removes rows in the window whose id was not
      fetched — **`source = strava` rows only**. Skipped and linked rows count
      as neither upserted nor deleted.
5. On success the row becomes `succeeded` with the summed `activities_upserted`
   / `activities_deleted`, and every synced athlete's cached grid for today is
   forgotten (`HeatmapGridCache::forget()`).
6. On `StravaApiException` or `StravaReconnectRequiredException` the row becomes
   `failed` with zero counts and the exception message in `error_message`; a
   warning is logged with the last attempted athlete id; exit code is failure.

**Retry policy (per page):** `retry_attempts` (3) with `retry_backoff_ms`
(1000 / 2000 / 4000). Connection errors, 429, and 5xx retry. A 401 triggers one
forced token refresh (`rejectedToken:`) and one retry for that page; a second 401
fails the run.

## Technical design

| Piece | Where | Role |
| --- | --- | --- |
| Command | `php artisan strava:sync` — `app/Console/Commands/SyncStravaActivities.php` | Orchestrates one run |
| Schedule | `routes/console.php` | Daily, timezone-aware, `withoutOverlapping()` |
| HTTP client | `app/Services/Strava/StravaClient.php` | Token, pagination, retry/backoff, mid-sync 401 |
| Activity DTO | `app/Data/StravaActivity.php` | `fromApiPayload()`; running sports only (`app/Enums/SportType.php`) |
| Reconcile action | `app/Actions/Strava/ReconcileStravaWindow.php` | Skip / link / upsert, then Strava-only delete-on-absence, in one transaction |
| Start-time matcher | `app/Support/Garmin/StartTimeMatcher.php` | Same date, ±120 s inclusive, closest start, ties to the lowest id — shared with the Garmin import |
| Outcome DTO | `app/Data/SyncOutcome.php` | Upserted / deleted counts, summed across athletes |
| Models / tables | `app/Models/Activity.php` (`activities`), `app/Models/SyncRun.php` (`sync_runs`) | `strava_activity_id` unique and nullable; `source` per `ActivitySource`; `sync_runs` has no `user_id` |
| Run status | `app/Enums/SyncRunStatus.php` | `running`, `succeeded`, `failed`, plus detail-card label and dot modifier |
| Exceptions | `app/Exceptions/Strava/StravaApiException.php` | HTTP status and rate-limit usage only |
| Window | `app/Support/Heatmap/HeatmapWindow.php` | The shared 112-day window |
| Config | `config/strava.php` | `sync_hour`, `sync_timezone`, literal `api_base_url`, `activities_per_page`, `max_activity_pages`, `retry_attempts`, `retry_backoff_ms` |
| Env vars | `.env` | `STRAVA_SYNC_HOUR`, `STRAVA_SYNC_TIMEZONE` |
| Factories / seeders | `database/factories/ActivityFactory.php` (`garminImported()`, `garminOverwritten()`, `withoutMetrics()`, `withoutHeartRate()` states; the default definition stores a pace equal to the run's own moving-time pace), `SyncRunFactory.php`, `database/seeders/TrainingBlockSeeder.php` | Local/testing demo block for athlete `10000001`, with two Garmin-new runs (no metrics, one of them the second run of a Strava day) and one Garmin-won twin outside the current week; a few Strava runs have no heart rate |
| Tests | `tests/Feature/Strava/SyncStravaActivitiesCommandTest.php`, `StravaClientTest.php`, `ReconcileStravaWindowTest.php`, `tests/Feature/Console/ScheduleTest.php`, `tests/Unit/Data/StravaActivityTest.php` | |

### Data model

`activities.activity_date` is the local calendar date of the run, indexed
together with `user_id` (`[user_id, activity_date]`) — the shape of the
reconcile delete, the dashboard aggregate, and the Garmin import's conflict
lookup. `sync_runs.started_at` is indexed and is what "latest run" is read by.

Since `US-008`, `activities.source` (`app/Enums/ActivitySource.php`, default
`strava`) says whose values a row holds, and `strava_activity_id` is nullable.
Together they give three kinds of row:

| Kind | `source` | `strava_activity_id` | Owned by |
| --- | --- | --- | --- |
| Strava | `strava` | set | The sync: upserted, and deleted on absence |
| Garmin-new | `garmin_csv` | NULL | The Garmin import; the sync never deletes it |
| Garmin-won twin | `garmin_csv` | set | The Garmin import; the id is kept only so the sync recognises the twin and leaves it alone |

Since `US-012`, three more nullable integer columns hold the per-run metrics:
`average_pace_seconds_per_km` (whole seconds per km), `total_ascent_meters` (whole
metres) and `average_heart_rate` (whole bpm). **NULL means "not recorded"** and is
shown as an em dash; a stored `0` would read as a measurement, so there is no
column default and no writer may store `0` for a missing value. They are plain
`#[Fillable]` values (not identity), cast to `integer`, and are read only with the
run's own row, so they carry **no index**: every query still filters on
`(user_id, activity_date)` first. The migration is fully reversible
(`down()` drops the three columns, nothing else depends on them).

`Activity::stravaUrl()` returns a deep-link only for `source = strava` (FR-008):
a Garmin-won twin's Strava page shows values that are no longer the stored ones.
The unique index on `strava_activity_id` stays; SQLite allows any number of
NULLs under it. No new index was added — every query filters on
`(user_id, activity_date)` first. The `add_source_to_activities_table`
rollback is **lossy**: Garmin-new rows cannot exist in the old NOT NULL schema,
so `down()` deletes them first.

## Architectural choices

| Choice | Alternatives considered | Why this one |
| --- | --- | --- |
| Fetch every page, then reconcile once | Reconcile page by page | Delete-on-absence needs the complete id set; per-page reconcile would delete activities that sit on another page, and a retry exhausted on page 3 must not leave pages 1–2 applied |
| Delete-on-absence bounded to the window | Never delete; delete everything not seen | Strava deletions and sport changes must disappear from the map, but history outside the window must never be touched |
| Count only created or changed rows as upserted | Count every `updateOrCreate` | An idempotent re-run must report 0 churn, or `sync_runs` cannot prove idempotency |
| No `sync_runs` row when nobody is eligible | Write "succeeded 0/0" | A fabricated success would tell the dashboard a sync happened; "never synced" must stay visible |
| Skip a Garmin-won twin outright | Upsert it and let Garmin's values be re-applied | An upsert writes Strava's values and `source = strava` back; there is no later step that could restore Garmin's, so the owner's Overwrite would last until the next night |
| Link a late Strava twin to a Garmin-new row (decision `7baf70c6656a936f`) | Create it as a separate Strava row; replace the Garmin row with Strava's | A separate row counts the same run twice on the map; replacing would silently undo the import the owner confirmed |
| Delete-on-absence scoped to `source = strava` (FR-002) | Delete every row in the window not returned | A Garmin-new run was never on Strava and would be deleted the first night after its import |
| Synchronous Artisan command on the scheduler | Queued jobs | One athlete, one nightly run; a queue adds a worker to operate for no gain |
| Literal `api_base_url` | `env()` | Pointing it elsewhere would send a live bearer token to another host |
| Window anchored to the ISO week | Rolling "today − 112 days" | The bottom row must always be the current week, independent of the sync weekday |

## Key decisions & invariants

- **Invariant — reconciliation is idempotent.** Running twice against the same
  Strava data changes nothing and reports `0 upserted, 0 deleted`.
- **Invariant — Garmin wins permanently.** Once a row is `garmin_csv` the sync
  never reverts it, never re-adds its Strava twin, and never deletes it —
  including on a night Strava still returns the twin.
- **Invariant — one definition of "the same run".** Same local date, starts
  within ±2 minutes **inclusive** (120 s matches, 121 s does not), closest wins.
  The sync and the Garmin import both call `StartTimeMatcher`; never inline a
  second copy of the rule.
- **Invariant — `error_message` is safe to render.** It carries HTTP status and
  rate-limit usage only; it is shown on the dashboard (HTML-escaped).
- **Invariant — the grid cache is forgotten on success only.** A failed run must
  leave the last good grid in place.
- **Invariant — an athlete removed from the allowlist stops being synced**, the
  same rule the web guard enforces.
- **Invariant — skipping a CSV-only athlete is not a failure.** No warning or
  error is logged and no `sync_runs` row is written for it. _Breaks if:_ the
  selection stops requiring a Strava id — the sync would try to fetch with no
  token and fail the whole run.
- **Invariant — `withoutOverlapping()` stays on the schedule.** Two concurrent
  reconciles of one window race on the delete pass.

## Extension points & gotchas

- `PendingRequest::retry()` accepts an array only as its first argument; the
  backoff array is indexed inside the sleep closure on purpose.
- Tests zero `retry_backoff_ms` with `Config::set()` so the suite does not sleep.
- Start times are compared as **wall-clock** readings: the DTOs build them in
  UTC while Eloquent reads the column back in the app timezone, so comparing
  instants would shift one side by the app's UTC offset.
- `activities.user_id` is not mass-assignable; write through
  `$user->activities()`.
- `sync_runs` is instance-wide (no `user_id`): a multi-athlete instance shares one
  sync status.

## Related

- Specs: `US-003`, `US-006`, `US-008`, `US-011`
- Plans: `.larapilot/plans/US-003-plan.yaml`, `.larapilot/plans/US-008-plan.yaml`
- Review: `.larapilot/docs/review/US-003.md`
- Other domains: `strava-connection.md`, `heatmap-dashboard.md`, `garmin-csv-import.md`
