# Owner authentication

| | |
| --- | --- |
| **Status** | Active |
| **Introduced by** | `US-020` |
| **Last updated by** | `US-020` — 2026-10-26 |
| **Owner persona** | 🗄️ Mike |

## Purpose

This instance is private: one owner, one account, one password. Every page sits behind a session sign-in, and the owner's runs and heart rate are readable and writable only after it. This file describes the account itself (data model, first-sign-in state, recovery); the sign-in flow, the forced first change and the route gate are added by the later tasks of `US-020`.

## Functional flow

_Sign-in, throttle, forced first password change and the route-group gate are documented with the tasks that build them._

## Technical design

| Piece | Where | Role |
| --- | --- | --- |
| Model / table | `app/Models/User.php`, `users` table | One row is the owner; `username` is its sign-in identity |
| Migration | `database/migrations/2026_10_26_201636_add_username_to_users_table.php` | Adds `users.username` and backfills the owner |
| Factory states | `database/factories/UserFactory.php` | `owner()` (null password), `ownerWithPassword()` |
| Seeder | `database/seeders/TrainingBlockSeeder.php` | The demo athlete is `giarfa` with a null password |
| Tests | `tests/Feature/Database/UsernameMigrationTest.php`, `SchemaTest.php` | Backfill branches, untouched columns, `down()` |

### Data model

- `users.username` — `string`, nullable, **unique**. Only the value `User::OWNER_USERNAME` (`giarfa`) is ever used to sign in; no route creates or lists users.
- `users.password` — nullable. A **null password is the "first sign-in armed" state**: the owner signs in with an empty password and is forced to set one. Once set, it is a hash (the `hashed` cast).
- `username` is deliberately outside `#[Fillable]` (identity column): the migration and the factory assign it explicitly.

### The owner backfill (migration `up()`)

The migration uses the query builder only (no model, no events, no `updated_at` bump) and picks one row, in this order:

1. the first id of `config('strava.allowed_athlete_ids', [])` that matches a stored `strava_athlete_id` — the connected, allowlisted athlete the product already treated as the owner;
2. else the lowest-id user that has activities;
3. else the lowest-id user.

That row gets `username = giarfa` and `password = null`; nothing else changes (runs, Strava tokens, name, email, `updated_at`). On an empty `users` table it inserts `giarfa` (`giarfa@heatmap.invalid`, a reserved `.invalid` address) with a null password. `down()` drops the unique index and the column.

## Architectural choices

| Choice | Alternatives considered | Why this one |
| --- | --- | --- |
| Backfill with the query builder, self-contained | Eloquent model, an app Action | A migration must keep running after the model changes; Eloquent would fire events and bump `updated_at` on a row the owner did not edit |
| `config('strava.allowed_athlete_ids', [])` with a `[]` default | Direct config read | `US-021` deletes `config/strava.php`; the migration must still run on a fresh database afterwards |
| Other user rows are left untouched and simply not displayed | Merge them into the owner, delete them | Merging is out of scope for `US-020`; leaving them keeps the migration non-destructive (accepted trade-off) |
| Test databases clear the migration-created `giarfa` in `Tests\TestCase::setUp()` | Skip the insert under `testing` | The empty-database insert is part of the contract; tests arrange their own users and the unique username would otherwise collide with `owner()` |

## Key decisions & invariants

- **Invariant —** at most one row carries a username, and it is `giarfa`. _Breaks if:_ a second sign-in identity is added; the unique index and the single-owner rules assume it.
- **Invariant —** a null `users.password` means the first sign-in is armed. _Breaks if:_ any code path stores `''` or null for a convenience; the owner would be re-armed or locked out.
- **Recovery —** clearing the password re-arms the first change: `UPDATE users SET password = NULL WHERE username = 'giarfa'` on the SQLite file.

## Extension points & gotchas

- Never run `migrate:fresh` / `db:seed` against the dev SQLite file to check the seeder; `TrainingBlockSeederTest` covers it on the in-memory test database.
- The seeded demo athlete is the owner, so a seeded local database starts at the first sign-in.

## Related

- Specs: `US-020`
- Plans: `.larapilot/plans/US-020-plan.yaml`
- Other domains: `strava-connection.md`, `heatmap-dashboard.md`, `garmin-csv-import.md`
