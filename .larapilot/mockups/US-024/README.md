# Mockup — Aerobic efficiency (US-024)

Visual contract for FR-023: a tile and a server-rendered chart showing **pace at the owner's usual heart rate** across the
shown 16-week window, with the 4-week rolling median and the same weeks a year earlier. It sits in the dashboard rail
**right under "Weekly distance"**, so the page reads as a pair: what went in (distance), what came out (efficiency).

Static HTML, no application code. It extends `../US-014/` (the weekly distance chart): **same card, same chart frame,
same tokens, no new colour token.**

**Traces to:** US-024 · FR-023 · FR-020 · FR-019 · FR-017 · FR-009 · FR-003

| File | Screen | Stories |
| --- | --- | --- |
| `index.html` | **Primary reference.** Dashboard on the window 6 Jul – 25 Oct 2026, light: grid (context), legend (context), Weekly distance (unchanged), **Aerobic efficiency**, sync card (context). Mobile-first; two columns from 1024 px. Header toggle switches theme live | US-024 |
| `dark.html` | Same screen stamped dark | US-024 |
| `states.html` | The card alone: not enough runs, empty window, no runs a year earlier, mostly hilly weeks with a steep run | US-024 |
| `breakpoints.html` | `index.html` in frames at 320 / 375 / 768 / 1024 / 1280 px, light and dark | US-024 |
| `efficiency.css` | Part 1 = the compiled `resources/css/app.css` shipped in v3.1.0; **Part 2 = the new components to port**; a final "mockup shell" block that is **not** ported | US-024 |
| `favicon.svg` | Copy of the shipped favicon, so the mockup renders standalone | — |

Append `#dark` to any screen URL to stamp it dark.

**Design system:** [Tailwind CSS](../../design-systems/tailwind/) as shipped, per PRD `## Technical Architecture` (Blade + Tailwind 4,
no panel, no starter kit). The design-system gate was skipped: the PRD and the existing mockups (`heatmap-dashboard/`, `US-014/`)
already settle it, and this card must read as the twin of the Weekly distance card. Every new rule goes into
`resources/css/app.css` after the "Weekly trend" block.

Grid, legend, and sync card are drawn as placeholders: this spec does not change them.

---

## The card, element by element

| Element | Rule |
| --- | --- |
| **Title** | `Aerobic efficiency`, `.trend-title`, an `h3` like "Weekly distance" |
| **Headline** | The latest 4-week median as **`6:29`** (1.5 rem, 600, tabular) + `/km` (muted) + **`at 148 bpm`**, the window's reference heart rate. The one number to read at a glance |
| **Note** | `25 s/km faster than 16 weeks ago (6:54 /km) · 148 bpm is this window's median heart rate`. Change = first line point − latest line point, whole seconds, worded **faster** / **slower**; equal reads `same pace as 16 weeks ago`. No colour, arrow, praise or warning (Marika's register) |
| **Y axis** | Pace, **faster at the top**. Gridlines every 15 s; top = fastest value floored to 15 s, bottom = slowest ceiled to 15 s, over dots, the line and last year's line. Only the top label carries the unit (`6:15 /km`) |
| **X axis** | Identical to Weekly distance: four labels at weeks 1, 5, 9, 13 (the Monday of each Previous / Next step) |
| **Run dots** | One per drawn run, HTML spans positioned in percent over the SVG (circles inside a `preserveAspectRatio="none"` SVG would stretch). x = slot × 10 + 1.5 + weekday × 7⁄6 in the 160-unit plot; y = pace. 7 px |
| — flat run (< 5 m/km) | Filled `--lv-2` |
| — hilly run, adjusted (5–10 m/km) | Ring: `--surface-card` fill, 1.5 px `--lv-2` border |
| — steep run (> 10 m/km) | Muted cross (`--ink-muted`), shown, **never counted** |
| — not drawn | Under 3 km, no pace, no heart rate, or average HR more than 10 bpm above the reference |
| **4-week median** | `.trend-line` + `.trend-line-halo`, exactly as the 4-week average of Weekly distance: `--ink` 2 px over a 5 px card-coloured halo. Breaks where a span has fewer than 3 usable runs; never interpolated. A lone point is a 4-unit tick |
| **Same weeks last year** | `.eff-lastyear`: `--ink-muted`, 1.5 px, dash 4 3, under the median line. Same reference HR as the window. Breaks where it has no point — in the sample, across the hilly summer of 2025, which the 10 m/km cap leaves out |
| **Key** | Run · Hilly run, adjusted (when present) · Steep run, not counted (when present) · 4-week median · Same weeks last year (when present). Shapes, not only colours |
| **Weekly figures** | Native `<details>`, collapsed: Week / Runs / 4-wk median / Last year (column absent with no last-year data). Missing value `—` read as "none". This is the text alternative |
| **Not enough runs** | Fewer than 8 qualifying runs: title + `Not enough runs to measure efficiency: 6 in this window, 8 needed. A run counts when it is at least 3 km with pace and heart rate recorded.` No headline, no chart |
| **Empty window** | `No runs in this window, so there is nothing to chart.` — the Weekly distance sentence |
| **First sync in flight** | As Weekly distance: skeleton, `aria-hidden` (not drawn; same rule as US-014) |

### Calculation (FR-023, for reference — the spec is the source of truth)

Qualifying run: ≥ 3 km, pace and HR present, climb ≤ 10 m/km. Reference HR = median HR of the window's qualifying runs,
rounded. Efficiency = speed ÷ HR; 5–10 m/km runs use distance + 8 × ascent. Pace at reference HR = 1 km ÷ (efficiency × reference HR).
Line point = median of the week and the 3 before, flat runs only unless they are fewer than 4, then adjusted hilly runs join; < 3 runs → no point.

**Why the cap is 10 m/km, not 15** (changed during this design pass, decision journal): drawing last year's line from the
real data showed the summer 2025 block (runs at 11–15 m/km) reading ~30 s/km *faster* than the year's flat runs. Across the
whole history, 8 m per m of climb lands within 0.3 % on 5–10 m/km runs and over-corrects 10–15 m/km runs by ~8 %.

### Geometry

```
<svg viewBox="0 0 160 100" preserveAspectRatio="none">   same frame as Weekly distance
slot i        : x = i*10 .. i*10+10   (16 slots)
line point    : x = i*10 + 5
run dot (HTML): left = (i*10 + 1.5 + weekday*7/6) / 160 * 100 %,  top = y %
y             : (pace − top) / (bottom − top) * 100     (top = fastest)
```

Every stroke keeps `vector-effect: non-scaling-stroke` (already on `.trend-plot svg *`).

---

## Responsive & navigation

| Width | Behaviour |
| --- | --- |
| 320 / 375 | Rail stacks under the grid; the card is full width under Weekly distance. Headline wraps `at 148 bpm` to the next line only if needed (flex-wrap). Plot 9 rem tall, as Weekly distance. No horizontal scroll |
| 768 | Same stack, wider plot; dots stay 7 px (they never scale) |
| 1024+ | Two columns: grid 34 rem, rail on the right; plot 10 rem tall, as Weekly distance |
| 1280 / 1920 | Unchanged from 1024: the `max-w-5xl` container caps the width |

Navigation is unchanged: Previous / Next (US-013) above the grid move this card with the window. Portrait and landscape
reflow identically. The only control in the card is `<summary>` (44 px row).

## Accessibility (WCAG 2.2 AA)

- **Structure:** `<section aria-labelledby>` with an `h3`, as the other rail cards.
- **SVG:** `role="img"`, named by its `<title>`: "Pace at 148 bpm per run, 6 Jul – 25 Oct 2026, faster at the top; 4-week median 6:54 to 6:29 per km. Weekly figures below." Dots, gridlines and lines are `aria-hidden`; the numbers live in the table.
- **Text alternative:** the "Weekly figures" table: `sr-only` caption, `th scope="col"`, `th scope="row"` week ranges, `—` + `sr-only` "none".
- **Never colour alone:** flat / hilly / steep = filled / ring / cross; median / last year = solid / dashed; the key draws the shapes.
- **Contrast:** `--lv-2` dots and rings clear 3:1 on both cards (same token and measurements as the US-014 bars); `--ink` line as US-014; last year's `--ink-muted` dashed line is 4.76:1 light / 6.96:1 dark.
- **Focus:** the layout's `:focus-visible` outline on `summary`. **Motion:** none. **Live regions:** none (full page loads only).

## Brand assets (Elise)

Nothing new; `favicon.svg` is the shipped one.

## Emma: SEO

Unchanged: private dashboard, `noindex, nofollow`. Performance cost: one inline SVG of ~25 elements and ~50 positioned spans, no script, no font.

## Lauren: social

Nothing to distribute.

## Violet: regulatory

Private single-owner instance (FR-017, FR-021): EAA / EN 301 549 not triggered, no accessibility statement. The card shows a
value derived from heart rate, which only the signed-in owner sees — the same disclosure as the day cell detail (FR-011).

---

## Fidelity contract

Alex mirrors, from `index.html`, `dark.html` and `states.html`:

1. Card position (rail, directly under Weekly distance), title, headline layout, note wording and its faster / slower / same rule.
2. The chart frame shared with Weekly distance: plot height, y/x label placement, gridlines, baseline, x labels.
3. Dot shapes and colours per run kind, the median line style, last year's dashed line, line breaks without interpolation.
4. The key entries and their conditional presence; the "Weekly figures" table columns.
5. The not-enough-runs and empty-window copy, word for word.
6. Light and dark from the existing tokens only — Part 2 of `efficiency.css` is the CSS to port.

Not part of the contract: the placeholder grid, legend and sync cards, and the "mockup shell" CSS block.

## Deviations

| Date | Screen | What | Why | Decision |
| --- | --- | --- | --- | --- |

## Revisions

| Date | Screen | What changed | Why |
| --- | --- | --- | --- |
| 2026-11-14 | all | First draft from the owner's real runs (window 6 Jul – 25 Oct 2026, reference 148 bpm, 6:54 → 6:29 /km) | — |
| 2026-11-14 | all | Hill adjustment capped at 10 m/km (was 15); runs above it drawn as steep crosses. Last year's line now breaks over summer 2025 | The 8 m factor over-corrected 10–15 m/km runs by ~8 %, making last summer read ~30 s/km faster |
| 2026-11-14 | all | Note shortened to "148 bpm is this window's median heart rate" | Fits two lines at 320 px |
