# Garmin API sync

| | |
| --- | --- |
| **Status** | Active |
| **Introduced by** | `US-022` |
| **Last updated by** | `US-022` — 2026-11-14 |
| **Owner persona** | 🔧 Alex |

## Purpose

A second way runs enter besides the CSV upload: the owner's Garmin Connect Personal API,
read once a day by the scheduler and on demand by a "Sync now" button. Without it the
heatmap is only as current as the last CSV the owner exported and uploaded. The CSV import
stays (`garmin-csv-import.md`); both write the same `activities` table.

## Functional flow

Entry points: `php artisan garmin:sync [--scheduled]` (the scheduler runs it daily at
05:00 in `HEATMAP_DISPLAY_TIMEZONE`, so `schedule:list` shows it in UTC) and the dashboard's
Sync now (`POST /sync/garmin`). Both call `SyncGarminActivities`; the controller runs it in
the request and flashes `GarminSyncResult::summary()`, or the already-running refusal, or "Garmin
sync is not configured." for a POST with no key (see `heatmap-dashboard.md` for the screen).

1. **No key** — the command prints "GARMIN_API_KEY is not set — nothing to sync." and exits
   0; nothing is called and nothing is recorded.
2. **Lock** — `Cache::lock('garmin:sync', 1800)` on the default store. Not acquired:
   `GarminSyncAlreadyRunningException` ("A Garmin sync is already running — nothing
   started."), no row, no call; the command exits 1. Released in `finally`.
3. **Record** — a `garmin_syncs` row (`running`, trigger, `started_at`, `full_history`).
4. **Window** — the whole history (no `startDate`) until a `full_history` attempt has
   succeeded *and* a run with a Garmin id exists; afterwards `startDate` = the newest
   stored Garmin run's date − 7 days. No `endDate` is sent.
5. **Pages** — `start` += 100 until a page comes back short; 200 full pages in a row is a
   failure (an API that ignores `start`).
6. **Per page** — each activity is classified: not a running `typeKey` → *ignored*; a
   required field missing → *invalid*; local start after today → *future-dated*. The rest
   are written in one `DB::transaction` after **one** candidate query (the owner's runs on
   the page's dates, or carrying one of the page's ids):
   - same `garmin_activity_id` → every API-owned column is compared and the row is saved
     only when one differs (*updated*), so a second sync moves no `updated_at`;
   - else the `StartTimeMatcher::closest()` run **with no Garmin id** (same local date,
     ±2 min) → it gains the id, `source = garmin_api` and the API values (*linked*, no
     review);
   - else a new run (*new*).
7. **Finish** — the row becomes `succeeded` or `failed` with its cause and counts; when at
   least one run was written (even in a failed attempt) `HeatmapGridCache::forgetAthlete()`
   retires every cached window, the intensity scale and the trend.

Failure paths: a `GarminApiException` is logged as `warning` with the cause and status only
and recorded; a database or programming error is recorded as `unexpected` and reported. Both
keep every page committed before them, and a first sync cut off half-way is followed by
another full-history read. A run deleted on Garmin is never deleted here. A process that dies
leaves its row `running`; the status queries only read finished rows and the lock expires on
its own after 30 minutes.

**Token lifecycle (`GarminPersonalApiClient`).** With a key configured, the client
`POST`s `Authorization: ApiKey {key}` to the token path. The answer is a **raw JWT**, not
JSON. The `exp` claim is read from its payload segment and the token is cached in the
`file` store until one hour before `exp`. Every data call sends `Authorization: Bearer`
and `Accept: application/json`. A `401` on a data call drops the cached token,
exchanges once more and retries once; a second refusal is `key_rejected`.

**Activity page.** `activities($start, $limit, $startDate, $endDate)` returns one page
of the activity search as a list; paging until a short page is the action's job.
`GarminApiActivity::typeKeyOf()` classifies a payload before a run is built
(`GarminRunningTypes::sportTypeForTypeKey()`: nine keys to `Run` / `TrailRun` /
`VirtualRun`), because a strength session has no distance and would otherwise be counted
invalid instead of ignored. `GarminApiActivity::fromApiPayload()` returns the run or null
when the id, local start, distance or moving time is missing or unreadable.

**Failure mapping** (cause is a `GarminSyncFailure`, carried by `GarminApiException`):

| What happened | Cause |
| --- | --- |
| Token call `401` / `403`; data call `403`; data call `401` twice | `key_rejected` |
| `ConnectionException` (including a timeout), `429`, any `5xx` | `unreachable` |
| Any other status, an unreadable token or `exp`, a body that is not a JSON list | `unexpected` |

## Technical design

| Piece | Where | Role |
| --- | --- | --- |
| Model / table | `app/Models/GarminSync.php`, `create_garmin_syncs_table` migration | One row per sync attempt |
| Action | `app/Actions/Garmin/SyncGarminActivities.php` | Lock, record, window, pages, match / link / create / update, cache, finalise; never authorizes (the caller decides) |
| Controller / route | `app/Http/Controllers/Garmin/SyncGarminNowController.php`, `POST /sync/garmin` (`garmin.sync`), limiter `garmin-sync` | Sync now; 3 a minute per user id |
| Command | `app/Console/Commands/Garmin/GarminSyncCommand.php`, `routes/console.php` | Thin wrapper; the daily schedule |
| DTOs | `app/Data/Garmin/GarminSyncCounts.php`, `GarminSyncResult.php` | What an attempt did and how it ended |
| Owner | `app/Support/Garmin/ResolveSyncOwner.php` | The `giarfa` account, found by username (no session in a command) |
| Client | `app/Support/Garmin/GarminPersonalApiClient.php` | Key to token, token reuse, one activity-search page, one retry on a data-call 401 |
| Exception | `app/Exceptions/Garmin/GarminApiException.php` | A `GarminSyncFailure` cause and an HTTP status; fixed messages only |
| DTO | `app/Data/Garmin/GarminApiActivity.php` | One running activity as run values; rounding and empty metrics |
| Type map | `app/Support/Garmin/GarminRunningTypes.php` | `sportTypeForTypeKey()` beside the CSV label map |
| Config keys | `config/services.php` → `garmin`, `GARMIN_API_KEY` in `.env.example` | Key (env); host, paths, page size, re-read span, token store, timeouts (literals) |
| Run columns | `app/Models/Activity.php`, `add_garmin_sync_to_activities_table` migration | `source`, `garmin_activity_id` (see `garmin-csv-import.md` → Data model) |
| Enums | `app/Enums/ActivitySource.php`, `GarminSyncTrigger.php`, `GarminSyncOutcome.php`, `GarminSyncFailure.php` | The closed vocabularies of the two tables |
| Factories | `database/factories/GarminSyncFactory.php`, `ActivityFactory::garminSynced()` | `succeeded()`, `failed()`, `running()`, `manual()`, `scheduled()`, `fullHistory()` |
| Seeder | `database/seeders/TrainingBlockSeeder.php` | Last four weeks as API-synced runs, one succeeded full-history scheduled sync finished this morning |
| Tests | `tests/Feature/Database/SchemaTest.php`, `GarminSyncMigrationTest.php`, `tests/Unit/Models/ActivityTest.php`, `tests/Feature/Garmin/GarminPersonalApiClientTest.php`, `GarminSyncTest.php`, `GarminSyncCommandTest.php`, `tests/Feature/Console/ScheduleTest.php` | Schema, client, the sync, the command, the schedule |

### Data model (`garmin_syncs`)

One row per attempt, written when the attempt starts and finished when it ends.

| Column | Type | Meaning |
| --- | --- | --- |
| `trigger` | string, `GarminSyncTrigger` values `scheduled` / `manual` | What started it |
| `started_at`, `finished_at` | datetime, `finished_at` null while running | The attempt's span |
| `outcome` | string: `running` / `succeeded` / `failed` | `succeeded` includes an attempt that wrote nothing |
| `failure_cause` | nullable string: `key_rejected` / `unreachable` / `unexpected` | A cause from a closed list, never free text |
| `full_history` | boolean | The attempt read the whole history, not the last few days |
| `new_count`, `updated_count`, `linked_count`, `ignored_count`, `future_dated_count`, `invalid_count` | unsigned integers, default 0 | What the attempt did with each activity it read |

Index `(outcome, finished_at)` serves the two status queries the dashboard makes on every
request (newest succeeded attempt, newest finished attempt). `GarminSync` exposes them as
the `succeeded()` and `finished()` scopes. `GarminSyncFailure::warning()` is the one place
the dashboard copy lives: "Garmin key rejected — renew it", "Garmin unreachable",
"Garmin sync failed".

## Architectural choices

| Choice | Alternatives considered | Why this one |
| --- | --- | --- |
| No column can hold a key, token, URL or response body; the failure is a cause from `GarminSyncFailure` | A free-text `error_message`, as the retired `sync_runs` table had | A free-text message is where a request URL or a response body ends up. With a closed list a secret has nowhere to be stored, and the warning copy cannot drift from the cause |
| Integer primary keys on `garmin_syncs` | UUIDs (the Larapilot default for new models) | The existing schema uses auto-increment ids and the PRD data model keeps them; one owner, one table of attempts, nothing exposed by id |
| A hand-rolled client on the HTTP client | `garmin/connect-personal-api-python` (Python only); a community PHP package | The only official client is Python and no maintained PHP package exists (PRD prior art) |
| The JWT's `exp` is read without verifying the signature | Verifying it; a fixed 24 h lifetime | This is the client, not the verifier — the API checks the token on every call. A fixed lifetime would expire mid-sync or never renew; a missing `exp` is a failure, not a guess |
| The token is cached until one hour before `exp`, in `Cache::store('file')` (decision `dffd4adf33c2d285`) | The default store; a column; encrypting it in the database cache | `CACHE_STORE=database` would write the token into SQLite, and "the token never appears in the database" is a requirement. A token inside its last hour is used once and not kept |
| Exception messages are fixed text; the previous exception is chained, never its message | Rethrowing the HTTP exception's message | That message embeds the request URL and can embed a response body |
| Endpoint URLs are literals in `config/services.php`; only the key is `env()` | Env-driven base URL | No environment file can send the key to another host (`.ai/rules/config.md`) |
| The window is anchored on the newest stored Garmin run, minus 7 days | Always "7 days ago"; a stored cursor | After a week of failed nightly syncs "7 days ago" would leave a gap; the newest stored run is where the last good data ends, with no extra state to keep in step |
| No `endDate` is sent | `endDate` = today | The API does not say whether it is inclusive; an exclusive end would hide a run done today, and the search answers newest first, so an open end costs nothing |
| One transaction per page, the counts added after commit | One transaction for the whole sync; none | A failure on page N keeps pages 1..N-1, so a cut-off first sync resumes instead of restarting; a rolled-back page never inflates the attempt's counts |
| The candidates of a page are loaded with one query that also matches the page's ids | A query per activity; dates only | No N+1; and a run Garmin moved to another day would be missed by a date range and then created again against the unique index |
| The lock is on the default cache store | The `file` store like the token | The lock holds no secret and `cache_locks` already exists; only the token must stay out of the default store |
| For a run with a Garmin id the API is authoritative; a metric it leaves empty keeps the stored value | API values always replace; the CSV wins | A value backfilled from the CSV is never blanked. Accepted trade-off: a CSV Overwrite of a run inside the next sync's re-read span is replaced by the API values at that sync |
| `garmin_activity_id` is an unsigned big integer with a unique index | A string; a lookup table | Ids are 11-digit JSON numbers; the unique index makes a second sync idempotent in the database itself, not only in the action |

## Key decisions & invariants

- **Invariant —** `source` and `garmin_activity_id` are never mass-assigned. _Breaks if:_ a
  request or import payload can plant an id, making a sync overwrite an unrelated run.
- **Invariant —** the API key and the token appear in no exception message, log context,
  cache default store, column or response. _Breaks if:_ a catch block rethrows
  `$exception->getMessage()` or the token is put in the default cache store.
- **Invariant —** a missing metric is stored as NULL, never 0: no pace for a null or zero
  speed, none for a null elevation or heart rate (a heart rate of 0 is Garmin's "no strap").
  _Breaks if:_ a 0 reads as a measurement on the dashboard.
- **Invariant —** only one sync runs at a time, and the lock is released in `finally`.
  _Breaks if:_ two syncs write the same page and the unique index turns the second into a
  failed attempt.
- **Invariant —** a type is classified before its fields. _Breaks if:_ non-run activities
  with no distance (strength, yoga) are counted *invalid* instead of *ignored*.
- **Invariant —** `garmin_syncs` holds no secret and no response text. _Breaks if:_ a column
  of free text is added; a failure would then be able to store the key or a URL.

## Extension points & gotchas

- `US-023` extends the API-owned columns: add the column to `GarminApiActivity::toRunAttributes()`
  / `metricAttributes()` and the comparison in `SyncGarminActivities::writeRuns()` follows.
- A new sync trigger is a `GarminSyncTrigger` case; the action never authorizes, so the
  caller (command, controller) is where access is decided.

- Rolling back `remove_strava_from_schema` in a test needs `add_garmin_sync_to_activities_table`
  undone first, or `source` is added twice (`RemoveStravaMigrationTest`, `UsernameMigrationTest`).
- The additive migration is applied to the owner's development database only after copying
  `database/database.sqlite` aside; never `migrate:fresh --seed` there.

## Related

- Specs: `US-022`
- Plans: `.larapilot/plans/US-022-plan.yaml`
- Other domains: `garmin-csv-import.md`, `heatmap-dashboard.md`
