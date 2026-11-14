# Garmin API sync

| | |
| --- | --- |
| **Status** | Planned |
| **Introduced by** | `US-022` |
| **Last updated by** | `US-022` — 2026-11-14 |
| **Owner persona** | 🔧 Alex |

## Purpose

A second way runs enter besides the CSV upload: the owner's Garmin Connect Personal API,
read once a day by the scheduler and on demand by a "Sync now" button. Without it the
heatmap is only as current as the last CSV the owner exported and uploaded. The CSV import
stays (`garmin-csv-import.md`); both write the same `activities` table.

## Functional flow

> Filled as the spec lands: the client and the DTO (TASK-02), the sync action, command and
> schedule (TASK-04), Sync now and the status line (TASK-06). Only the data model exists so
> far.

## Technical design

| Piece | Where | Role |
| --- | --- | --- |
| Model / table | `app/Models/GarminSync.php`, `create_garmin_syncs_table` migration | One row per sync attempt |
| Run columns | `app/Models/Activity.php`, `add_garmin_sync_to_activities_table` migration | `source`, `garmin_activity_id` (see `garmin-csv-import.md` → Data model) |
| Enums | `app/Enums/ActivitySource.php`, `GarminSyncTrigger.php`, `GarminSyncOutcome.php`, `GarminSyncFailure.php` | The closed vocabularies of the two tables |
| Factories | `database/factories/GarminSyncFactory.php`, `ActivityFactory::garminSynced()` | `succeeded()`, `failed()`, `running()`, `manual()`, `scheduled()`, `fullHistory()` |
| Seeder | `database/seeders/TrainingBlockSeeder.php` | Last four weeks as API-synced runs, one succeeded full-history scheduled sync finished this morning |
| Tests | `tests/Feature/Database/SchemaTest.php`, `GarminSyncMigrationTest.php`, `tests/Unit/Models/ActivityTest.php` | Schema, backfill, rollback, mass-assignment |

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
| `garmin_activity_id` is an unsigned big integer with a unique index | A string; a lookup table | Ids are 11-digit JSON numbers; the unique index makes a second sync idempotent in the database itself, not only in the action |

## Key decisions & invariants

- **Invariant —** `source` and `garmin_activity_id` are never mass-assigned. _Breaks if:_ a
  request or import payload can plant an id, making a sync overwrite an unrelated run.
- **Invariant —** `garmin_syncs` holds no secret and no response text. _Breaks if:_ a column
  of free text is added; a failure would then be able to store the key or a URL.

## Extension points & gotchas

- Rolling back `remove_strava_from_schema` in a test needs `add_garmin_sync_to_activities_table`
  undone first, or `source` is added twice (`RemoveStravaMigrationTest`, `UsernameMigrationTest`).
- The additive migration is applied to the owner's development database only after copying
  `database/database.sqlite` aside; never `migrate:fresh --seed` there.

## Related

- Specs: `US-022`
- Plans: `.larapilot/plans/US-022-plan.yaml`
- Other domains: `garmin-csv-import.md`, `heatmap-dashboard.md`
