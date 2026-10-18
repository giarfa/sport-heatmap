# Sport Heatmap

A single-athlete training heatmap, public and read-only. It syncs your own runs from Strava once a day and
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

3. **Set the development overrides.** `.env.example` ships production-safe values, so
   `composer setup` leaves you with a production-style `.env`. For local work, change these in
   `.env`:

   ```dotenv
   APP_ENV=local
   APP_DEBUG=true
   APP_URL=https://sport-heatmap.test
   ```

   `APP_URL` must match the domain Herd serves, or the OAuth callback will not line up. Add
   `LOG_LEVEL=debug` if you want the full log. Leave `SESSION_SECURE_COOKIE=true` — Herd serves
   HTTPS.

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
can actually touch. Start with how the code is installed, then the settings.

**Install without the development tools.** Every deployment runs:

```bash
composer install --no-dev --optimize-autoloader
```

The Larapilot dashboard (`/larapilot/*`) and Laravel Boost are development-only dependencies. The
dashboard can read `.env`, dump the database and write files, so a host that has it installed is
one `APP_ENV` value away from handing all of that to a stranger. `--no-dev` leaves both out, and
a plain `composer install` on a host puts them back. After installing, check that the dashboard is
gone: `php artisan route:list --path=larapilot` must list no route.

`.env.example` ships these values for a host. Keep them, and set `APP_URL` to the host's own
`https` URL:

| Key | Value | Why |
| --- | --- | --- |
| `APP_ENV` | `production` | Turns off the development affordances that assume a trusted visitor |
| `APP_DEBUG` | `false` | Otherwise any unhandled error renders a stack trace, environment variables included, to whoever triggered it |
| `APP_URL` | `https://…` | The host's own HTTPS URL; the OAuth callback and generated links are built from it |
| `LOG_STACK` | `daily` | One file per day, old ones rotated out, instead of a single log that grows forever |
| `LOG_LEVEL` | `warning` | `debug` writes request detail to disk that nobody on a real host needs |
| `SESSION_SECURE_COOKIE` | `true` | Without it the sign-in cookies are also sent over plain HTTP |

If the app sits behind a reverse proxy or CDN, also configure trusted proxies — the OAuth rate
limiter keys on the client IP, and every request arriving from the proxy's address collapses that
budget into one shared bucket.

## Environment variables

| Key | Meaning | Example |
| --- | --- | --- |
| `STRAVA_CLIENT_ID` | Client ID of your Strava API application | `123456` |
| `STRAVA_CLIENT_SECRET` | Client secret of that application — secret, never committed | `2f9c…` |
| `STRAVA_REDIRECT_URI` | OAuth callback URL; its host must match the callback domain registered with Strava | `https://sport-heatmap.test/auth/strava/callback` |
| `STRAVA_ALLOWED_ATHLETE_IDS` | Comma-separated allowlist of Strava athlete ids permitted to connect. Numeric entries only; blank means nobody may connect. Order matters: the first id that has connected is displayed publicly on the home page; with none connected, the home page shows the stored athlete with the lowest id who has at least one activity, whatever the allowlist says. | your own athlete id |
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

With `STRAVA_ALLOWED_ATHLETE_IDS` blank, the public home page shows that block without signing
in: no allowlisted athlete has connected, so the page falls back to the seeded athlete. Once your
own allowlisted athlete connects, they replace it. Never seed production — it would publish a demo
athlete until you connect.

## Running the sync

Every run reconciles the full 112-day window: it requests activities with `after` set to the
start of the window and paginates at Strava's maximum page size until a page comes back short.
Only running sport types (`Run`, `TrailRun`, `VirtualRun`) are stored; everything else is
discarded. Activities are upserted on their Strava activity id, so an edit made on Strava
(distance, name, sport type) propagates on the next run, and a Strava-sourced activity inside the
window that Strava no longer returns is deleted — so the Strava part of the map always matches
Strava, in both directions. Runs imported from a Garmin CSV are never touched by the sync (see
[Importing runs from Garmin Connect](#importing-runs-from-garmin-connect)).
The command is idempotent: running it twice in a row leaves the database byte-identical. Every
attempt — success or failure — writes exactly one row to `sync_runs` (start, finish, status,
upserted/deleted counts, and the error on failure), which is what the dashboard's sync-status
indicator reads.

Run it manually:

```bash
php artisan strava:sync
```

Run it on a schedule. It registers itself to run daily at `STRAVA_SYNC_HOUR` in
`STRAVA_SYNC_TIMEZONE`; the host only ever needs Laravel's one-minute scheduler tick:

```cron
* * * * * cd /path/to/sport-heatmap && php artisan schedule:run >> /dev/null 2>&1
```

`php artisan schedule:list` shows the registration and its next run time.

### Rate limits

One run costs one or two Strava API requests, well inside any allowance Strava currently issues —
the application hardcodes no quota, since Strava has changed the default allowance for newer
applications. Confirm the allowance issued to this application in the Strava developer dashboard
before relying on it in production. A `429` response is retried with backoff (the retry
count and delays are literals in `config/strava.php`); an exhausted retry records the observed
`X-RateLimit-Usage` response header in `sync_runs.error_message`, which is where to look if the
daily sync starts failing.

## Importing runs from Garmin Connect

Runs that Strava is missing, or that Garmin recorded better, can be added by hand from a Garmin
Connect export. Signed in as the owner, follow **Import Garmin CSV** under the map.

1. On the Garmin Connect **website**, open **Activities → All activities**, scroll until every
   run of the last 16 weeks is loaded, and press **Export CSV**. Both Italian (`Tipo di attività`,
   `Data`, `Distanza`, …) and English (`Activity Type`, `Date`, `Distance`, …) exports are accepted.
2. Upload the file (max 5 MB). The account must use **metric units**: Garmin writes distance in the
   account's unit, and an imperial export cannot be told apart from a metric one.
3. Review. Only running types are kept (every other type is counted and listed by name); rows
   outside the 16-week window or in the future are counted and skipped; a row with an unreadable
   date, distance or moving time is listed with its line number while the rest still import.
   A row **clashes** with a stored run that starts on the same date within ±2 minutes (the closest
   one). For each clash choose:
   - **Skip** (pre-selected) — keep the stored run untouched;
   - **Overwrite** — replace its name, distance, moving time and start with Garmin's values.
   Runs with no clash are added as they are. A row identical to a run already imported from Garmin
   is not asked about, so re-importing the same file changes nothing.
4. **Confirm import** writes everything in one transaction; **Cancel**, or simply leaving the page,
   writes nothing. A summary shows imported, overwritten, skipped, already-imported, ignored,
   outside-window and unreadable counts, and the map updates immediately.

**Garmin wins permanently.** The nightly sync never reverts an overwritten run, never re-adds its
Strava twin, and never deletes a Garmin-imported run — even when Strava still returns the twin. If
Strava later sends a run that starts within ±2 minutes of a run imported as new, the sync links the
two instead of adding a duplicate, keeping Garmin's values. The uploaded file itself is never
stored: it is read during the request and discarded. Imported runs carry no Strava link.

## The dashboard

The home page (`/`) is **public and read-only**. A signed-out visitor sees the heatmap, weekly
totals, legend and sync status of the **first connected athlete of `STRAVA_ALLOWED_ATHLETE_IDS`, in
configured order** — the same content the signed-in owner sees, without a Strava sign-in. A guest
request never refreshes a token, calls Strava, or writes a row. Signing in is only needed to connect
Strava, from the discreet "Owner? Connect with Strava" line under the map, and stays
allowlist-only. While no allowlisted athlete has connected yet, the home page shows the stored
athlete with the lowest id who has at least one activity (Strava or Garmin); the connect screen
appears only when no stored athlete has any activity. Removing an athlete from the allowlist
therefore no longer hides their stored runs — deleting their user row does. The page keeps
`noindex, nofollow`: reachable by link, not promoted.

If the owner's Strava token can no longer be refreshed, they are signed out and land on the public
map with a one-off "Your Strava connection expired" banner.

The heatmap grid is cached per athlete, per 16-week window and per calendar day
(`App\Support\Heatmap\HeatmapGridCache`), so a second request the same day skips the aggregate
query. The cache is cleared automatically for every synced athlete once `strava:sync` succeeds, so a
mid-day sync is visible on the next request; a failed sync leaves the cache alone, since there is
nothing new to show. The calendar day is part of the cache key, so a cached grid can never survive
into the next day even without a sync in between.

On first visit the theme follows the browser's `prefers-color-scheme`; toggling it in the header
persists the explicit choice in `localStorage` (`theme`), which then wins over the OS preference on
every later visit.

### The intensity scale

Each running day is bucketed into one of five levels — Rest, Easy, Steady, Hard, Peak — shaded from
a single-hue blue ramp, always shown next to a legend that states the actual kilometre range of
every level so no meaning ever rests on colour alone. Levels 1–4 are the **25th, 50th and 75th
percentiles of the window's own non-zero daily distances**, computed by **R-7 linear
interpolation** (the numpy / Excel `PERCENTILE.INC` default). That method is not interchangeable:
it is the one that reproduces this project's validated legend, and a different percentile method
(nearest-rank, for instance) silently desynchronises the legend from the ramp it was designed
against. Thresholds are recomputed by `App\Support\Heatmap\CalibrateIntensityScale` from scratch on
every request that misses the grid cache, and the cache is cleared on every successful sync — so
the scale is never carried over from a previous window's distribution.

Two fallbacks fall back to the same fixed bands (`0 / ≤ 5 / ≤ 10 / ≤ 20 / 20+ km`) rather than a
computed scale, and differ only in the sentence the legend prints:

- **Sparse** — fewer than four non-zero days in the window; there are not enough data points to
  separate three distinct quartiles.
- **Degenerate** — the computed percentiles collapse (p25 equals p75), which would otherwise render
  every running day at a single level and hide the data.

The ramp's hexes — four steps per theme, transcribed verbatim from
`.larapilot/mockups/heatmap-dashboard/README.md` → "The intensity ramp" — are the **only** contract
for that colour table. The palette validator script the mockup README cites was never committed to
this repository, so there is no automated gate that would catch a retuned step; treat that README
table as authoritative before changing a hex.

### Sync status

`App\Support\Sync\ResolveSyncStatus` reads `sync_runs` fresh on every request — never from the
grid cache — and resolves one of five states, in this precedence order:

| State | Condition | What renders |
| --- | --- | --- |
| **Never synced** | no `sync_runs` row at all, or the only rows are still `running` | The header chip reads "Never synced"; a banner says "Waiting for the first sync" and names the nightly job's hour. |
| **Failed** | the most recent run's status is `failed` | An alert banner names the run's own `error_message` and offers **Reconnect Strava**; wins even over a recent success, because the map is now frozen at that last good sync. |
| **Stale** | the most recent **successful** run finished more than `stale_after_hours` ago | An alert banner names the date the map's data is from and offers **Reconnect Strava**. |
| **Loading** | a run is `running` and no run has ever succeeded | A skeleton in the grid's exact geometry, `aria-busy="true"` with a polite live region — the only moment the product has geometry to hold open and no data yet. |
| **Healthy** | none of the above | The header chip alone reads "Synced {relative time}". |

`stale_after_hours` (48) is a literal in `config/strava.php`, not an environment value, since it is
a product invariant rather than a deployment knob — see the comment there for why. The boundary is
strict: a success finished exactly 48 hours ago is still healthy, one second more is stale.

A **failed** sync deliberately leaves the cached grid in place — the map keeps showing the last
good data under the warning, which is what "stale data is never presented as current, but the grid
still renders what it has" actually means in practice. Seeing an old map under an amber banner is
the feature working, not a bug to chase.

To reach each state in local dev without editing `TrainingBlockSeeder`, edit the newest
`sync_runs` row directly:

- **Never synced** — delete the newest row (or every row).
- **Stale** — set its `finished_at` to more than 48 hours in the past.
- **Failed** — set its `status` to `failed` and give it an `error_message`.
- **Loading** — set its `finished_at` to `null`.

### Manual review checklist

Automated coverage is PHPUnit only at this project's `NORMAL` test bar — no Playwright, Dusk, or
axe automation. Before accepting a change to the dashboard's states, check by hand:

- Each of the five sync states above, at 375 px and 1280 px, in both light and dark theme.
- The amber warning banner clears contrast against both card surfaces.
- The skeleton's pulse stops under an OS "reduce motion" setting (`prefers-reduced-motion:
  reduce`) and otherwise animates.
- The header chip disappears below 640 px and the footer's lowercase line takes over.

## Tests and quality

```bash
php artisan test --compact        # PHPUnit suite
php artisan larapilot:quality     # Laravel Pint + Larastan (level 5)
vendor/bin/pint                   # apply formatting
```

CI runs the same gates — Pint, Larastan, PHPUnit, `composer audit` and the Vite build — on pushes
to `develop` and `main`, and on every pull request targeting them. Pushing a feature branch does
not trigger CI on its own; opening the PR does.
