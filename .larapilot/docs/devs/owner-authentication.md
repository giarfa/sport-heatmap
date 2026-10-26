# Owner authentication

| | |
| --- | --- |
| **Status** | Active |
| **Introduced by** | `US-020` |
| **Last updated by** | `US-021` — 2026-10-26 |
| **Owner persona** | 🗄️ Mike |

## Purpose

This instance is private: one owner, one account, one password. Every page sits behind a session sign-in, and the owner's runs and heart rate are readable and writable only after it. This file describes the account (data model, first-sign-in state, recovery) and the sign-in flow. The forced first password change and the route-group gate are described below.

## Functional flow

### Sign-in

1. `GET /login` (guest-only; a signed-in owner is redirected to the dashboard) renders `auth/login.blade.php` inside `layouts/app`.
2. `POST /login` validates through `SignInRequest` (username required, password nullable), then calls `SignInOwner`.
3. `SignInOwner` checks the throttle first, then the credentials. Success: `Auth::login($owner, remember: false)`, session id regenerated, redirect to the intended URL (same-host only) or the dashboard. Failure: back to `/login` with the one message "These credentials do not match our records." on the `username` field; only the username is flashed back.
4. After 5 failures for the same username + IP within 60 seconds the next attempt is refused with "Too many sign-in attempts. Try again in N seconds." and no credential check runs.
5. `POST /logout` ends the session, regenerates the CSRF token and redirects to `/login`.

### Forced first password change

1. While `users.password` is null, signing in as `giarfa` with an empty password succeeds (and any non-empty password is refused).
2. The `password.set` middleware (`EnsureOwnerPasswordIsSet`) redirects every signed-in route that carries it to `GET /password/change`. Only the change routes and `POST /logout` sit behind `auth` alone, so they stay reachable.
3. `PUT /password/change` validates through `ChangePasswordRequest` (`required|string|min:12|max:72|confirmed`, a message per rule; 72 is bcrypt's input limit); a refused value stores nothing. A valid one is assigned to the `hashed` cast, saved, every other stored session of the owner deleted (database session driver; two clients may have signed in with the empty password during the first-change window), the session id regenerated, and the owner goes to the dashboard.
4. Once a password exists, the change page and the update route redirect to the dashboard: a voluntary change is out of scope (FR-021), so the page is the one-time first change only. Signing in with an empty password is refused from then on and no route forces the change.

### The route gate

`routes/web.php` has three groups; `/up` (the health route) lives outside the web
group:

1. `guest` — `GET /login`, `POST /login`.
2. `auth` — `GET|PUT /password/change`, `POST /logout`.
3. `auth` + `password.set` — everything else: `/` (the dashboard), and every
   `import/garmin/*` route.

`bootstrap/app.php` sends guests to `route('login')` and signed-in users who open a
guest route to the dashboard. The `auth` middleware stores the intended URL for
GET requests, so the owner lands on the page first requested after signing in.
No route creates or lists users. What each protected area does for the owner is
in `heatmap-dashboard.md` and `garmin-csv-import.md`.

## Technical design

| Piece | Where | Role |
| --- | --- | --- |
| Model / table | `app/Models/User.php`, `users` table | One row is the owner; `username` is its sign-in identity |
| Migration | `database/migrations/2026_10_26_201636_add_username_to_users_table.php` | Adds `users.username` and backfills the owner |
| Factory states | `database/factories/UserFactory.php` | `owner()` (null password), `ownerWithPassword()` |
| Seeder | `database/seeders/TrainingBlockSeeder.php` | The demo athlete is `giarfa` with a null password |
| Routes | `routes/web.php` (the three groups above), `bootstrap/app.php` (`redirectGuestsTo`, `redirectUsersTo`, the `password.set` alias) | The gate; guests go to `/login`, signed-in users who open a guest route to the dashboard |
| Controllers | `app/Http/Controllers/Auth/ShowSignInFormController.php`, `SignInController.php`, `app/Http/Controllers/SignOutController.php` | Thin, single action |
| Action | `app/Actions/Auth/SignInOwner.php` | The one decision: throttle → credential check → hit/clear the limiter |
| Middleware | `app/Http/Middleware/EnsureOwnerPasswordIsSet.php` (alias `password.set`) | Forces the first password change while `password` is null |
| Controllers (change) | `app/Http/Controllers/Auth/ShowChangePasswordFormController.php`, `ChangePasswordController.php` | The one-time first-password page and its handler |
| Form Requests | `app/Http/Requests/Auth/SignInRequest.php`, `ChangePasswordRequest.php` | Edge validation of both forms |
| Views | `resources/views/auth/login.blade.php`, `change-password.blade.php` | Mirrors the Garmin upload form idiom; `.text-input` in `resources/css/app.css` |
| Tests | `tests/Feature/Database/UsernameMigrationTest.php`, `SchemaTest.php`, `tests/Feature/Auth/SignInTest.php`, `SignOutTest.php`, `ForcedPasswordChangeTest.php`, `tests/Feature/Garmin/GarminImportAccessTest.php`, `tests/Feature/Routing/NoPublicSignupTest.php` | Backfill branches, untouched columns, `down()`; sign-in, throttle, intended URL, sign-out |

### Data model

- `users.username` — `string`, nullable, **unique**. Only the value `User::OWNER_USERNAME` (`giarfa`) is ever used to sign in; no route creates or lists users.
- `users.password` — nullable. A **null password is the "first sign-in armed" state**: the owner signs in with an empty password and is forced to set one. Once set, it is a hash (the `hashed` cast).
- `username` is deliberately outside `#[Fillable]` (identity column): the migration and the factory assign it explicitly.

### The `users` columns

After `US-021` the table holds `name`, `username`, `email`, `email_verified_at`, `password`, `remember_token` and the timestamps: the four Strava columns (`strava_athlete_id`, the two encrypted tokens and the token expiry) were dropped by `remove_strava_from_schema`, so the account holds no third-party credential. The backfill below is history: it reads `strava_athlete_id`, which exists when it runs and is gone afterwards, and `UsernameMigrationTest` rolls the newer migration back before exercising it.

### The owner backfill (migration `up()`)

The migration uses the query builder only (no model, no events, no `updated_at` bump) and picks one row, in this order:

1. the first id of `config('strava.allowed_athlete_ids', [])` that matches a stored `strava_athlete_id` — the connected, allowlisted athlete the product already treated as the owner;
2. else the lowest-id user that has activities;
3. else the lowest-id user.

That row gets `username = giarfa` and `password = null`; nothing else changes (runs, name, email, `updated_at`; the Strava token columns it left alone were dropped by `US-021`). On an empty `users` table it inserts `giarfa` (`giarfa@heatmap.invalid`, a reserved `.invalid` address) with a null password. `down()` drops the unique index and the column.

## Architectural choices

| Choice | Alternatives considered | Why this one |
| --- | --- | --- |
| Backfill with the query builder, self-contained | Eloquent model, an app Action | A migration must keep running after the model changes; Eloquent would fire events and bump `updated_at` on a row the owner did not edit |
| `config('strava.allowed_athlete_ids', [])` with a `[]` default | Direct config read | `US-021` deleted `config/strava.php`; the migration still runs on a fresh database, where only the later branches apply |
| Other user rows are left untouched and simply not displayed | Merge them into the owner, delete them | Merging is out of scope for `US-020`; leaving them keeps the migration non-destructive (accepted trade-off) |
| Session guard with a hand-written credential check | `Auth::attempt`, Fortify, a starter kit | `Auth::attempt` rejects a null stored hash and `ConvertEmptyStringsToNull` turns the empty first-sign-in password into null; Fortify and the kits bring registration, reset and 2FA a one-account instance does not use |
| Throttle inside `SignInOwner`, key `username|ip`, 5 per 60 s, checked first | Route `throttle` middleware | A route limiter cannot key on the submitted username; checking first means a refused attempt does no lookup and no hashing |
| Wrong username runs one `Hash::check` against a fixed dummy hash | Return immediately | Response time must not reveal whether the username exists |
| Intended URL pulled by the controller and accepted only when same-host | `redirect()->intended()` | The URL generator passes an absolute URL through untouched, so a planted off-site `url.intended` would become an open redirect |
| The 12-character rule alone, not `Password::defaults()` | Mixed case, symbols, `uncompromised()` | The PRD fixes the rule at 12 characters; `uncompromised()` is an outbound call the PRD rules out |
| The change page closes after the first password | A voluntary change form | A later change is out of scope for FR-021; the recovery path is clearing `users.password` |
| `redirect()->route('login')` on failure | `back()` | `back()` depends on the Referer header |
| One `auth` + `password.set` group for every owner route | Per-route middleware | A single group makes a forgotten route visible in review |
| First-change cleanup deletes the owner's other `sessions` rows | `AuthenticateSession` + `logoutOtherDevices` | The session table already holds `user_id`; one delete needs no extra middleware on every route |
| Test databases clear the migration-created `giarfa` in `Tests\TestCase::setUp()` | Skip the insert under `testing` | The empty-database insert is part of the contract; tests arrange their own users and the unique username would otherwise collide with `owner()` |

## Key decisions & invariants

- **Invariant —** at most one row carries a username, and it is `giarfa`. _Breaks if:_ a second sign-in identity is added; the unique index and the single-owner rules assume it.
- **Invariant —** a null `users.password` means the first sign-in is armed. _Breaks if:_ any code path stores `''` or null for a convenience; the owner would be re-armed or locked out.
- **Recovery —** clearing the password re-arms the first change: `UPDATE users SET password = NULL WHERE username = 'giarfa'` on the SQLite file.

- **Invariant —** sign-in sets no remember-me cookie and regenerates the session id. _Breaks if:_ `Auth::login` is called with `remember: true`, or the regenerate is dropped (session fixation).
- **Invariant —** a wrong username and a wrong password answer identically. _Breaks if:_ a distinct message or an early return is added; the owner's username becomes enumerable.

- **Invariant —** `password.set` is on every signed-in route except the change routes and `POST /logout`. _Breaks if:_ a route is added without it (a first-sign-in owner reaches the data with no password) or the change routes get it (they redirect to themselves).
- **Accepted risk —** between the migration and the first password change, whoever knows the username can set the password first. Mitigation: set it right after the deploy (README, "First sign-in and recovery").

## Extension points & gotchas

- **Behind a reverse proxy the throttle needs `trustProxies`.** `SignInOwner` keys on `$request->ip()`. With no trusted proxy configured (none is today; deployment is not defined in the app), every visitor shares the proxy's address, so anyone can burn the five attempts a minute of the `giarfa|{ip}` key and lock the owner out until the minute passes. Configure `$middleware->trustProxies(at: …)` for the production proxy before shipping (open review finding, `.larapilot/docs/review/US-020.md`).

- Never run `migrate:fresh` / `db:seed` against the dev SQLite file to check the seeder; `TrainingBlockSeederTest` covers it on the in-memory test database.
- The seeded demo athlete is the owner, so a seeded local database starts at the first sign-in.

## Related

- Specs: `US-020`, `US-021` (removed Strava)
- Plans: `.larapilot/plans/US-020-plan.yaml`
- Other domains: `heatmap-dashboard.md`, `garmin-csv-import.md`
