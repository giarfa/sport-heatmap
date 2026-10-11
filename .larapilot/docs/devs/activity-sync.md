# Activity sync

| | |
| --- | --- |
| **Status** | Active |
| **Introduced by** | `US-003` |
| **Last updated by** | `US-008` — 2026-10-11 |
| **Owner persona** | 🔗 Matt |

## Purpose

Keeps the local `activities` table equal to what Strava reports for the 112-day
heatmap window, once a night. It fetches every running activity in the window,
upserts it, deletes local rows Strava no longer returns, and records each attempt
in `sync_runs` so the dashboard can say whether the map is current. If it stops
working, the map freezes at the last good sync and the dashboard warns about it.

## Functional flow

1. The scheduler runs `strava:sync` daily at `strava.sync_hour`:00 in
   `strava.sync_timezone` (defaults 3 and `Europe/Rome`), `withoutOverlapping()`.
2. The command selects **connected, allowlisted** athletes: Strava id and both
   tokens present, and `AthleteAllowlist::allows()` true.
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
| Reconcile action | `app/Actions/Strava/ReconcileStravaWindow.php` | Upsert + delete-on-absence in one transaction |
| Outcome DTO | `app/Data/SyncOutcome.php` | Upserted / deleted counts, summed across athletes |
| Models / tables | `app/Models/Activity.php` (`activities`), `app/Models/SyncRun.php` (`sync_runs`) | `strava_activity_id` unique and nullable; `source` per `ActivitySource`; `sync_runs` has no `user_id` |
| Run status | `app/Enums/SyncRunStatus.php` | `running`, `succeeded`, `failed`, plus detail-card label and dot modifier |
| Exceptions | `app/Exceptions/Strava/StravaApiException.php` | HTTP status and rate-limit usage only |
| Window | `app/Support/Heatmap/HeatmapWindow.php` | The shared 112-day window |
| Config | `config/strava.php` | `sync_hour`, `sync_timezone`, literal `api_base_url`, `activities_per_page`, `max_activity_pages`, `retry_attempts`, `retry_backoff_ms` |
| Env vars | `.env` | `STRAVA_SYNC_HOUR`, `STRAVA_SYNC_TIMEZONE` |
| Factories / seeders | `database/factories/ActivityFactory.php` (`garminImported()`, `garminOverwritten()` states), `SyncRunFactory.php`, `database/seeders/TrainingBlockSeeder.php` | Local/testing demo block for athlete `10000001`, with two Garmin-new runs and one Garmin-won twin outside the current week |
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
| Synchronous Artisan command on the scheduler | Queued jobs | One athlete, one nightly run; a queue adds a worker to operate for no gain |
| Literal `api_base_url` | `env()` | Pointing it elsewhere would send a live bearer token to another host |
| Window anchored to the ISO week | Rolling "today − 112 days" | The bottom row must always be the current week, independent of the sync weekday |

## Key decisions & invariants

- **Invariant — reconciliation is idempotent.** Running twice against the same
  Strava data changes nothing and reports `0 upserted, 0 deleted`.
- **Invariant — `error_message` is safe to render.** It carries HTTP status and
  rate-limit usage only; it is shown on the dashboard (HTML-escaped).
- **Invariant — the grid cache is forgotten on success only.** A failed run must
  leave the last good grid in place.
- **Invariant — an athlete removed from the allowlist stops being synced**, the
  same rule the web guard enforces.
- **Invariant — `withoutOverlapping()` stays on the schedule.** Two concurrent
  reconciles of one window race on the delete pass.

## Extension points & gotchas

- `PendingRequest::retry()` accepts an array only as its first argument; the
  backoff array is indexed inside the sleep closure on purpose.
- Tests zero `retry_backoff_ms` with `Config::set()` so the suite does not sleep.
- `activities.user_id` is not mass-assignable; write through
  `$user->activities()`.
- `sync_runs` is instance-wide (no `user_id`): a multi-athlete instance shares one
  sync status.

## Related

- Specs: `US-003`, `US-006`
- Plans: `.larapilot/plans/US-003-plan.yaml`
- Review: `.larapilot/docs/review/US-003.md`
- Other domains: `strava-connection.md`, `heatmap-dashboard.md`
