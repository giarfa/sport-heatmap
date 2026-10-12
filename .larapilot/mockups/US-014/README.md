# Mockup — Weekly mileage trend beside the heatmap (US-014)

Visual contract for FR-020: a server-rendered SVG chart of weekly totals with a 4-week rolling average,
in the dashboard rail. Static HTML, no application code. It extends `../heatmap-dashboard/` and `../US-008/`:
**same tokens, same cards, same grid, no new design system and no new colour token.**

**Traces to:** US-014 · FR-020 · FR-005 · FR-009 · FR-017 · FR-019

| File | Screen | Stories |
| --- | --- | --- |
| `index.html` | **Primary reference.** Dashboard on the current window (18 May – 6 Sep 2026): grid, legend, **trend card**, sync card. Mobile-first, light, live theme toggle | US-014 |
| `dark.html` | Same screen stamped dark | US-014 |
| `past-window.html` | One Previous step back (20 Apr – 9 Aug 2026): no partial bar, the line spans all 16 weeks, a 0 km week (4 May) | US-014, US-013 (context) |
| `states.html` | The trend card alone: short history, figures expanded, empty window, first sync in flight, connect screen | US-014 |
| `breakpoints.html` | `index.html` in frames at 320 / 375 / 768 / 1024 / 1280 px, light and dark | US-014 |
| `trend.css` | Part 1 = copy of `resources/css/app.css` as shipped; **Part 2 = the new components to port** | US-014 |
| `favicon.svg` | Copy of the shipped favicon, so the mockup renders standalone | — |

Append `#dark` to any screen URL to stamp it dark. The header toggle switches live.

**Design system:** [Tailwind CSS](../../design-systems/tailwind/), per PRD `## Technical Architecture` (Blade + Tailwind 4,
no panel, no starter kit). The design-system gate was skipped: the PRD and the two earlier mockups already settle it.
Tailwind loads from the CDN in the mockup only; in the app every new rule goes into `resources/css/app.css` next to the grid.

---

## The chart, element by element

| Element | Rule |
| --- | --- |
| **Bars** | One per week of the shown window, oldest left, current week right. Height = the FR-005 week total of the same row, **the same number the grid prints** (the mockup generator asserts this). Fill `--lv-2` |
| **Rolling average** | A polyline through the slot centres: mean of that week and the 3 before it, **including stored weeks before the window** (the 18 May point uses 27 Apr – 11 May). Stroke `--ink`, 2 px, over a 5 px `--surface-card` halo so it separates from the bars it crosses |
| **Line gaps** | No point for the partial week, so on the current window the line **stops at the last completed week**. No point for a week with fewer than 3 weeks of history since the week of the oldest stored run (`states.html`, short history: first point 6 Jul). Weeks before that are never averaged as 0. A lone point draws as a 4-unit horizontal tick, because a one-point polyline draws nothing |
| **Partial week** | Washed fill (30 % `--lv-2`), **dashed** `--lv-2` outline, and an uppercase `PARTIAL` tag above the bar, the same word and styling as the grid's `.hm-partial`. Three cues, none of them hue. Its accessible name says "partial week" |
| **0 km week** | A 3 px `--lv-2` stub on the baseline, never a gap (`past-window.html`, 4 May; `states.html`, short history) |
| **Y axis** | Starts at 0. Gridlines at a "nice" step: the smallest of 5, 10, 20, 25, 30, 40, 50, 75, 100, 150, 200 km that is ≥ max ÷ 3. Top = step × ⌈max ÷ step⌉, where *max* covers both the bars and the line points (a line point can come from weeks before the window and outrun every bar). Every gridline is labelled; only the top one carries the unit (`90 km`). Current window: step 30, top 90. Past window: step 25, top 75 |
| **X axis** | Four labels, at weeks 1, 5, 9, 13: the Monday of each 4-week block, which is also the Previous / Next step. Each label starts flush with its bar |
| **Key** | Weekly total · 4-week average · Partial week (only when present) · 0 km week (only when present). The key draws shapes (bar, line, dashed box, stub), not only colours |
| **Note** | `4-week average 67.7 km · week of 24 Aug`: the last drawn point, in words. With no point yet: "The 4-week average starts once 4 weeks of runs are stored." Terse and factual, per Marika: no "building!", no arrows, no praise |
| **Weekly figures** | A native `<details>`, collapsed, under the key: a table with Week / Total / 4-wk avg, one row per bar, partial week tagged, missing average shown as `—` (read as "none"). This is the text alternative, visible to everyone |
| **Empty window** | Card keeps its heading. Body: "No runs in this window, so there is nothing to chart." No axis, no bars |
| **First sync in flight** | When the grid shows its skeleton, the trend card shows 16 skeleton bars, `aria-hidden`. The grid skeleton already announces the load |
| **Connect screen** | No athlete means no dashboard and **no trend card** |

### Geometry: why the SVG looks the way it does

```
<svg viewBox="0 0 160 100" preserveAspectRatio="none">
slot i      : x = i*10 .. i*10+10       (16 slots)
bar         : x = i*10 + 2, width 6
line point  : x = i*10 + 5
y(km)       : 100 - km / top * 100
```

- `preserveAspectRatio="none"` lets one SVG fill the rail at 1280 px and a phone at 320 px with **no resize script**.
- So **every stroke has `vector-effect: non-scaling-stroke`**, or lines smear on a wide rail.
- So **no text and no circles inside the SVG**: both would stretch. The y labels, x labels, and `PARTIAL` tag are HTML,
  positioned in % (`bottom: {km/top*100}%`). That is the same coordinate system the SVG uses, so they line up at any width.
- Plot height is fixed: `9rem` below `lg`, `10rem` from `lg`.
- The PHP class that builds the chart (e.g. `App\Data\Heatmap\WeeklyTrend` built beside `HeatmapBuilder`) returns
  numbers and strings only. The Blade component does no arithmetic, matching `heatmap-grid.blade.php` and
  `heatmap-legend.blade.php` ("Markup only").

### Data contract (from the spec, restated for the build)

- The 4-week average needs the **3 weeks before** the window. Fetch the window's daily rows plus those 21 days, and
  the athlete's oldest run date, in **one query** (AC: at most one extra query on the dashboard request).
- The chart belongs to the cached window payload, so it is invalidated with the grid on sync / confirmed import
  (`HeatmapGridCache`). No separate chart cache that could outlive a write.
- Rendering writes nothing and calls no Strava endpoint (FR-017). A signed-out visitor sees the same card.

---

## Tokens

No new tokens. Contrast was checked against the real card surfaces:

| Use | Token | Light | Dark |
| --- | --- | --- | --- |
| Bar fill / partial outline / 0 km stub | `--lv-2` | `#3987e5`: **3.64:1** on `#ffffff` | `#2a78d6`: **4.04:1** on `#0f172a` |
| Average line | `--ink` | `#0f172a`: 17.9:1 on card, **4.91:1** over a bar | `#f8fafc`: **4.22:1** over a bar |
| Line halo | `--surface-card` | `#ffffff` | `#0f172a` |
| Gridlines | `--hairline` | decorative, unlabelled by itself | decorative |
| Baseline, axis text, tag | `--ink-muted` | 4.76:1 | 6.96:1 |
| "Weekly figures" summary | `--accent` | 7.90:1 | 8.96:1 |

Every data mark clears the WCAG 2.2 non-text 3:1 bar in both themes. **Why `--lv-2` and not a new chart colour:** the bars
are the same quantity as the grid (running distance), so they share its hue. `--lv-2` is the step that clears 3:1 in
*both* themes. `--lv-1` is 2.1–2.2:1 and only passes inside the ramp, next to its neighbours. Strava orange stays off
data, as in every earlier mockup.

---

## Responsive & navigation contract

Design origin is 375 px. The chart is **the third rail card**: legend → **trend** → sync.

| Width | Behaviour |
| --- | --- |
| **320 px** | Smallest supported. Single column: grid, legend, trend, sync. Plot ≈ 226 px wide, slots ≈ 14 px, bars ≈ 8.5 px. All four x labels fit. No horizontal page scroll (checked in `breakpoints.html`) |
| **375 px** | Design origin. Plot ≈ 281 px, bars ≈ 10.5 px |
| **768 px** | Grid capped at 34 rem and centred (unchanged). Rail cards, the chart included, run the full container width under it. Bars widen and the plot height stays 9 rem |
| **1024 px** (`lg`) | **Two columns**: grid left (34 rem), rail right. The trend card sits **beside the grid**, between legend and sync. Plot ≈ 340 px wide, 10 rem tall |
| **1280 / 1920 px** | Container caps at `max-w-5xl`; nothing changes further |

**Orientation:** a landscape phone gets the ≥ 640 px treatment. The plot height is in rem, not vh, so rotation cannot
squash it.

**Navigation:** unchanged, no nav chrome. The Previous / Next row above the grid in these screens is **US-013's**.
It is drawn here only so the chart shows the window it follows (US-013 was planned without a mockup), so it is not a
design contract. The trend card has no controls of its own: it re-renders with the window and needs no client state.

---

## Accessibility (WCAG 2.2 AA): what Alex must preserve in Blade

- **Structure:** the card is a `<section aria-labelledby>` with an `h3` ("Weekly distance"), like the legend card.
- **SVG:** `role="group"`, named by its `<title>` ("Weekly running distance, 18 May – 6 Sep 2026, oldest week first").
  Each bar is a `<g role="img">` whose `<title>` is its accessible name, which is also the pointer tooltip:
  - `18 – 24 May 2026: 38.8 km; 4-week average 19.9 km`
  - `31 Aug – 6 Sep 2026: 44.1 km so far, partial week`
  - `4 – 10 May 2026: 0.0 km; 4-week average 22.9 km`
  - `15 – 21 Jun 2026: 55.2 km; no 4-week average yet`

  Gridlines, baseline, and the line are `aria-hidden`: the averages are in the bar names and in the table.
- **Text alternative:** the `<details>` "Weekly figures" table has an `sr-only` `<caption>`, `<th scope="col">` headers,
  and `<th scope="row">` week ranges. It is visible, so it also serves sighted users who want the exact numbers.
  Use `<summary>`, not a button: no script, and the keyboard support comes free.
- **Never colour alone:** partial = dashed outline + washed fill + `PARTIAL` tag + "partial week" in the name. Average =
  line shape vs bar shape. 0 km = a stub, labelled in the key.
- **Touch:** the only control is the `<summary>`, with a 44 px minimum height. Bars are not interactive (no tap-through:
  the per-week detail is the table).
- **Focus:** the `:focus-visible` outline from the layout applies to `summary`. Do not remove it.
- **Motion:** none, except the skeleton pulse, which keeps the existing `prefers-reduced-motion: no-preference` rule.
- **Live regions:** none needed. The chart only changes on a full page load (window navigation is a plain link).

---

## Sample data

`index.html` reuses the 68-day dataset of `../heatmap-dashboard/` unchanged, so its legend is still **7.2 / 9.9 / 14.1 km**
(R-7, see `.ai/rules/heatmap.md`). Before it there are 7 weeks of Garmin-imported history (oldest run in the week of
30 Mar). They feed the average and give `past-window.html` its rows: 28.4, 31.0, 33.5, 36.2, 22.0, **0.0** (4 May, calf strain), 18.6 km.
"Today" is Saturday 5 Sep 2026, as before.

| Week | Total | 4-wk avg |
| --- | --- | --- |
| 18 – 24 May | 38.8 | 19.9 |
| 25 – 31 May | 40.8 | 24.5 |
| 1 – 7 Jun | 50.1 | 37.1 |
| 8 – 14 Jun | 27.5 | 39.3 |
| 15 – 21 Jun | 55.2 | 43.4 |
| 22 – 28 Jun | 61.7 | 48.6 |
| 29 Jun – 5 Jul | 63.9 | 52.1 |
| 6 – 12 Jul | 38.8 | 54.9 |
| 13 – 19 Jul | 5.0 | 42.4 |
| 20 – 26 Jul | 24.5 | 33.0 |
| 27 Jul – 2 Aug | 56.9 | 31.3 |
| 3 – 9 Aug | 69.8 | 39.0 |
| 10 – 16 Aug | 70.5 | 55.4 |
| 17 – 23 Aug | 86.8 | 71.0 |
| 24 – 30 Aug | 43.5 | 67.7 |
| 31 Aug – 6 Sep | 44.1 (partial) | — |

Use these numbers as a ready-made fixture: the same daily rows give the same bars, line, axis (top 90 km), and legend.
The past window recomputes its legend from its own days (FR-006): **6.9 / 8.8 / 12.4 km** over 59 running days.

---

## Brand assets (Elise)

Nothing new. The PRD commissions no brand assets for this release. `favicon.svg` is a copy of the shipped one, and the
header mark is inlined as in `layouts/app.blade.php`.

## Emma: SEO

Unchanged: `noindex, nofollow`, no sitemap, no `robots.txt` allowances, no `llms.txt`, no JSON-LD. Lighthouse
Accessibility ≥ 90 still applies. Performance cost: one inline SVG of ~20 elements, no script, no font.

## Lauren: social

Nothing to distribute. No Open Graph image.

## Violet: regulatory

Unchanged from `../heatmap-dashboard/`: a private single-athlete instance. EAA / EN 301 549 are not triggered and no
accessibility statement is required. WCAG 2.2 AA is held voluntarily. The chart shows only weekly sums of data that is
already public on the dashboard (FR-017): **no new personal data, no heart rate**, so the accepted risks in the PRD do not move.

---

## Scope: deliberately absent

- Rolling 4/8/16-week totals, streak counters, a window-length switch (FR-014)
- Targets, goal lines, "you're up 12 %" deltas, training advice (Out of Scope; Marika's tone rule)
- Hover cards or click-through on bars: the tooltip is the native `<title>`, and the detail is the table
- Any JavaScript charting library: the chart is Blade + SVG + CSS
- The final design of Previous / Next (US-013)

If any of these appear in the implementation, the spec changed and this mockup is stale.
