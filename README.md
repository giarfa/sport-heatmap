# Sport Heatmap

A private single-athlete training heatmap. It reads your own runs from Garmin Connect — automatically
every day, on demand with **Sync now**, or from a CSV export — and renders them as a GitHub-style
contribution grid, so a training block is readable at a glance.

One athlete, one screen, no public signup: every page sits behind a single password-protected account
(see "First sign-in and recovery" below).

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
   TLS certificate, which the secure session cookie below needs.

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

   `APP_URL` must match the domain Herd serves, or generated links will not line up. Add
   `LOG_LEVEL=debug` if you want the full log. Leave `SESSION_SECURE_COOKIE=true` — Herd serves
   HTTPS.

4. **Watch assets while developing:**

   ```bash
   npm run dev
   ```

   `composer run dev` is also available: it runs the PHP server, queue listener, log tail and
   Vite together for people who prefer `php artisan serve` over Herd. That server answers on plain
   HTTP, where browsers drop `Secure` cookies, so set `SESSION_SECURE_COOKIE=false` in `.env` for
   that setup only.

## First sign-in and recovery

The whole app sits behind one account: the user `giarfa`. There is no registration and no other way in.

1. Open the site. Every page sends a signed-out browser to `/login`.
2. Sign in as `giarfa` with an **empty password**. This works only while no password is stored.
3. Set a password of **at least 12 characters** (typed twice). You land on the dashboard, and from
   then on only that password signs in.

Do this right after every deploy that runs the username migration: until the password is set,
anyone who knows the username can set it first (an accepted risk — the migration resets the owner's
password to empty so the first sign-in can happen).

Five wrong attempts for the same username and IP within a minute lock that pair out for the
remaining seconds.

**Recovery.** To re-arm the first sign-in (a forgotten password), clear the stored password on the
SQLite file, then sign in with an empty password again:

```sql
UPDATE users SET password = NULL WHERE username = 'giarfa';
```

## Removing the former Strava connection

Since 3.0.0 the app has no Strava connection: no OAuth, no stored token, and no Strava data. Runs
enter through the Garmin Connect sync or the Garmin CSV import (see below).

If you used an earlier version, do one thing by hand: **revoke the former app at
<https://www.strava.com/settings/apps>**, so Strava stops listing it among your authorized
applications. The upgrade migration **permanently deletes every run that came from Strava**, so back
up `database/database.sqlite` first. To restore older days afterwards, import your full Garmin Connect
history.

No queue worker is required. The scheduler (cron) is needed only for the daily Garmin sync (see
**Syncing runs from Garmin Connect**); without a `GARMIN_API_KEY` nothing runs in the background.

### Before putting this on a real host

Only `/login` and the `/up` health route answer without a session, which makes them the part of this
app a stranger can actually touch. Start with how the code is installed, then the settings.

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
| `APP_URL` | `https://…` | The host's own HTTPS URL; generated links are built from it |
| `LOG_STACK` | `daily` | One file per day, old ones rotated out, instead of a single log that grows forever |
| `LOG_LEVEL` | `warning` | `debug` writes request detail to disk that nobody on a real host needs |
| `SESSION_SECURE_COOKIE` | `true` | Without it the sign-in cookies are also sent over plain HTTP |

If the app sits behind a reverse proxy or CDN, also configure trusted proxies — the sign-in throttle
keys on the username and the client IP, and every request arriving from the proxy's address collapses
that budget into one shared bucket.

## Environment variables

The app-specific keys are few. Everything else in `.env.example` is Laravel's own.

| Key | Meaning | Example |
| --- | --- | --- |
| `HEATMAP_DISPLAY_TIMEZONE` | Timezone the import card prints its absolute "Last import" and "Last sync" times in, and the one the daily sync is scheduled in (timestamps are stored in UTC) | `Europe/Rome` |
| `GARMIN_API_KEY` | Your Garmin Connect personal API key. Empty (the default) turns the sync off: no Sync now button, no schedule effect, no status or warning | *(empty — never commit it)* |

`.env.example` lists it with its default. It is read through `config/heatmap.php` — never call `env()`
for it outside that file, because `env()` returns `null` once `config:cache` has run.

## Database and demo data

The database is a single SQLite file at `database/database.sqlite` (untracked).

```bash
php artisan migrate:fresh --seed
```

This produces a full **16-week training block** for one demo athlete — including an illness
week, a peak week and a partial current week — so the dashboard can be developed and reviewed
from Garmin-imported runs alone.

The demo athlete is the owner account `giarfa` with no password, so a freshly seeded database starts
at the first sign-in (see above). Never seed production — it would plant a demo athlete and runs in
your real database.

## Syncing runs from Garmin Connect

With a personal API key the app reads your running activities from Garmin Connect on its own: once a
day at 05:00 (`HEATMAP_DISPLAY_TIMEZONE`), and whenever you press **Sync now** under the map or on the
empty screen. The CSV import below keeps working next to it. With no key, nothing here applies.

**Create the key.** On the Garmin Connect website open **Settings → Personal API token**
(connect.garmin.com), create a key and put it in `.env` as `GARMIN_API_KEY=…` — the name only here, the
value never in Git, a log or a ticket. A key expires after somewhere between 30 days and a year. When it
does, the dashboard shows **"Garmin key rejected — renew it"**: create a new key, replace the value in
`.env` (and run `php artisan config:clear` if the config is cached), and press **Sync now**; the warning
goes away after the next successful sync.

**The first sync.** It reads your whole Garmin history, which can take longer than a web request
allows. Run it once from the command line on the host:

```bash
php artisan garmin:sync
```

It prints what it did (new, updated, linked, ignored, future-dated, invalid). A first sync that is cut
off is safe: what it stored stays, and the next attempt reads the whole history again until one has
finished. Later syncs re-read from seven days before your newest synced run, so a run you rename or edit
on Garmin within that span is updated here; a run you delete on Garmin stays here.

**What it writes.** Only running activities (running, street, track, indoor, treadmill, ultra, obstacle,
trail and virtual runs). A run on the same date starting within two minutes of one you imported from a
CSV is the same run: it gains its Garmin id and Garmin's values, and no review is shown. Running the sync
twice in a row changes nothing. A failed sync keeps every run it wrote before failing; the dashboard
names the cause ("Garmin key rejected — renew it", "Garmin unreachable", "Garmin sync failed") until the
next sync succeeds. Only one sync runs at a time, and Sync now is limited to three a minute.

**The scheduler.** The daily run is a Laravel scheduled task, so the host must run the scheduler every
minute. Add this cron entry for the app's user (on the production host, Cipi, add it as the app's cron
job) and check it with `php artisan schedule:list`:

```cron
* * * * * cd /path/to/the/app && php artisan schedule:run >> /dev/null 2>&1
```

The access token is kept in the `file` cache store (`storage/framework/cache`), never in the database,
and neither it nor the key is ever written to a log or an error message.

## Importing runs from Garmin Connect

The Garmin Connect CSV export is the other way a run enters the app (the sync above is the first).
Signed in as the owner, follow **Import Garmin CSV** under the map (or on the empty screen).

1. On the Garmin Connect **website**, open **Activities → All activities**, scroll until every
   run of the last 16 weeks is loaded, and press **Export CSV**. Both Italian (`Tipo di attività`,
   `Data`, `Distanza`, …) and English (`Activity Type`, `Date`, `Distance`, …) exports are accepted.
2. Upload the file (max 5 MB). The account must use **metric units**: Garmin writes distance in the
   account's unit, and an imperial export cannot be told apart from a metric one.
3. Review. Only running types are kept (every other type is counted and listed by name); rows
   dated in the future are counted and skipped, while runs of any age are kept; a row with an unreadable
   date, distance or moving time is listed with its line number while the rest still import.
   A row **clashes** with a stored run that starts on the same date within ±2 minutes (the closest
   one). For each clash choose:
   - **Skip** (pre-selected) — keep the stored run untouched;
   - **Overwrite** — replace its name, distance, moving time and start with Garmin's values. A run the
     sync wrote keeps its Garmin id; note that the next sync re-reads the last seven days and applies
     Garmin Connect's values again.
   Runs with no clash are added as they are. A row identical to a run already imported from Garmin
   is not asked about, so re-importing the same file changes nothing.
4. **Confirm import** writes everything in one transaction; **Cancel**, or simply leaving the page,
   writes nothing. A summary shows imported, overwritten, skipped, already-imported, ignored,
   outside-window and unreadable counts, and the map updates immediately.

A stored run is never replaced by a re-read of the file: only an explicit **Overwrite** changes one,
and nothing else writes a run except the Garmin sync. The uploaded file itself is never stored: it is read during the request
and discarded. Imported runs carry no link anywhere.

## The dashboard

The home page (`/`) is **private**: a signed-out browser is sent to `/login`. The signed-in owner sees
the heatmap, weekly totals, legend and import status built from their own runs. Opening it
never calls out or writes a row. Under the map, a discreet line offers **Import Garmin CSV**, **Sync
now** (only with a `GARMIN_API_KEY`) and **Sign out**. An owner with no runs sees an empty screen that
offers the Garmin CSV import and, with a key, **Sync now**. The page keeps `noindex, nofollow`.

The heatmap grid is cached per athlete, per 16-week window and per calendar day
(`App\Support\Heatmap\HeatmapGridCache`), so a second request the same day skips the aggregate
query. The cache is cleared for the owner after every confirmed import and after every sync that wrote a run,
so the new runs are visible on the next request. The calendar day is part of the cache key, so a cached grid can never survive into
the next day even without an import in between.

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
every request that misses the grid cache, and the cache is cleared on every confirmed import — so
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

### Import status

`App\Support\Garmin\ResolveImportStatus` reads the status fresh on every request — never from the grid
cache. The header chip reads the more recent of **"Last sync {relative time}"** (the newest successful
sync, even one that wrote nothing) and **"Last import {relative time}"** (the newest CSV-imported run's
`updated_at`; runs the sync wrote never move it). With neither it reads **"No runs imported yet"**, or
**"Not synced yet"** when a key is set. Below 640 px the footer's lowercase line takes over. The card in
the rail prints the absolute times in `HEATMAP_DISPLAY_TIMEZONE`, with a **Last sync** row only when a key
is set. A re-import that writes nothing does not move the import time.

While the newest finished sync attempt failed, a warning above the map names the cause; the next
successful sync removes it. With no `GARMIN_API_KEY` there is no sync status, no warning and no **Sync
now** anywhere: the dashboard behaves exactly as it did before the sync existed.

### Manual review checklist

Automated coverage is PHPUnit only at this project's `NORMAL` test bar — no Playwright, Dusk, or
axe automation. Before accepting a change to the dashboard, check by hand:

- The status line and the empty state, at 375 px and 1280 px, in both light and dark theme.
- The empty state's **Import Garmin CSV** button is a full-width 44 px target on a phone, and with a
  key **Sync now** sits under it as a secondary full-width button.
- With a key: **Sync now** in the line under the map is a 44 px target; set a wrong key and check
  **"Garmin key rejected — renew it"** reads clearly in both themes, then restore the key and check it
  goes away after the next sync.
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
