# Garmin CSV import

| | |
| --- | --- |
| **Status** | Active |
| **Introduced by** | `US-008` |
| **Last updated by** | `US-012` — 2026-10-13 |
| **Owner persona** | 🔧 Alex |

## Purpose

Lets the owner add runs that Strava is missing, or that Garmin recorded better,
by uploading the "Activities" CSV exported from the Garmin Connect website
(FR-018). Since US-011 any visitor can import, signed in or not: a guest
imports into the displayed athlete, or — on a fresh install — creates a local
CSV-only athlete, so the heatmap works with CSV imports only. The file is parsed into running rows inside the 112-day window, and
every row that clashes with a stored run is reviewed before anything is
written. If it stops working, Garmin-only runs never reach the heatmap; the
Strava sync is unaffected.

## Functional flow

### 1. Parsing (`ParseGarminCsv`)

`ParseGarminCsv::__invoke($path, HeatmapWindow, $today)` reads the uploaded
file and returns a `ParsedGarminCsv`. It writes nothing.

1. **Whole-file checks** — the import stops with `GarminCsvRejectedException`
   and nothing is written when:
   - the file is unreadable, contains NUL bytes, or is not valid UTF-8 →
     "This is not a CSV file."
   - the header carries neither `Tipo di attività` (Italian) nor
     `Activity Type` (English) together with all but one of that language's
     other required headers → "This is not a Garmin Connect activities
     export…" (Strava's export shares three English names, so one shared name
     is not enough)
   - a required column of that language is missing → the message names it
   - there is a header but no data row → "The file has no activities."
   - more than **1,000** runs survive into the window, or more than **100**
     rows are unreadable → the message says so. These caps bound what one 5 MB
     upload can put in the session and in front of the planner; a real
     16-week export holds a few hundred runs at most.
2. **Per row**, in this order — the first rule that applies decides the row:
   1. **Type** — not a running type → `ignoredByType[name] += 1` (names cut
      to 60 characters; beyond 50 distinct names the rest count as
      `(other types)`).
   2. **Date** — not `Y-m-d H:i:s` / `Y-m-d H:i` (or an overflowing date such
      as `2026-02-30`) → `invalid[] = {line, "unreadable date"}`.
   3. **Window** — after today, or outside the heatmap window →
      `outsideWindow += 1`.
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

### 2. Target and planning (`ResolveGarminImportTarget`, `PlanGarminImport`)

`ResolveGarminImportTarget::__invoke(?User $signedIn)` picks the athlete the
import plans against and writes to:

1. the **signed-in owner**, when there is one;
2. else the **displayed athlete** (`ResolveDisplayedAthlete`, FR-017) — the
   narrow `id, strava_athlete_id` model, so no token is ever loaded;
3. else **null** — no stored athlete has an activity yet.

The review and the confirm both resolve through it.

`PlanGarminImport::__invoke(?User, rows)` sorts the parsed rows against the
target's stored activities. It reads only. With a **null target** every row is
new and no query runs.

1. **One query** loads the owner's activities on the dates the file contains
   (`whereIn('activity_date', …)`, served by the `(user_id, activity_date)`
   index) — never one query per row.
2. **Already imported** (first pass, whole file): a stored `garmin_csv` row with
   the identical start (to the second), distance and moving time. It is not
   asked about, and that stored row is claimed.
3. **Conflict** (second pass, whole file): every (CSV row, stored row) pair
   that `StartTimeMatcher::difference()` accepts — a stored row of **either
   source** on the same local date within **±2 minutes inclusive** (120 s
   matches, 121 s does not) — is ranked by start difference, and pairs are
   taken **closest first**, each CSV row and each stored row at most once.
   Ties go to the earlier CSV row, then the lower stored id.
4. **New**: everything else.

The result is a `GarminImportPlan` (`newRows`, `conflicts`, `alreadyImported`,
all keyed by the row's index in the parsed file, plus the target's
`athleteId`, null when there is none) with a `fingerprint()`: a SHA-256 of the
target id, the `rowIndex => storedActivityId` conflict pairs, and the
already-imported row indexes — what the visitor was shown.

### 3. Applying (`ApplyGarminImport`)

`ApplyGarminImport::__invoke(?User, plan, resolutions, parsed)` runs inside
**one** `DB::transaction`:

- **null target with new rows** → `CreateCsvOnlyAthlete` first, inside the
  transaction: `firstOrCreate` on `email = csv-athlete@garmin.invalid`,
  `name = Garmin CSV athlete`, no password, no Strava id, no tokens. A null
  target with nothing to add writes nothing and creates no athlete;
- each new row → `$user->activities()->create()` with `source = garmin_csv`
  and no Strava id;
- **Overwrite** → the stored row takes the CSV's name, distance, moving time,
  start, `activity_date` and sport type, and `source` becomes `garmin_csv`.
  `strava_activity_id` is **kept**, so the nightly sync recognises the twin and
  skips it (see `activity-sync.md`);
- **Skip**, or no answer at all → nothing is written.

The passed athlete is only read, never `save()`d — it may be the narrow
displayed-athlete model. After the commit it forgets the cached grid for today
of the athlete actually written to
(`HeatmapGridCache::forget()`), so the dashboard shows the import on the next
request. It returns a `GarminImportSummary`: imported, overwritten, skipped,
already imported, ignored by type, outside window, invalid. The action does not
authorize (`.ai/rules/actions.md`); the caller has.

### 4. HTTP flow

All routes sit under `import/garmin`, named `garmin.import.*`, behind
`strava.fresh` only — **open to signed-out visitors** by the owner's choice
(US-011; PRD Security, accepted risk). `strava.fresh` is a no-op for guests and
still re-checks a signed-in owner's allowlist and token. CSRF comes from the
`web` group.

| Route | Name | Does |
| --- | --- | --- |
| `GET /import/garmin` | `create` | Upload form |
| `POST /import/garmin` | `store` | `UploadGarminCsvRequest` → `ParseGarminCsv` → `PendingGarminImport::put()` → review. Throttled `garmin-import` (10/min per signed-in owner, else per IP) |
| `GET /import/garmin/review` | `review` | Resolves the target, re-plans the pending rows against the DB **as it is now**; renders conflicts, new rows, counts, `token`, `fingerprint` |
| `POST /import/garmin/confirm` | `confirm` | `ConfirmGarminImportRequest`; throttled `garmin-import`; under the **instance-wide** `Cache::lock('garmin-import:confirm')`, resolves the target, re-plans; token + fingerprint must match; every conflict needs an answer; `ApplyGarminImport`; forgets the pending import; summary flashed |
| `POST /import/garmin/cancel` | `cancel` | Forgets the pending import → dashboard, "Import cancelled. Nothing was written." |
| `GET /import/garmin/summary` | `summary` | Renders the flashed `GarminImportSummary`; without one → dashboard |

- **Guests** reach every page. A guest's target is the displayed athlete
  (narrow model, no token column), else none — the confirm then creates the
  CSV-only athlete. A guest request never calls Strava and never reads or
  refreshes a token.
- **Instance-wide confirm lock**: one confirm at a time on the whole instance,
  with the target resolved **inside** the lock. A per-athlete key cannot exist
  before the CSV-only athlete does; serialised, a second concurrent first
  import re-plans against the athlete the first just created, fails the
  fingerprint and goes back to the review. A confirm that waits out the 10 s
  block (`LockTimeoutException`) writes nothing and goes back to the review
  with "Another import is being saved — confirm again in a moment."; the upload
  stays pending.
- **Session binding**: `PendingGarminImport` is the only reader and writer of
  `garmin_import.pending` = `{token, parsed}` (scalars only). No route or field
  names an import or an athlete, so a request can only reach the one in its
  own session — this is what keeps two signed-out visitors apart.
  Abandoning the review leaves just this entry; the next upload replaces it.
- **The file is never stored**: the controller parses
  `$request->file('csv')->getRealPath()` and never calls `store()`; PHP deletes
  the temporary upload when the request ends.
- **Staleness**: confirm recomputes the plan and compares
  `GarminImportPlan::fingerprint()` with the one the review rendered, and the
  token with the session's (both with `hash_equals`). A mismatch redirects to
  the review with "Your stored activities changed since this review — check
  the conflicts again." and writes nothing. A review page left open across a
  newer upload fails the token check the same way, and so does a target that
  changed since the review (an allowlisted athlete connected, the visitor
  signed in, another guest created the CSV-only athlete).
- **Rejections** (`UploadGarminCsvRequest` or `GarminCsvRejectedException`) go
  back to the form with the message on `csv`, linked to the input through
  `aria-describedby`; nothing is parsed into the session.
- **Guest masking**: the review controller passes
  `showsStoredRunDetails` (`$request->user() !== null`) once; the view only
  branches on it. The owner's review is unchanged; the Garmin-file side and the
  Overwrite / Skip choice are the same for everyone.
- **Views** (`resources/views/garmin/`) follow `.larapilot/mockups/US-008/`:
  real `<table>`s with captions and scoped headers that reflow into stacked
  cards below 640 px (`td[data-label]`); a fieldset per conflict with Skip
  pre-checked; "Overwrite all" / "Skip all" rendered `hidden` and revealed by
  `resources/js/app.js` (`data-resolve-all`); "View on Strava" only where
  `Activity::stravaUrl()` is not null (FR-008). Formats come from
  `GarminImportFormat` (km to one decimal, `H:MM:SS`, `HH:MM:SS` start).

## Technical design

| Piece | Where | Role |
| --- | --- | --- |
| Parser | `app/Support/Garmin/ParseGarminCsv.php` | File → `ParsedGarminCsv`; `SplFileObject`, no package |
| Header sets | `app/Support/Garmin/GarminCsvColumns.php` | Language detection, column lookup by name |
| Type map | `app/Support/Garmin/GarminRunningTypes.php` | Garmin type name → `SportType` |
| Row DTO | `app/Data/Garmin/GarminCsvRow.php` | `toAttributes()` (always `source = garmin_csv`), `toSessionArray()` / `fromSessionArray()`, `isIdenticalTo(Activity)` |
| Parse result | `app/Data/Garmin/ParsedGarminCsv.php` | Rows, ignored by type, outside-window count, invalid list; session round-trip |
| Rejection | `app/Exceptions/Garmin/GarminCsvRejectedException.php` | Owner-facing messages; no file content beyond a column name |
| Target | `app/Support/Garmin/ResolveGarminImportTarget.php` | Signed-in owner, else displayed athlete, else null |
| Planner | `app/Support/Garmin/PlanGarminImport.php` | New / conflict / already imported, one query, claiming; all new with no target |
| CSV-only athlete | `app/Actions/Garmin/CreateCsvOnlyAthlete.php`, `User::isCsvOnly()`, `UserFactory::csvOnly()` | Placeholder identity (`NAME`, `EMAIL` constants), reused by `firstOrCreate` |
| Matcher | `app/Support/Garmin/StartTimeMatcher.php` | Same date, ±120 s inclusive, closest, ties to the lowest id — shared with the sync |
| Plan DTOs | `app/Data/Garmin/GarminImportPlan.php`, `GarminImportConflict.php` | The plan and its `fingerprint()` |
| Apply action | `app/Actions/Garmin/ApplyGarminImport.php` | One transaction, CSV-only athlete created inside it; grid cache forgotten after commit |
| Answer | `app/Enums/ConflictResolution.php` | `overwrite`, `skip` |
| Summary DTO | `app/Data/Garmin/GarminImportSummary.php` | The seven counts; session round-trip for the summary page |
| Routes | `routes/web.php` (`garmin.import.*`, `strava.fresh` only), `garmin-import` limiter in `app/Providers/AppServiceProvider.php` | |
| Controllers | `app/Http/Controllers/Garmin/*` | Six single-action controllers |
| Form Requests | `app/Http/Requests/Garmin/UploadGarminCsvRequest.php`, `ConfirmGarminImportRequest.php` | `required\|file\|max:5120\|mimes:csv,txt`; token, fingerprint, `resolutions.*` in `ConflictResolution` |
| Session binding | `app/Support/Garmin/PendingGarminImport.php` | The one reader/writer of `garmin_import.pending` |
| Formatting | `app/Support/Garmin/GarminImportFormat.php` | Shared by the review's stored and CSV sides |
| Views / assets | `resources/views/garmin/{create,review,summary}.blade.php`, "Garmin import" block in `resources/css/app.css`, `data-resolve-all` in `resources/js/app.js` | |
| Tests | `tests/Feature/Garmin/*`, `tests/Unit/Support/Garmin/StartTimeMatcherTest.php`, `tests/Feature/Strava/ReconcileStravaWindowTest.php` | |
| Fixtures | `tests/Fixtures/Garmin/activities-it.csv` (client sample, verbatim), `activities-en.csv` | |

## Architectural choices

| Choice | Alternatives considered | Why this one |
| --- | --- | --- |
| `SplFileObject` CSV reader | `league/csv`, `maatwebsite/excel` | One flat file of ~100 rows; PHP's reader covers it and the project adds no dependency |
| Parse inside the request | Queue a job | A whole export parses in milliseconds, and the owner is waiting on the review page anyway |
| Locate columns by header name, per language | Fixed column positions | Garmin translates headers and changes column sets between exports; positions would silently read the wrong column |
| List unknown types by name in the summary (decision `56c5683061907ab8`) | Drop them silently; reject the file | Only `Corsa` is confirmed by a real export. A missed running variant must be visible to the owner so it becomes a one-line addition |
| "Already imported" = same start, distance and moving time on a `garmin_csv` row | A hash of the CSV row as its identity | Garmin exports carry no activity id, and a re-export may add, drop or reorder columns, changing the hash of an unchanged run |
| Identity pass before the conflict pass | One pass in file order | A row a minute away could claim the stored run first, and the row identical to it — a re-import — would be inserted a second time |
| Claim each stored row for one CSV row, closest pairs first | Let every CSV row match freely; claim in file order | Free matching lets two CSV runs overwrite one stored run; file order lets an earlier row 90 s away take a stored run a later row matches to the second, so the later row is planned as new and a Skip leaves that run on the map twice |
| Instance-wide lock around confirm, target resolved inside it | Rely on the token and fingerprint alone; a per-athlete lock | A double-clicked confirm sends two requests carrying the same session; serialised, the second re-plans after the first committed, finds its runs already imported, fails the fingerprint and writes nothing. A per-athlete key cannot exist before a guest's first import creates the athlete, and a single-athlete instance loses nothing by serialising |
| Import open to signed-out visitors (US-011) | Keep it owner-only behind `auth` | The owner's choice, recorded in the decision journal and PRD Security as an accepted risk: the heatmap must work with CSV imports only, with no Strava sign-in |
| Conflicts with **either** source | Only with Strava rows | A second, slightly different Garmin export of the same run must be reviewed, not duplicated |
| Overwrite keeps `strava_activity_id` | Clear it | Without the id the sync no longer recognises the twin and would re-add Strava's copy the next night |
| A missing answer is Skip | Reject the confirm | Skip is the pre-checked, non-destructive default; the Form Request already requires an answer |
| Pending rows in the owner's session | A `pending_imports` table | A table would persist owner data beyond the request, need cleanup of abandoned reviews, and expose an id a request could name |
| Re-plan at confirm and compare a fingerprint | Trust the plan rendered in the review | A sync or another tab can change stored runs between review and confirm; the owner's answers must apply to the runs they saw |
| Form Requests for upload and confirm | Inline `$request->validate()` | First user-submitted forms in the app; `.ai/rules/http.md` was amended for them |
| Reject the whole file only for file-level problems | Reject on the first bad row | One corrupt row must not cost the owner the other thirty-nine |
| One resolver for the import target, used by review and confirm | Each controller reading `$request->user()` | A guest has no user; two copies of "owner, else displayed athlete" could drift and plan against one athlete while writing to another |
| CSV-only athlete with placeholder `name` / `email` | A migration making `users.name` / `users.email` nullable | No migration for one row; the fixed `.invalid` address plus the existing UNIQUE index makes a second CSV-only athlete impossible |
| Create the CSV-only athlete inside the import transaction, only with rows | Create it on upload or on review | Upload and review write nothing; a failed insert must not leave an empty athlete, and an empty one would never be displayed |
| Fingerprint carries the target id | Compare the conflicts only | A plan with no conflicts hashes the same for every athlete, so a target change between review and confirm would go unnoticed |
| Masked stored side for guests (start + "Clashes with a stored run") | Accept the disclosure (rejected by the owner, decision journal); hide clashes from guests entirely | Hiding clashes would stop the visitor making an informed Overwrite / Skip choice; the start time is enough to recognise the run |
| Wall-clock dates built in UTC | Parse with the app timezone | Same contract as Strava's `start_date_local`; a conversion moves late-evening runs to the wrong day |

## Key decisions & invariants

- **Invariant — the parser writes nothing.** Planning and the owner's review
  come first. _Breaks if:_ a write is added here — abandoning the review would
  no longer leave the database untouched.
- **Invariant — one definition of "the same run".** Conflicts use
  `StartTimeMatcher`, the class the sync uses to link late twins: same local
  date, ±2 minutes **inclusive**, closest wins. _Breaks if:_ either side
  inlines its own rule — a run the importer called "stored" could be one the
  sync calls "new", and the day is counted twice.
- **Invariant — Garmin wins permanently.** An overwritten or imported row is
  `garmin_csv`, and the sync never reverts it, never re-adds its Strava twin and
  never deletes it — including a night Strava still returns the twin. A Strava
  activity that turns up later within ±2 minutes of a run imported as new is
  **linked** to it (its Strava id is stored, Garmin's values stay; decision
  `7baf70c6656a936f`). See `activity-sync.md`.
- **Invariant — all or nothing.** Every write of one confirm is in one
  transaction. _Breaks if:_ rows are created outside it — a failure halfway
  would leave half an import that a re-upload then reports as part new, part
  already imported.
- **Invariant — at most one CSV-only athlete**, created only together with at
  least one run. _Breaks if:_ its email changes per import, or it is created
  outside the transaction.
- **Invariant — the fingerprint carries the target.** _Breaks if:_ the athlete
  id is dropped from it — a confirm could write to an athlete the review never
  planned against.
- **Invariant — re-importing the same file changes nothing.** Every row is
  already imported, so nothing is created or asked about.
- **Invariant — the session is the only binding.** _Breaks if:_ a route or
  form field starts naming an import or an athlete — a request could then
  reach another visitor's pending rows or write to a chosen athlete.
- **Invariant — a guest never sees a stored run's details.** The review shows
  a guest only the start of a clashing stored run and "Clashes with a stored
  run" — no name, distance, moving time, Strava link or source label. Reason:
  crafted rows within ±2 minutes would otherwise enumerate the displayed
  athlete's names, distances, moving times and Strava links, private activities
  included, without confirming anything. _Breaks if:_ the view decides from
  anything but `showsStoredRunDetails`, or a new stored field is added outside
  the owner branch.
- **Accepted risk — anyone can import.** Any visitor can add or overwrite runs
  on the displayed athlete's map. Bounded by CSRF, 5 MB, the per-IP throttle,
  and the review step; re-decide before deploying to a public URL that others
  know (PRD Security).
- **Invariant — the raw file is never persisted.** _Breaks if:_ the upload is
  `store()`d — an export carries heart rate, cadence and locations.
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
- **Known gap — the window is fixed at upload.** A review confirmed after the
  week rolls over still imports rows that were in the window when parsed.
  They are older runs, and the heatmap simply does not show them.
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

- Specs: `US-008`, `US-011`
- Plans: `.larapilot/plans/US-008-plan.yaml`, `.larapilot/plans/US-011-plan.yaml`
- Mockups: `.larapilot/mockups/US-008/`
- Decisions: `7baf70c6656a936f` (late Strava twin links to the Garmin row), `56c5683061907ab8` (running type names; unknowns surfaced)
- Other domains: `activity-sync.md`, `heatmap-dashboard.md`
