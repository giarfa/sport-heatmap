# Garmin CSV import

| | |
| --- | --- |
| **Status** | Active |
| **Introduced by** | `US-008` |
| **Last updated by** | `US-021` — 2026-10-26 |
| **Owner persona** | 🔧 Alex |

## Purpose

Lets the owner load their runs, the only way a run enters the app, by uploading the "Activities" CSV exported from the Garmin Connect website
(FR-018). Since `US-020` the import requires the owner's session and always
imports into the signed-in owner (`giarfa`): there is no guest import and no
CSV-only athlete creation, and the heatmap works from imports alone. The file is parsed into running rows of any age (dated today or earlier, so the
heatmap can be navigated back to them — `US-013`), and
every row that clashes with a stored run is reviewed before anything is
written. If it stops working, no run reaches the heatmap.

## Functional flow

### 1. Parsing (`ParseGarminCsv`)

`ParseGarminCsv::__invoke($path, $today)` reads the uploaded
file and returns a `ParsedGarminCsv`. It writes nothing.

1. **Whole-file checks** — the import stops with `GarminCsvRejectedException`
   and nothing is written when:
   - the file is unreadable, contains NUL bytes, or is not valid UTF-8 →
     "This is not a CSV file."
   - the header carries neither `Tipo di attività` (Italian) nor
     `Activity Type` (English) together with all but one of that language's
     other required headers → "This is not a Garmin Connect activities
     export…" (other exports share three English names, so one shared name
     is not enough)
   - a required column of that language is missing → the message names it
   - there is a header but no data row → "The file has no activities."
   - more than **5,000** runs, or more than **100** rows are unreadable → the
     message says so ("The file has more than 5000 runs. Export a shorter period
     from Garmin Connect."). These caps bound what one 5 MB upload can put in the
     session and in front of the planner. The run cap was 1,000 while only the
     last 16 weeks were kept; with older runs kept, a full export of several
     years is the normal case, and 5,000 is about 13 years at a run a day.
2. **Per row**, in this order — the first rule that applies decides the row:
   1. **Type** — not a running type → `ignoredByType[name] += 1` (names cut
      to 60 characters; beyond 50 distinct names the rest count as
      `(other types)`).
   2. **Date** — not `Y-m-d H:i:s` / `Y-m-d H:i` (or an overflowing date such
      as `2026-02-30`) → `invalid[] = {line, "unreadable date"}`.
   3. **Future** — dated after today → `futureDated += 1` and the row is
      skipped. A run of any age up to today is kept; which window it falls in is
      the dashboard's business, not the importer's (`US-013`, FR-018).
   4. **Distance** — `"--"` or empty → `invalid[] = {line, "missing
      distance"}`; non-numeric or more than six integer digits →
      `"unreadable distance"` (an unbounded value would overflow the int cast
      into a negative distance).
   5. **Moving time** — `"--"` falls back to the `Tempo` / `Time` column; if
      that is also unreadable → `invalid[] = {line, "missing moving time"}`.
   6. **Title** — trimmed, `Run` when empty, cut to 255 characters.
   7. **Run metrics** — pace, ascent and heart rate (below) are read last and
      can never reject, invalidate or drop the row: an absent column, `--`, or
      an unreadable value leaves **only that metric** null.
3. Rows identical **within the file** (same start, distance and moving time)
   collapse into one.

Invalid rows carry their 1-based line number in the file, and the remaining
rows still parse.

### Accepted headers

| Key | Italian | English | Required |
| --- | --- | --- | --- |
| type | `Tipo di attività` | `Activity Type` | yes — decides the language |
| date | `Data` | `Date` | yes |
| title | `Titolo` | `Title` | yes |
| distance | `Distanza` | `Distance` | yes |
| moving time | `Tempo in movimento` | `Moving Time` | yes |
| time | `Tempo` | `Time` | no — fallback for a `"--"` moving time |
| average pace | `Passo medio` | `Avg Pace` | no — `US-012` |
| total ascent | `Ascesa totale` | `Total Ascent` | no — `US-012` |
| average heart rate | `FC Media` | `Avg HR` | no — `US-012` |

Columns are located **by name**, never by position. A UTF-8 BOM on the first
cell is stripped. Optional columns are skipped when absent and never count
toward language detection, so a file without them parses exactly as before.

**English metric headers are an assumption** (decision `98fe4bd16f3263d6`): the
Italian names come from a real export; `Avg Pace` / `Total Ascent` / `Avg HR`
follow the existing English fixture and are unconfirmed by a real English export.
If a real one differs, the fix is one line in `GarminCsvColumns::HEADER_SETS`;
until then an English file imports with empty metrics, which is safe.

### Running types

Matched trimmed and case-insensitively (`GarminRunningTypes`):

| Stored as | Garmin names |
| --- | --- |
| `Run` | `Corsa`, `Running`, `Corsa su strada`, `Street Running`, `Corsa su pista`, `Track Running`, `Tapis roulant`, `Corsa su tapis roulant`, `Treadmill Running`, `Corsa indoor`, `Indoor Running` |
| `TrailRun` | `Trail running`, `Corsa su sentiero` |
| `VirtualRun` | `Corsa virtuale`, `Virtual Run`, `Virtual Running` |

### Value rules

| Column | Rule | Example |
| --- | --- | --- |
| Distance | km, `.` decimal, `,` thousands → `round(km × 1000)` integer metres | `"8.29"` → 8290 · `"1,005.20"` → 1 005 200 |
| Moving time | `HH:MM:SS`, `MM:SS`, optional fractional seconds → rounded seconds | `"00:54:23"` → 3263 · `"00:40:12.6"` → 2413 |
| Date | wall-clock, **no timezone conversion**; `activity_date` is its date part | `2026-10-10 09:33:27` |
| Average pace | `m:ss` or `h:mm:ss` (the moving-time grammar) → whole seconds per km; `0` is not recorded | `"5:23"` → 323 · `"1:05:10"` → 3910 |
| Total ascent | metres, `,` thousands, optional decimal rounded; up to six integer digits; a flat run's `0` stays `0` | `"1,234"` → 1234 · `"45.6"` → 46 |
| Average heart rate | up to three digits, optional decimal rounded; `0` is not recorded | `"152"` → 152 · `"148.6"` → 149 |

Implausible values are not recorded either (pace above 36 000 s/km, ascent above
20 000 m, heart rate above 250 bpm): the file is untrusted input and the
values are drawn on the owner's map.

`--`, empty, an absent column, or anything that does not match is a **null
metric, never `0`**: a stored `0` would read on the dashboard as a measurement.
The metrics are **not part of a run's identity** (`GarminCsvRow::isIdenticalTo()`
and `identityKey()` compare start, distance and moving time only), so
re-importing a file whose metrics changed still recognises its runs. In the
session `GarminCsvRow::fromSessionArray()` reads the three keys with `?? null`,
so an upload parked before `US-012` shipped can still be confirmed afterwards.
`GarminCsvRow::toAttributes()` and `metricAttributes()` carry **only the metrics
the file recorded** — an empty one is left out, not nulled, so writing the
attributes onto a stored run can never blank a value it already holds.

### 2. Target and planning (`PlanGarminImport`)

The import plans against and writes to the **signed-in owner**
(`$request->user()`), passed to the planner and the apply action as a non-null
`User`. There is no resolver: the review and the confirm read the same user from
the session.

`PlanGarminImport::__invoke(User, rows)` sorts the parsed rows against the
owner's stored activities. It reads only.

1. **One query** loads the owner's activities on the dates the file contains
   (`Activity::onDates()`, a `whereIn` on `activity_date` served by the
   `(user_id, activity_date)` index) — never one query per row. The stored runs
   are then **indexed by date once** (every stored run by `start_date_local`
   date for the identity pass, the unclaimed ones by `activity_date` for the
   pairing pass), so a row is compared only with the runs of its own date, not
   with every stored run on every date of the file (`US-018`).
2. **Already imported** (first pass, whole file): a stored run with
   the identical start (to the second), distance and moving time. It is not
   asked about, and that stored row is claimed.
3. **Conflict** (second pass, whole file): every (CSV row, stored row) pair
   that `StartTimeMatcher::difference()` accepts — **any stored run**,
   including one imported in an earlier session, on the same local date within **±2 minutes inclusive** (120 s
   matches, 121 s does not) — is ranked by start difference, and pairs are
   taken **closest first**, each CSV row and each stored row at most once.
   Ties go to the earlier CSV row, then the lower stored id.
4. **New**: everything else.

**The review reuses its plan** (`RememberedGarminImportPlan`, `US-018`). The review
is a GET the owner can reload, so the plan is computed once and kept in the session
under the pending entry (`garmin_import.pending.plan`: the target athlete id, a
stamp, and the row indexes of the new rows plus the stored run id of each conflict
and each already-imported row). On the next render the stored runs on the upload's
dates are loaded once (`PlanGarminImport::storedRuns()`), the **stamp** — a SHA-256 of
each such run's `start_date_local`, `activity_date`, `distance_meters` and
`moving_time_seconds`, by id — is recomputed, and when the athlete and the stamp match
the plan is rebuilt from the parsed rows and those loaded runs with no planning. Any
run added, removed or changed on one of those dates, a different target, or a new
upload re-plans: `put()` replaces the whole pending entry, so a new upload carries no
old plan, and `forget()` drops it with the entry. With no target nothing is
remembered (no database work to save). The confirm never reuses it: it plans fresh
under its lock.

The result is a `GarminImportPlan` (`newRows`, `conflicts`, `alreadyImported`,
all keyed by the row's index in the parsed file, `alreadyImportedActivities` —
the stored run each already-imported row matched, so the apply step needs no
second lookup — plus the target's `athleteId`) with a
`fingerprint()`: a SHA-256 of the
target id, the `rowIndex => storedActivityId` conflict pairs, and the
already-imported row indexes — what the owner was shown.

### 3. Applying (`ApplyGarminImport`)

`ApplyGarminImport::__invoke(User, plan, resolutions, parsed)` runs inside
**one** `DB::transaction`:

- each new row → `$user->activities()->create()`;
- **Overwrite** → the stored row takes the CSV's name, distance, moving time,
  start, `activity_date` and sport type. The three run metrics are replaced by the
  CSV's **except where the CSV recorded none**: `GarminCsvRow::toAttributes()`
  omits an empty metric, so a `--` keeps the stored value. Blanking a pace the
  owner already has because the new export lacks it would be data loss;
- **Skip**, or no answer at all → nothing is written;
- **Already imported** (same start, distance and moving time) → only the
  metrics its stored run **lacks** are filled from the row
  (`fillEmptyMetricsOfAlreadyImportedRuns()`); a metric already stored is never
  touched and the row is saved only when something was filled. So a run
  imported before `US-012` gains its pace, ascent and heart rate from a fresh
  export, and re-importing the same file again changes nothing. These rows
  still count as "already imported" in the summary — backfilling adds no new
  count.

The passed owner is only read, never `save()`d. After the commit it retires every
cached window grid of the owner (`HeatmapGridCache::forgetAthlete()`), so the dashboard shows the import on the next
request, whichever window is opened — also after a backfill-only import, whose metrics the cached day
detail carries. It returns a `GarminImportSummary`: imported, overwritten, skipped,
already imported, ignored by type, future-dated, invalid. The action does not
authorize (`.ai/rules/actions.md`); the caller has.

### 4. HTTP flow

All routes sit under `import/garmin`, named `garmin.import.*`, in the `auth` +
`password.set` route group (`US-020`): a signed-out request goes to `/login`,
an owner with no password yet to the first-password page. CSRF comes from the `web` group.

| Route | Name | Does |
| --- | --- | --- |
| `GET /import/garmin` | `create` | Upload form |
| `POST /import/garmin` | `store` | `UploadGarminCsvRequest` → `ParseGarminCsv` → `PendingGarminImport::put()` → review. Throttled `garmin-import` (10/min per signed-in owner; the review and the confirm draw on the same budget, and the redirect to the review is itself a hit, so an upload and its review cost two of the ten) |
| `GET /import/garmin/review` | `review` | Throttled `garmin-import`, the same limiter as upload and confirm (`US-018`). Plans the pending rows for the owner against the DB **as it is now**, reusing the plan remembered from the previous render while the stored runs on the upload's dates are unchanged (see *The review reuses its plan*); renders conflicts, new rows, counts, `token`, `fingerprint` |
| `POST /import/garmin/confirm` | `confirm` | `ConfirmGarminImportRequest`; throttled `garmin-import`; under the **instance-wide** `Cache::lock('garmin-import:confirm')`, re-plans for the owner; token + fingerprint must match; every conflict needs an answer; `ApplyGarminImport`; forgets the pending import; summary flashed |
| `POST /import/garmin/cancel` | `cancel` | Forgets the pending import → dashboard, "Import cancelled. Nothing was written." |
| `GET /import/garmin/summary` | `summary` | Renders the flashed `GarminImportSummary`; without one → dashboard |

- **Instance-wide confirm lock**: one confirm at a time on the whole instance
  (one owner, so serialising costs nothing); a second concurrent confirm (a
  double click, a second tab) re-plans after the first committed, finds its runs
  already imported, fails the fingerprint and goes back to the review. A confirm that waits out the 10 s
  block (`LockTimeoutException`) writes nothing and goes back to the review
  with "Another import is being saved — confirm again in a moment."; the upload
  stays pending.
- **Session binding**: `PendingGarminImport` is the only reader and writer of
  `garmin_import.pending` = `{token, parsed}` (scalars only). No route or field
  names an import or an athlete, so a request can only reach the one in its
  own session. Abandoning the review leaves just this entry; the next upload replaces it.
- **The file is never stored**: the controller parses
  `$request->file('csv')->getRealPath()` and never calls `store()`; PHP deletes
  the temporary upload when the request ends.
- **Staleness**: confirm recomputes the plan and compares
  `GarminImportPlan::fingerprint()` with the one the review rendered, and the
  token with the session's (both with `hash_equals`). A mismatch redirects to
  the review with "Your stored activities changed since this review — check
  the conflicts again." and writes nothing. A review page left open across a
  newer upload fails the token check the same way, and so does a different
  signed-in user.
- **Rejections** (`UploadGarminCsvRequest` or `GarminCsvRejectedException`) go
  back to the form with the message on `csv`, linked to the input through
  `aria-describedby`; nothing is parsed into the session.
- **Views** (`resources/views/garmin/`) follow `.larapilot/mockups/US-008/`:
  real `<table>`s with captions and scoped headers that reflow into stacked
  cards below 640 px (`td[data-label]`); a fieldset per conflict with Skip
  pre-checked; "Overwrite all" / "Skip all" rendered `hidden` and revealed by
  `resources/js/app.js` (`data-resolve-all`); the stored side of a conflict shows its
  values and the run's name, with no link and no source label. Formats come from
  `GarminImportFormat` (km to one decimal, `H:MM:SS`, `HH:MM:SS` start, and
  `pace()` `5:23 /km`, `ascent()` `45 m`, `heartRate()` `152 bpm` — each null
  when not recorded, which `<x-run-metric>` prints as an em dash read aloud as
  "not recorded").
- **Review metrics** (`US-012`, mockup `.larapilot/mockups/US-012/review-metrics.html`):
  each conflict's Stored and Garmin-file sides gain `Avg pace`, `Ascent` and
  `Heart rate` rows under `Moving`. The Stored side always shows the stored
  run's details (the guest masking is gone with the guest path).

## Technical design

| Piece | Where | Role |
| --- | --- | --- |
| Parser | `app/Support/Garmin/ParseGarminCsv.php` | File → `ParsedGarminCsv`; `SplFileObject`, no package |
| Header sets | `app/Support/Garmin/GarminCsvColumns.php` | Language detection, column lookup by name |
| Type map | `app/Support/Garmin/GarminRunningTypes.php` | Garmin type name → `SportType` |
| Row DTO | `app/Data/Garmin/GarminCsvRow.php` | `toAttributes()`, `toSessionArray()` / `fromSessionArray()`, `isIdenticalTo(Activity)` |
| Parse result | `app/Data/Garmin/ParsedGarminCsv.php` | Rows, ignored by type, future-dated count, invalid list; session round-trip (`fromSessionArray()` tolerates the pre-`US-013` `outside_window` key and reads the missing `future_dated` as 0, so an upload or summary in flight across the deploy still opens) |
| Rejection | `app/Exceptions/Garmin/GarminCsvRejectedException.php` | Owner-facing messages; no file content beyond a column name |
| Remembered plan | `app/Support/Garmin/RememberedGarminImportPlan.php`, `PendingGarminImport::rememberPlan()` / `rememberedPlan()` | The review's plan, kept in the session with a stamp of the planner's database inputs and reused while it holds (`US-018`) |
| Planner | `app/Support/Garmin/PlanGarminImport.php` | New / conflict / already imported, one query through `Activity::onDates()`, per-date indexes, claiming |
| Matcher | `app/Support/Garmin/StartTimeMatcher.php` | Same date, ±120 s inclusive, closest, ties to the lowest id |
| Plan DTOs | `app/Data/Garmin/GarminImportPlan.php`, `GarminImportConflict.php` | The plan and its `fingerprint()` |
| Apply action | `app/Actions/Garmin/ApplyGarminImport.php` | One transaction; Overwrite and the already-imported metric backfill; every cached window grid of the athlete retired after commit (`forgetAthlete()`) |
| Answer | `app/Enums/ConflictResolution.php` | `overwrite`, `skip` |
| Summary DTO | `app/Data/Garmin/GarminImportSummary.php` | The seven counts; session round-trip for the summary page |
| Routes | `routes/web.php` (`garmin.import.*`, in the `auth` + `password.set` group), `garmin-import` limiter in `app/Providers/AppServiceProvider.php` | |
| Controllers | `app/Http/Controllers/Garmin/*` | Six single-action controllers |
| Form Requests | `app/Http/Requests/Garmin/UploadGarminCsvRequest.php`, `ConfirmGarminImportRequest.php` | `required\|file\|max:5120\|mimes:csv,txt`; token, fingerprint, `resolutions.*` in `ConflictResolution` |
| Session binding | `app/Support/Garmin/PendingGarminImport.php` | The one reader/writer of `garmin_import.pending` |
| Formatting | `app/Support/Garmin/GarminImportFormat.php` | Shared by the review's stored and CSV sides |
| Views / assets | `resources/views/garmin/{create,review,summary}.blade.php`, "Garmin import" block in `resources/css/app.css`, `data-resolve-all` in `resources/js/app.js` | |
| Tests | `tests/Feature/Garmin/*`, `tests/Unit/Support/Garmin/*`, `tests/Feature/Database/RemoveStravaMigrationTest.php` | |
| Fixtures | `tests/Fixtures/Garmin/activities-it.csv` (client sample, verbatim), `activities-en.csv` | |

### Data model (`activities`)

The table the import writes and the dashboard reads — one row per run (moved here from
the retired `activity-sync.md` by `US-021`). `activity_date` is the local calendar date
of the run, indexed together with `user_id` as `[user_id, activity_date]`, the shape of
the dashboard aggregate and of the import's conflict lookup. `sport_type` is one of
`Run`, `TrailRun`, `VirtualRun` (`app/Enums/SportType.php`, FR-003).

Three nullable integer columns hold the per-run metrics: `average_pace_seconds_per_km`
(whole seconds per km), `total_ascent_meters` (whole metres) and `average_heart_rate`
(whole bpm). **NULL means "not recorded"** and is shown as an em dash; a stored `0`
would read as a measurement, so there is no column default and no writer may store `0`
for a missing value. They are plain `#[Fillable]` values cast to `integer`, read only
with the run's own row, so they carry **no index**: every query filters on
`(user_id, activity_date)` first. `activities.user_id` is not mass-assignable; write
through `$user->activities()`.

There is no `source` and no `strava_activity_id` column: `US-021` deleted every
Strava-sourced run and dropped both, together with the Strava columns of `users` and
the `sync_runs` table (`remove_strava_from_schema`). The migration is irreversible: its
`down()` restores the schema, never the deleted rows or the tokens.

## Architectural choices

| Choice | Alternatives considered | Why this one |
| --- | --- | --- |
| Older runs are kept; only future-dated rows are skipped and counted as "Future-dated" (US-013, decision `17affbd2ffbcf2fe`) | Keep skipping what is outside the 112 days; import everything including future rows; a user-chosen cut-off | The heatmap is navigable back through history, so dropping an old run would make Previous show a hole the athlete's own export fills. A future-dated row is a clock or a typo, and on the dashboard it would be a cell that has not happened. The old count, "Outside 16 weeks", described a rule that no longer exists, so the counter, its session key and its label were renamed rather than reused with a new meaning |
| `SplFileObject` CSV reader | `league/csv`, `maatwebsite/excel` | One flat file of ~100 rows; PHP's reader covers it and the project adds no dependency |
| Parse inside the request | Queue a job | A whole export parses in milliseconds, and the owner is waiting on the review page anyway |
| Locate columns by header name, per language | Fixed column positions | Garmin translates headers and changes column sets between exports; positions would silently read the wrong column |
| List unknown types by name in the summary (decision `56c5683061907ab8`) | Drop them silently; reject the file | Only `Corsa` is confirmed by a real export. A missed running variant must be visible to the owner so it becomes a one-line addition |
| "Already imported" = same start, distance and moving time on a stored run | A hash of the CSV row as its identity | Garmin exports carry no activity id, and a re-export may add, drop or reorder columns, changing the hash of an unchanged run |
| Identity pass before the conflict pass | One pass in file order | A row a minute away could claim the stored run first, and the row identical to it — a re-import — would be inserted a second time |
| Claim each stored row for one CSV row, closest pairs first | Let every CSV row match freely; claim in file order | Free matching lets two CSV runs overwrite one stored run; file order lets an earlier row 90 s away take a stored run a later row matches to the second, so the later row is planned as new and a Skip leaves that run on the map twice |
| Instance-wide lock around confirm | Rely on the token and fingerprint alone; a per-athlete lock | A double-clicked confirm sends two requests carrying the same session; serialised, the second re-plans after the first committed, finds its runs already imported, fails the fingerprint and writes nothing. A single-owner instance loses nothing by serialising |
| Import behind the owner session (`US-020`; supersedes the signed-out import of US-011) | Keep the guest import | The instance is private: the import writes into the owner's data, so only the signed-in owner may use it. The heatmap still works with CSV imports only |
| Every stored run is a conflict candidate (US-021) | Only runs of a given source | With Strava gone there is one source; a second, slightly different export of the same run must be reviewed, not duplicated, whichever session imported the stored one |
| The `activities.source` column is dropped, not narrowed to one value (US-021) | Keep it with only `garmin_csv` as a value; a check constraint | A column that cannot differ says nothing and invites a reader to look for the other value; SQLite rebuilds the table either way, so narrowing saved nothing. The migration that deleted the Strava runs is `remove_strava_from_schema` |
| A missing answer is Skip | Reject the confirm | Skip is the pre-checked, non-destructive default; the Form Request already requires an answer |
| Pending rows in the owner's session | A `pending_imports` table | A table would persist owner data beyond the request, need cleanup of abandoned reviews, and expose an id a request could name |
| Re-plan at confirm and compare a fingerprint | Trust the plan rendered in the review | Another tab can change stored runs between review and confirm; the owner's answers must apply to the runs they saw |
| Form Requests for upload and confirm | Inline `$request->validate()` | First user-submitted forms in the app; `.ai/rules/http.md` was amended for them |
| Reject the whole file only for file-level problems | Reject on the first bad row | One corrupt row must not cost the owner the other thirty-nine |
| Fingerprint carries the target id | Compare the conflicts only | A plan with no conflicts hashes the same for every athlete, so a target change between review and confirm would go unnoticed |
| Throttle the review with the upload's and confirm's `garmin-import` limiter, and index stored runs by date (US-018) | A looser limit for the review; a cached plan alone; leave the scan | Each render re-plans every row against the stored runs, so a route repeated in a loop was unbounded work: rows × stored runs per reload. One shared limiter means the three routes cannot be hammered in turn to get ten of each. Indexing makes the work rows × runs-of-that-date, and the plan keeps the same results because the matcher already required the same date |
| Remember the review's plan in the session, stamped with the planner's inputs (US-018) | Cache it; stamp by `updated_at` or a count / `MAX(updated_at)` aggregate; re-plan every render | The plan belongs to the pending upload, which already lives in the session, so it dies with the upload and needs no key or TTL. `updated_at` has one-second resolution and a query-builder write does not touch it; an aggregate cannot see a run whose distance changed. A stamp over exactly the columns the planner compares can. The stored runs are loaded once per render and feed the stamp, the planner and a reused plan alike, so the review costs one activities query hit or miss, and the runs it renders (name, metrics, link) are always current |
| The confirm still plans fresh (US-018) | Confirm from the remembered plan | The confirm's fingerprint exists to compare the review against the database at write time, inside the lock; a remembered plan would compare it with itself |
| Wall-clock dates built in UTC | Parse with the app timezone | Garmin exports the runner's local wall-clock time; a conversion moves late-evening runs to the wrong day |

## Key decisions & invariants

- **Invariant — the parser writes nothing.** Planning and the owner's review
  come first. _Breaks if:_ a write is added here — abandoning the review would
  no longer leave the database untouched.
- **Invariant — one definition of "the same run".** Conflicts use
  `StartTimeMatcher`: same local date, ±2 minutes **inclusive**, closest wins.
  _Breaks if:_ the review and the confirm inline their own rule — a run the review
  called "stored" could be one the write calls "new", and the day is counted twice.
- **Invariant — a stored run is never replaced by a re-read of the file.** Only an
  explicit Overwrite changes one, and an already-imported row fills only the metrics
  its stored run lacks. Nothing else writes a run: no background job exists.
- **Invariant — all or nothing.** Every write of one confirm is in one
  transaction. _Breaks if:_ rows are created outside it — a failure halfway
  would leave half an import that a re-upload then reports as part new, part
  already imported.
- **Invariant — the import never creates a user.** It writes into the
  signed-in owner only; `CreateCsvOnlyAthlete` and the guest path are gone.
  _Breaks if:_ a route imports for anyone but `$request->user()`.
- **Invariant — the fingerprint carries the target.** _Breaks if:_ the athlete
  id is dropped from it — a confirm could write to an athlete the review never
  planned against.
- **Invariant — re-importing the same file changes nothing.** Every row is
  already imported, so nothing is created or asked about.
- **Invariant — the session is the only binding.** _Breaks if:_ a route or
  form field starts naming an import or an athlete — a request could then
  reach another session's pending rows or write to a chosen athlete.
- **Invariant — the raw file is never persisted.** _Breaks if:_ the upload is
  `store()`d — an export carries heart rate, cadence and locations.
- **Invariant — a remembered plan is reused only while every input of the planner is
  unchanged.** _Breaks if:_ the planner starts reading a stored column the stamp
  leaves out (`RememberedGarminImportPlan::stampOf()`), or a new field joins what
  the plan is made from: a reload would then show a stale review. The confirm's
  fingerprint would still refuse a stale write, but the owner would answer a
  review that no longer matches.
- **Invariant — nothing is written before confirm**, and a stale confirm writes
  nothing.
- **Invariant — a rejection message never echoes file content** beyond a
  column name. _Breaks if:_ a cell value is interpolated — the upload page
  would render attacker-chosen text.
- **Invariant — `start_date_local` is never timezone-converted.**

## Extension points & gotchas

- **Adding a running type name:** one line in `GarminRunningTypes::TYPES`,
  lower-cased. The summary's "Ignored activity types" list is where a missing
  one shows up.
- **Known gap — "today" is fixed at upload.** A review confirmed after midnight
  still imports the rows that were not future-dated when parsed; a row dated
  tomorrow at upload time stays skipped even though tomorrow has come.
- **Parsed rows live in the session store.** The raw file is never stored, but
  the parsed rows (names, starts, distances) sit in `sessions.payload` until
  confirm, cancel, the next upload, or session expiry. Set
  `SESSION_ENCRYPT=true` if that store is shared.
- Rows identical within one file collapse silently; they are counted under no
  heading, so the totals can be lower than the file's row count.
- **Adding a language:** one more header set in `GarminCsvColumns`; the type
  map needs that language's running names too.
- **Known limitation — metric accounts only.** Garmin exports distance in the
  account's unit setting, and an imperial export cannot be told apart from the
  CSV (pace and distance stay self-consistent in either unit). The PRD is
  metric-only; the upload hint says "metric units".
- Garmin writes thousands separators in **both** languages (`"8,240"` steps in
  the Italian sample), so `,` is never a decimal mark here.

## Related

- Specs: `US-008`, `US-011`, `US-012`, `US-013`, `US-018`, `US-020`, `US-021`
- Plans: `.larapilot/plans/US-008-plan.yaml`, `.larapilot/plans/US-011-plan.yaml`, `.larapilot/plans/US-013-plan.yaml`, `.larapilot/plans/US-020-plan.yaml`
- Mockups: `.larapilot/mockups/US-008/`
- Decisions: `56c5683061907ab8` (running type names; unknowns surfaced)
- Other domains: `heatmap-dashboard.md`, `owner-authentication.md`
