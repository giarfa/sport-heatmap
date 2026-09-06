# Sport Heatmap

A private, single-athlete training heatmap. It syncs your own runs from Strava once a day and
renders them as a GitHub-style contribution grid, so a training block is readable at a glance.

One athlete, one screen, no public signup: identity comes from Strava OAuth and access is
restricted to an explicit allowlist of athlete ids.

## Requirements

- **PHP 8.3 or newer** and **Composer** — `composer.json` requires `^8.3`; CI runs 8.4
- **Node.js 20+** and npm
- **SQLite** (bundled with PHP on macOS)
- **[Laravel Herd](https://herd.laravel.com/)** on macOS

> There is **no Laravel Sail and no Docker** in this project — Herd is a deliberate choice
> recorded in the PRD. Do not go looking for a `docker-compose.yml`; there isn't one.

## Local setup on Herd

1. **Park or link the project in Herd.** Either park the parent directory
   (`herd park` from the folder that contains `sport-heatmap`) or link this one directly:

   ```bash
   cd /path/to/sport-heatmap
   herd link sport-heatmap
   herd secure sport-heatmap
   ```

   Herd then serves the app at **`https://sport-heatmap.test`**. `herd secure` issues the local
   TLS certificate — Strava requires an HTTPS callback, so this step is not optional.

2. **Install and bootstrap.** The `setup` script installs dependencies, creates `.env`,
   generates the application key, migrates, and builds the frontend assets:

   ```bash
   composer setup
   ```

3. **Point `APP_URL` at the Herd domain.** In `.env`:

   ```dotenv
   APP_URL=https://sport-heatmap.test
   ```

   It must match the domain Herd serves, or the OAuth callback will not line up.

4. **Watch assets while developing:**

   ```bash
   npm run dev
   ```

   `composer run dev` is also available: it runs the PHP server, queue listener, log tail and
   Vite together for people who prefer `php artisan serve` over Herd.

## Registering the Strava application

The app talks to Strava as a registered API client. You need to create one once, under your own
Strava account.

1. Go to **<https://www.strava.com/settings/api>** and create an application.
2. Set **Authorization Callback Domain** to the host of your app **without scheme or path**:
   - development: `sport-heatmap.test`
   - production: your real domain
3. Note the **Client ID** and **Client Secret** Strava shows you.
4. The app requests the **`activity:read_all`** scope — read-only. No write scope is ever
   requested, and nothing is ever pushed back to Strava.
5. Find your numeric **athlete id**: it is the last segment of your profile URL
   (`https://www.strava.com/athletes/10000001` → `10000001`).
6. Put the values in your local `.env` — **never commit them**:

   ```dotenv
   STRAVA_CLIENT_ID=123456
   STRAVA_CLIENT_SECRET=your-client-secret
   STRAVA_REDIRECT_URI=https://sport-heatmap.test/auth/strava/callback
   STRAVA_ALLOWED_ATHLETE_IDS=replace-with-your-own-athlete-id
   ```

Only athletes listed in `STRAVA_ALLOWED_ATHLETE_IDS` may connect. Anyone else who completes the
Strava authorization is rejected **before** any token, user record, or session is created — the
allowlist is checked against the athlete id Strava returns with the token exchange, and nothing at
all is written until it passes.

> **Put your own athlete id there and nobody else's.** The allowlist is the only access control
> in this product; copying an example id verbatim would authorize a stranger. Non-numeric entries
> are discarded, so a blank or malformed value allows nobody rather than everybody.

### Signing in

Visit the app signed out and press **Connect with Strava**. That is the whole flow — there is no
password, no registration, and no other way in.

A few things worth knowing before the first attempt:

- **The callback domain is the setting that fails confusingly.** Strava's *Authorization Callback
  Domain* is a bare host — no scheme, no path, no port. If it does not match the host in
  `STRAVA_REDIRECT_URI`, Strava rejects the callback with an opaque error rather than a useful one.
  Under Herd that host is your HTTPS `.test` domain, e.g. `sport-heatmap.test`.
- **The app only ever requests `activity:read_all`.** The scope is a literal in
  `config/strava.php`, not an environment value, so no deployment can widen it. Nothing is ever
  written back to Strava.
- **Sign-in creates a synthetic email address.** Strava exposes no email under this scope and
  `users.email` is `NOT NULL UNIQUE`, so the account is created as
  `athlete-{id}@strava.invalid`. `.invalid` is the RFC 2606 reserved TLD — that address can never
  resolve, and the app never sends anything to it.
- **Tokens are refreshed transparently.** The access token is renewed from the refresh token when
  it has expired or is within `STRAVA_TOKEN_REFRESH_MARGIN` seconds of expiring. Strava rotates the
  refresh token on every refresh and both halves are stored, encrypted at rest.
- **If you revoke the app on Strava**, the next visit shows the connect screen in a reconnect
  state rather than an error. Press the button again to reconnect.
- **Removing yourself from the allowlist signs you out immediately.** It is re-checked on every
  request, not only at connect time, so an existing session does not outlive the edit.

### Before putting this on a real host

The OAuth routes are reachable without a session, which makes them the part of this app a stranger
can actually touch. Three settings are not safe at their `.env.example` defaults:

| Key | Set it to | Why |
| --- | --- | --- |
| `APP_DEBUG` | `false` | Otherwise any unhandled error renders a stack trace, environment variables included, to whoever triggered it |
| `APP_ENV` | `production` | Turns off the development affordances that assume a trusted visitor |
| `SESSION_SECURE_COOKIE` | `true` | Sign-in issues a long-lived remember-me cookie; without this it is sent over plain HTTP too |

If the app sits behind a reverse proxy or CDN, also configure trusted proxies — the OAuth rate
limiter keys on the client IP, and every request arriving from the proxy's address collapses that
budget into one shared bucket.

## Environment variables

| Key | Meaning | Example |
| --- | --- | --- |
| `STRAVA_CLIENT_ID` | Client ID of your Strava API application | `123456` |
| `STRAVA_CLIENT_SECRET` | Client secret of that application — secret, never committed | `2f9c…` |
| `STRAVA_REDIRECT_URI` | OAuth callback URL; its host must match the callback domain registered with Strava | `https://sport-heatmap.test/auth/strava/callback` |
| `STRAVA_ALLOWED_ATHLETE_IDS` | Comma-separated allowlist of Strava athlete ids permitted to connect. Numeric entries only; blank means nobody. | your own athlete id |
| `STRAVA_TOKEN_REFRESH_MARGIN` | Seconds before expiry at which the access token is refreshed pre-emptively | `300` |
| `STRAVA_SYNC_HOUR` | Local hour (0–23) at which the daily sync runs | `3` |
| `STRAVA_SYNC_TIMEZONE` | Timezone that `STRAVA_SYNC_HOUR` is expressed in | `Europe/Rome` |

`.env.example` lists all of them with empty or safe placeholder values. They are read through
`config/strava.php` — never call `env()` for them outside that file, because `env()` returns
`null` once `config:cache` has run.

## Database and demo data

The database is a single SQLite file at `database/database.sqlite` (untracked).

```bash
php artisan migrate:fresh --seed
```

This produces a full **16-week training block** for one demo athlete — including an illness
week, a peak week and a partial current week — so the dashboard can be developed and reviewed
without ever connecting to Strava.

## Running the sync

The sync pulls your recent Strava activities and upserts them into `activities`. It records
every attempt in `sync_runs`, which is what the dashboard's sync-status indicator reads.

> **The sync command is not implemented yet** — it lands in a later spec. The Strava OAuth
> connection it depends on is in place. This section documents how the sync will be operated, so
> the scheduling setup can be done once and left alone.

Run it manually:

```bash
php artisan strava:sync
```

Run it on a schedule. Once the sync command lands it will register itself to run daily at
`STRAVA_SYNC_HOUR` in `STRAVA_SYNC_TIMEZONE`; the host only ever needs Laravel's one-minute
scheduler tick, so this cron entry can be installed now and never touched again:

```cron
* * * * * cd /path/to/sport-heatmap && php artisan schedule:run >> /dev/null 2>&1
```

`php artisan schedule:list` shows what is currently registered — today it reports no scheduled
tasks, because the sync command does not exist yet.

## Tests and quality

```bash
php artisan test --compact        # PHPUnit suite
php artisan larapilot:quality     # Laravel Pint + Larastan (level 5)
vendor/bin/pint                   # apply formatting
```

CI runs the same gates — Pint, Larastan, PHPUnit, `composer audit` and the Vite build — on pushes
to `develop` and `main`, and on every pull request targeting them. Pushing a feature branch does
not trigger CI on its own; opening the PR does.
