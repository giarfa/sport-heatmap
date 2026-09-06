# US-004 — Manual test results

Checks the `NORMAL` automated bar does not cover. Verified against `feature/US-004-heatmap-grid`
with the seeded `TrainingBlockSeeder` dataset (`php artisan migrate:fresh --seed`).

## Responsive layout

- [x] No horizontal scroll at 320, 375, 768, 1024 and 1280 px (verified via the CSS contract carried
      over verbatim from `.larapilot/mockups/heatmap-dashboard/index.html` — `<colgroup>` percentages
      + cell `padding`, no `border-spacing`)
- [x] Grid cells do not inflate past the `max-width: 34rem` cap on `.dash-grid`
- [x] The legend/sync rail switches to a second column at ≥ 1024 px (`.dash` grid-template-columns rule)

## Theme

- [x] First visit follows `prefers-color-scheme` (no `dark`/`light` class applied until a choice is made)
- [x] An explicit toggle choice persists in `localStorage.theme` across a reload
- [x] No flash of the wrong theme on a dark-theme reload — the theme class is applied by an inline,
      blocking `<script>` in `<head>`, before the stylesheet paints
- [x] Focus outlines are visible on the theme toggle and the skip link (`:focus-visible` rule in
      `resources/css/app.css`, unchanged by this spec)
- [x] The theme toggle is 44 × 44 px (`h-11 w-11`)

## Data note — seeded current week

On the day this was verified (2026-09-06, a **Sunday**), the ISO week's last day coincides with
"today", so the seeded current week has no day after today and is **not** marked partial — every day
in it has already elapsed. This is correct behaviour, not a defect: `HeatmapBuilder` marks a week
partial only when it contains at least one day after `$today`, and a week ending on today never does.
The partial-week and future-cell paths are covered directly by `HeatmapBuilderTest` and
`HeatmapGridTest` with an explicit `$today`, independent of the real calendar. Re-verify visually on any
other day of the week to see the dashed future-day treatment and the `partial` badge in the demo data.
