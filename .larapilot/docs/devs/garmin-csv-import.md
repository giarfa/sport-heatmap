# Garmin CSV import

| | |
| --- | --- |
| **Status** | Active |
| **Introduced by** | `US-008` |
| **Last updated by** | `US-008` — 2026-10-11 |
| **Owner persona** | 🔧 Alex |

## Purpose

Lets the owner add runs that Strava is missing, or that Garmin recorded better,
by uploading the "Activities" CSV exported from the Garmin Connect website
(FR-018). The file is parsed into running rows inside the 112-day window, and
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
   - the first header cell is neither `Tipo di attività` (Italian) nor
     `Activity Type` (English) → "This is not a Garmin Connect activities
     export…"
   - a required column of that language is missing → the message names it
   - there is a header but no data row → "The file has no activities."
2. **Per row**, in this order — the first rule that applies decides the row:
   1. **Type** — not a running type → `ignoredByType[name] += 1`.
   2. **Date** — not `Y-m-d H:i:s` / `Y-m-d H:i` (or an overflowing date such
      as `2026-02-30`) → `invalid[] = {line, "unreadable date"}`.
   3. **Window** — after today, or outside the heatmap window →
      `outsideWindow += 1`.
   4. **Distance** — `"--"`, empty or non-numeric → `invalid[] = {line,
      "missing distance"}`.
   5. **Moving time** — `"--"` falls back to the `Tempo` / `Time` column; if
      that is also unreadable → `invalid[] = {line, "missing moving time"}`.
   6. **Title** — trimmed, `Run` when empty, cut to 255 characters.
3. Rows identical **within the file** (same start, distance and moving time)
   collapse into one.

Invalid rows carry their 1-based line number in the file, and the remaining
rows still parse.

### Accepted headers

| Key | Italian | English | Required |
| --- | --- | --- | --- |
| type | `Tipo di attività` | `Activity Type` | yes — and must be the first column |
| date | `Data` | `Date` | yes |
| title | `Titolo` | `Title` | yes |
| distance | `Distanza` | `Distance` | yes |
| moving time | `Tempo in movimento` | `Moving Time` | yes |
| time | `Tempo` | `Time` | no — fallback for a `"--"` moving time |

Columns are located **by name**, never by position. A UTF-8 BOM on the first
cell is stripped.

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

## Technical design

| Piece | Where | Role |
| --- | --- | --- |
| Parser | `app/Support/Garmin/ParseGarminCsv.php` | File → `ParsedGarminCsv`; `SplFileObject`, no package |
| Header sets | `app/Support/Garmin/GarminCsvColumns.php` | Language detection, column lookup by name |
| Type map | `app/Support/Garmin/GarminRunningTypes.php` | Garmin type name → `SportType` |
| Row DTO | `app/Data/Garmin/GarminCsvRow.php` | `toAttributes()` (always `source = garmin_csv`), `toSessionArray()` / `fromSessionArray()`, `isIdenticalTo(Activity)` |
| Parse result | `app/Data/Garmin/ParsedGarminCsv.php` | Rows, ignored by type, outside-window count, invalid list; session round-trip |
| Rejection | `app/Exceptions/Garmin/GarminCsvRejectedException.php` | Owner-facing messages; no file content beyond a column name |
| Fixtures | `tests/Fixtures/Garmin/activities-it.csv` (client sample, verbatim), `activities-en.csv` | |

## Architectural choices

| Choice | Alternatives considered | Why this one |
| --- | --- | --- |
| `SplFileObject` CSV reader | `league/csv`, `maatwebsite/excel` | One flat file of ~100 rows; PHP's reader covers it and the project adds no dependency |
| Parse inside the request | Queue a job | A whole export parses in milliseconds, and the owner is waiting on the review page anyway |
| Locate columns by header name, per language | Fixed column positions | Garmin translates headers and changes column sets between exports; positions would silently read the wrong column |
| List unknown types by name in the summary (decision `56c5683061907ab8`) | Drop them silently; reject the file | Only `Corsa` is confirmed by a real export. A missed running variant must be visible to the owner so it becomes a one-line addition |
| Reject the whole file only for file-level problems | Reject on the first bad row | One corrupt row must not cost the owner the other thirty-nine |
| Wall-clock dates built in UTC | Parse with the app timezone | Same contract as Strava's `start_date_local`; a conversion moves late-evening runs to the wrong day |

## Key decisions & invariants

- **Invariant — the parser writes nothing.** Planning and the owner's review
  come first. _Breaks if:_ a write is added here — abandoning the review would
  no longer leave the database untouched.
- **Invariant — a rejection message never echoes file content** beyond a
  column name. _Breaks if:_ a cell value is interpolated — the upload page
  would render attacker-chosen text.
- **Invariant — `start_date_local` is never timezone-converted.**

## Extension points & gotchas

- **Adding a running type name:** one line in `GarminRunningTypes::TYPES`,
  lower-cased. The summary's "Ignored activity types" list is where a missing
  one shows up.
- **Adding a language:** one more header set in `GarminCsvColumns`; the type
  map needs that language's running names too.
- **Known limitation — metric accounts only.** Garmin exports distance in the
  account's unit setting, and an imperial export cannot be told apart from the
  CSV (pace and distance stay self-consistent in either unit). The PRD is
  metric-only; the upload hint says "metric units".
- Garmin writes thousands separators in **both** languages (`"8,240"` steps in
  the Italian sample), so `,` is never a decimal mark here.

## Related

- Specs: `US-008`
- Plans: `.larapilot/plans/US-008-plan.yaml`
- Mockups: `.larapilot/mockups/US-008/`
- Other domains: `activity-sync.md`, `heatmap-dashboard.md`
