# Mockup — Run metrics in the day cell detail (US-012)

Visual contract for FR-011 (day cell detail) with the three new per-run metrics, and for the metric rows of the Garmin
conflict review. Static HTML, no application code. It extends `../heatmap-dashboard/` and `../US-008/`:
**same tokens, same card, same grid, same ramp, no new design system and no new colour token.**

**Traces to:** US-012 · FR-011 · FR-018 · FR-003 · FR-008 · FR-009 · FR-017

| File | Screen | Blade view / target |
| --- | --- | --- |
| `day-detail.html` | **Primary reference.** The grid (last 5 of 16 rows) with ran days as buttons, and the day detail dialog. Light, live theme toggle | `components/heatmap-grid.blade.php`, a new `components/day-detail.blade.php` |
| `dark.html` | Same screen stamped dark | — |
| `review-metrics.html` | The US-008 review's Clashes card with Avg pace / Ascent / Heart rate on both sides | `resources/views/garmin/review.blade.php` |
| `breakpoints.html` | Both screens in frames at 320 / 375 / 768 / 1280 px, light and dark, plus a run with no metrics | — |
| `detail.css` | `@import`s `../US-008/import.css` (tokens, shell, card, comparison table); adds the ramp, the grid, the **new components to port** | `resources/css/app.css` → "Day cell detail" |
| `favicon.svg` | Copy of the shipped favicon, so the mockup renders standalone | — |

URL hash drives the stamped states: `day-detail.html#open=2026-10-03` opens that day, `#dark` stamps dark, `#dark&open=2026-10-10`
both. `review-metrics.html#dark` stamps dark. The header toggle switches live.

---

## Fidelity contract — what the implementation must keep

1. **The grid does not move.** Column widths, `max-width: 34rem`, cell size, ramp, the dashed future cell and the `PARTIAL` tag are exactly
   `../heatmap-dashboard/README.md`. A ran day gets a `<button class="hm-hit">` around the existing `.hm-cell`; nothing else changes.
2. **Only ran days are controls.** Rest days and future days stay plain `<td>`s with the same `sr-only` name as today. A day with at least one stored
   run in the window is a button. (Days outside the 16-week window are not drawn, so there is nothing to open.)
3. **One dialog, one template per ran day.** The page carries a single `<dialog>` and one inert `<template id="day-YYYY-MM-DD">` per ran day, so the
   detail is part of the cached grid (it invalidates with it) and opening a day makes no request (FR-017). The markup contract below holds whether
   Joe fills the dialog from `<template>`s, as the mockup does, or from per-cell JSON.
4. **The copy of the mockup is the copy of the product** (terse, factual, no exclamation marks): title `Sat 3 Oct 2026`; sub-line `2 runs · 13.6 km`
   (`1 run · 8.2 km`); labels `Distance`, `Moving time`, `Avg pace`, `Ascent`, `Heart rate`; values `10.4 km`, `0:55:59`, `5:23 /km`, `45 m`,
   `152 bpm`; a missing metric `—`; source line `Strava · View on Strava` or `Garmin import`; close control `Close`.
5. **Units are always metric**: km to one decimal, `/km`, metres, bpm. No imperial unit anywhere.

### The dialog, element by element

| Element | Rule |
| --- | --- |
| **Open** | Click, tap, Enter, Space on a ran day's button → `dialog.showModal()`. The browser traps focus, makes the page inert, closes on Esc |
| **Close** | The 44 × 44 px close button (top right), Esc, or a click on the backdrop. Focus returns to the cell that opened it (the browser does this for `showModal()`; the script sets it explicitly as a belt) |
| **Open cell** | While open the cell keeps a 2 px `--ink` ring (`.is-open`), so the backdrop still shows which day it is |
| **Heading** | `h2` with the long date, `aria-labelledby` on the dialog. Sub-line: run count and the day total in km |
| **Run** | One `<li>` per run, in start order: `h3` name; source line; a `<dl>` of five stats in a 3-column grid (Distance, Moving time, Avg pace / Ascent, Heart rate) |
| **Missing metric** | `—` in `--ink-muted`, with an `sr-only` "not recorded" so a screen reader says it instead of "em dash". Never `0` |
| **Strava link** | A Strava-sourced run: `Strava · View on Strava` → `https://www.strava.com/activities/{id}`, a 44 px target, with the run name in an `sr-only` suffix so the link name is unique and still starts with its visible text (SC 2.5.3). A Garmin-imported run: `Garmin import`, no link (FR-008). Link styling is the accent link of US-008's review; Strava orange stays on the connect button only |
| **Scrolling** | The header is fixed; the run list scrolls inside the dialog (`overscroll-behavior: contain`). The page never scrolls horizontally |

### Responsive & navigation

| Width | Behaviour |
| --- | --- |
| **320 px** | Smallest supported. Bottom sheet, 3-column stats still fit (Distance / Moving time / Avg pace), no horizontal scroll. Cells ≈ 26 px target |
| **375 px** (origin) | Bottom sheet: full width, `max-height: 85dvh`, rounded top corners, a decorative grip bar, slides up 180 ms |
| **640 px** (`sm`) and up | Centred dialog, `min(30rem, 100% − 2rem)`, `max-height: min(80dvh, 40rem)`, fades in. Page behind unchanged |
| **768 / 1280 px** | Same dialog; the grid keeps its 34 rem cap, so the dialog sits over the grid column with the rail dimmed |

**Navigation:** none added. The dialog is a layer on the single screen, not a page; there is no history entry and no URL (Back leaves the dashboard as today).
**Orientation:** a landscape phone gets the `sm` treatment and the sheet scrolls inside `85dvh`.
**No JavaScript:** the cells render as buttons that do nothing, the same fall-back as the theme toggle; the `sr-only` text of each cell still reads
day, distance and run count. The app has no framework, so this is the accepted degradation (Joe), not a gap to fill with a `<noscript>` page.

### The review's metric rows

`review-metrics.html` changes the US-008 Clashes card in one way: three rows (`Avg pace`, `Ascent`, `Heart rate`) under `Moving`, on **both** the Stored side and the
Garmin-file side, in the same `.side` list. `.side dt` widens from 3.25 rem to 4.5 rem for the new label. At 375 px the two sides still sit at 49 % each. A value
the stored run or the file does not have reads `—`. The section note gains one sentence: *Overwrite replaces the run and its pace, ascent and heart rate; a “—” in the file keeps the stored value.*
The New runs table, the tally, the shortcuts and the buttons are untouched. A signed-out visitor’s Stored side keeps showing the start only: no metrics (same
rule as name, distance, moving time and the Strava link; **assumption to confirm in TASK-06**, see Deviations).

---

## Accessibility (WCAG 2.2 AA)

- **Target size (SC 2.5.8).** The cell button fills the whole `<td>` and the 2–3 px gap moves inside it: **25.8 px at 320 px, 31 px at 375 px**, ≥ 24 px at every width. The visible square is unchanged. Close and Strava link are 44 px.
- **Names.** Each button reads `Saturday 3 Oct 2026: 13.6 km, 2 runs. Show details`; `aria-haspopup="dialog"`. The dialog is named by its heading. Stats are a `<dl>`, so every value is announced with its label.
- **Focus.** `:focus-visible` outline 2 px `--accent`, offset 0, on the cell button; the same ring on the close button and the link. Hover adds a 2 px `--ink-muted` ring and never changes the cell size.
- **Contrast.** Text `--ink` on `--surface-card` ≥ 17:1 in both themes; labels and `—` `--ink-muted` 4.76:1 on `#ffffff`, 6.96:1 on `#0f172a`; the close icon is `--ink-muted`, ≥ 3:1 as non-text. The backdrop is `rgb(15 23 42 / .55)` light and `rgb(0 0 0 / .65)` dark, so the dialog edge stays distinct from the dimmed page. Ramp cells keep the validated 2.11:1 / 2.20:1 against their surface.
- **Never colour alone.** The ran/rest distinction is the ramp plus the accessible name; the open day is ringed with `--ink`, not a hue.
- **Motion.** The slide/fade runs only under `prefers-reduced-motion: no-preference`.
- **Keyboard.** Tab reaches every ran day in reading order; Enter/Space opens; Tab stays inside the dialog; Esc closes and focus lands back on the cell.
- **Light and dark.** Both drawn, tokens only.

## Joe — implementation notes

- Port the "NEW" blocks of `detail.css` (`.hm-td--ran`, `.hm-hit`, `.day-dlg`, `.dlg-*`, `.runs`, `.run*`, the `.side` tweaks) into `resources/css/app.css` next to the grid rules, with the same variables. Layout utilities in Blade stay Tailwind 4.
- The bottom-sheet rule is `max-width: 639.98px`, so it never overlaps Tailwind's `sm` (640 px).
- One JS addition (delegated click on `[data-day]`, `showModal()`, close on `[data-dlg-close]` and the backdrop, focus return), written like the existing theme-toggle and `data-resolve-all` handlers in `resources/js/app.js`. No new dependency.
- Mockup sample data is a 5-week excerpt (Mon 14 Sep – Sun 18 Oct 2026, "today" Tue 13 Oct 2026): 19 ran days, 3 of them hand-picked to show a two-run day with a gap in metrics, a Garmin run with none, and a Strava link.

## Mirrors (targets for the build tasks)

- **TASK-06** — `review-metrics.html` ⇢ `resources/views/garmin/review.blade.php` (Clashes card only)
- **TASK-09** — `day-detail.html` (grid buttons, dialog, templates, script) and `dark.html` ⇢ `components/heatmap-grid.blade.php`, a day-detail component, `resources/css/app.css`, `resources/js/app.js`

## Deviations

| Date | Screen | What | Why | Decision |
| --- | --- | --- | --- | --- |
| — | — | none yet | — | — |

## Approval

Approved by the user on 2026-10-13, as drawn (guest review keeps showing the stored start only). TASK-06 and TASK-09 build from these screens.
