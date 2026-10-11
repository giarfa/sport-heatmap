# Mockup — Garmin CSV import (US-008)

Visual contract for the owner-only Garmin Connect CSV import. Static HTML, no application code.
It extends `../heatmap-dashboard/` — **same tokens, same card surface, same warn palette, no new design system**.

| File | Screen | Blade view |
| --- | --- | --- |
| `upload.html` | Upload form, plus its rejection state | `resources/views/garmin/create.blade.php` |
| `review.html` | Clashes side by side, Overwrite / Skip per row, shortcuts, new runs, tally | `resources/views/garmin/review.blade.php` |
| `summary.html` | The seven counts, ignored types, unreadable rows | `resources/views/garmin/summary.blade.php` |
| `breakpoints.html` | The screens in iframes at 375 / 768 / 1280 px, light and dark | — |
| `import.css` | Tokens copied from `resources/css/app.css` + the import components to port | `resources/css/app.css` → "Garmin import" |

Append `#dark` to any screen URL to stamp it dark; the header toggle switches live.

## Tokens

Nothing new except one control token, needed for WCAG 2.2 non-text contrast on inputs and radios
(the `--hairline` border is decorative and only ~1.2:1):

| Token | Light | Dark | Use |
| --- | --- | --- | --- |
| `--control-border` | `#94a3b8` (slate-400, 3.0:1 on white) | `#64748b` (slate-500, 3.7:1 on slate-900) | File input, secondary button, segmented radio outline |

Everything else is existing: `--surface-card`, `--hairline`, `--ink`, `--ink-muted`, `--accent` (links + focus only),
`--warn-bg` / `--warn-border` / `--warn-ink` (errors, stale-review notice). The primary button is `--ink` on
`--surface-card` — it inverts with the theme, so it never needs its own dark value. **No** Strava orange and **no**
intensity-ramp colours appear on these screens: the ramp is data, and these screens are not.

## Breakpoints

| Width | Behaviour |
| --- | --- |
| **375 px** (origin) | Single column. The clash table **reflows as stacked cards**: `thead` is visually hidden (still read by screen readers), each `<tr>` is a card, every `<td>` prints its column name from `data-label`. Stored and Garmin sit **side by side at 49 % each**, so the comparison stays a comparison on a phone. Keep sits under them. Tally is 2 columns. No horizontal page scroll |
| **640 px** (`sm`) | The clash and new-run tables become real 4-column tables. Tally goes to 4 columns |
| **768 / 1280 px** | Upload and summary cap at `40rem` (a form does not need the full container); review uses the full `max-w-5xl` container. Nothing else changes — no side rail |

**Navigation:** unchanged — header logo is home, theme toggle, no nav. Upload has a "← Back to heatmap" link
(44 px target); review exits via **Cancel**; summary via **Back to heatmap**.

## Accessibility (WCAG 2.2 AA)

- **Upload:** `<label for>` on the file input; the hint is linked with `aria-describedby`; on rejection the input gets
  `aria-invalid="true"` and the message joins `aria-describedby` (`hint error`). The message sits directly under the input.
- **Review:** each table is a real `<table>` with an `sr-only` `<caption>`, `<th scope="col">` headers and a
  `<th scope="row">` run name (date + title), so every cell is announced with its row and column.
- **Overwrite / Skip:** one `<fieldset>` per clash, `<legend>` names the run ("Paese Corsa, 10 Oct 09:33"). The
  radios are visually hidden but **not** `display:none`, so they keep keyboard focus and arrow-key movement; the
  `<label>` is the 44 × 44 px target. Focus is drawn on the label (`input:focus-visible + label`). **Skip is pre-checked** —
  doing nothing must never overwrite a stored run.
- **Shortcuts:** "Overwrite all" / "Skip all" are `type="button"` with `data-resolve-all="overwrite|skip"`, inside a
  container rendered **`hidden`**. `resources/js/app.js` un-hides it and handles the clicks. Without JS the buttons never
  appear, and the per-row radios still submit a complete answer.
- **Never colour alone:** source is a word ("Strava" / "Garmin import"), not a tint.
- **FR-008:** a stored **Strava-sourced** row carries "View on Strava" (`https://www.strava.com/activities/{id}`);
  a Garmin row — new or a Garmin-won twin — carries no Strava link.
- **Summary:** the status line is `role="status"`; counts are a `<dl>`.
- **Stale review / notices:** the warn card (`--warn-*`) with `role="alert"`.

## Copy (PRD register: terse, factual, no exclamation marks)

- Upload hint: "Garmin Connect → Activities → Export CSV · Italian or English · metric units · max 5 MB"
- Rejections: "Choose a CSV file." · "The file is larger than 5 MB." · "This is not a CSV file." ·
  "This is not a Garmin Connect activities export. Use the Italian or English Activities CSV." ·
  "The file is missing the “{column}” column. Export the Activities list again from Garmin Connect." ·
  "The file has no activities."
- Review lede: "Nothing is saved until you confirm."
- Clash note: "A stored run starts within 2 minutes of a run in the file. Skip keeps the stored one."
- Stale: "Your stored activities changed since this review — check the conflicts again."
- Cancel: "Import cancelled. Nothing was written."
- Summary: "{n} runs added, {m} overwritten. The heatmap is up to date." / re-import: "Nothing new — every run was already imported."

Formats: distance in km to one decimal; start as `HH:MM:SS` (the run's local time); moving time as `H:MM:SS`;
dates as `Sat 10 Oct 2026`.

## Joe — implementation notes

- Port the "Garmin import components" block of `import.css` into `resources/css/app.css` (plain CSS next to the
  existing component rules, using the same variables). Layout utilities in Blade stay Tailwind 4.
- The stacked-table rule is a `max-width: 639.98px` query so it never overlaps Tailwind's `sm` (640 px).
- One JS addition only (the `data-resolve-all` handler, delegated on `document` like the theme toggle). No framework.
