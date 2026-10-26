# Mockup — Training heatmap dashboard

Visual contract for the `sport-heatmap` MVP. Static HTML, no application code.
Browse at `/mockups/heatmap-dashboard` (local/dev/staging only) or open the files directly.

| File | What it is |
| --- | --- |
| `index.html` | **Primary reference.** The dashboard, mobile-first responsive, light theme, working dark toggle |
| `dark.html` | Same screen stamped dark, for reviewing the dark palette without toggling |
| `states.html` | Every non-happy path: not connected, athlete rejected, sync stale/failed, loading, sparse window, empty |
| `breakpoints.html` | The same document in iframes at 320 / 375 / 768 / 1280 px |
| `favicon.svg`, `logo.svg` | Brand assets (Elise) |

**Design system:** [Tailwind CSS](../../design-systems/tailwind/) — per PRD `## Technical Architecture`
(**Frontend Topology:** Laravel-coupled, Blade + Tailwind 4, no Filament, no Starter Kit, no Bootstrap).
Screens load Tailwind via CDN as that reference requires; the project itself is Tailwind **4**, so Alex maps
tokens to a `@theme` block in CSS, not to a `tailwind.config.js`.

---

## The intensity ramp — read this before changing a colour

The ramp is the product. It was **computed and validated, not chosen by eye**, and it is the one thing in
this mockup that must not be casually retuned.

**Light mode** (cells on the white card `#ffffff`):

| Level | Meaning | Hex |
| --- | --- | --- |
| 0 | Rest — no run | `#e2e8f0` |
| 1 | Easy | `#86b6ef` |
| 2 | Steady | `#3987e5` |
| 3 | Hard | `#256abf` |
| 4 | Peak | `#104281` |

**Dark mode** (cells on the slate-900 card `#0f172a`) — the ramp **inverts**: lowest mileage sits nearest the
surface, highest is brightest. These are selected steps for the dark surface, not an automatic flip.

| Level | Hex |
| --- | --- |
| 0 | `#1e293b` |
| 1 | `#184f95` |
| 2 | `#2a78d6` |
| 3 | `#5598e7` |
| 4 | `#9ec5f4` |

Validated against the **actual card surfaces**, not generic defaults:

```
node scripts/validate_palette.js "#86b6ef,#3987e5,#256abf,#104281" --mode light --surface "#ffffff" --ordinal
node scripts/validate_palette.js "#9ec5f4,#5598e7,#2a78d6,#184f95" --mode dark  --surface "#0f172a" --ordinal
```

Both pass every gate: single hue (3° spread), monotone lightness, every adjacent step ≥ 0.06 ΔL, and the step
nearest the surface clears 2:1 (**2.11:1** light, **2.20:1** dark). **If you change a step, re-run the
validator.** Level 1 is deliberately not GitHub-pale — a 4 km recovery jog has to read as visibly *not* a rest day.

**Why blue.** GitHub green would clone the thing whose layout we borrowed. Strava orange `#fc4c02` implies
Strava's own branding on a surface that is not theirs — so it appears **only** on the "Connect with Strava"
button in `states.html`, where their guidelines expect it, and **never as a data colour**. Blue is also the
sequential default and sits correctly against the design system's slate neutrals.

Indigo (`#4338ca` light / `#a5b4fc` dark) is the accent for **focus rings and links only** — never a data colour.

---

## Grid geometry — the part that breaks if you rebuild it freehand

The heatmap is a real `<table>`: `<th scope="col">` weekdays, `<th scope="row">` week-start dates,
`<td>` day cells, week total in the last column. Screen readers announce the headers with each cell for free.

Column widths are **percentages on `<colgroup>`**, and the gaps between cells are **`padding` on the `<td>`**:

```css
.hm { width:100%; table-layout:fixed; border-collapse:separate; border-spacing:0; }
.hm-col-week { width:13%; } .hm-col-day { width:9.5%; } .hm-col-total { width:20.5%; }
.hm-td { padding:2px; }
```

**Do not reintroduce `border-spacing` for the gaps.** It is added *on top of* `width:100%`, which pushes Sunday
and the entire week-total column off a 375 px viewport. That was the first version of this mockup and it was wrong.

**Density is capped** at `max-width: 34rem` (`.dash-grid`). Without the cap, cells inflate to ~62 px at tablet
and ~90 px at desktop, which destroys the density that makes a heatmap readable at a glance. Desktop gets a
**side rail**, not bigger squares.

Cell states: rest = `--lv-0` fill; future days in the current week = **transparent with a dashed border**, a third
treatment that is neither a heat level nor a rest day. Current week row shows a `PARTIAL` badge on its total.

---

## Responsive & navigation contract

Design origin is 375 px. Desktop is progressive enhancement.

| Width | Behaviour |
| --- | --- |
| **320 px** | Smallest supported. Verified: all 7 days + week column + legend fit, no horizontal scroll. Cells ≈ 26 px |
| **375 px** | Design origin. Cells ≈ 31 px. Grid card, then legend card, then sync info — single column |
| **480 px** | Type steps up one notch; cell padding 2 → 3 px; radius 3 → 4 px |
| **640 px** (`sm`) | Sync status chip appears in the header (hidden below this; the footer carries it instead). Page padding 12 → 24 px |
| **768 px** | Grid capped at 34 rem and centred. Cells ≈ 48 px — the target density |
| **1024 px** (`lg`) | **Two columns**: grid left (34 rem), rail right with the legend card and the sync card. Grid stops centring |
| **1280 / 1920 px** | Container caps at `max-w-5xl`; layout does not change further. No full-bleed stretch |

**Navigation pattern: none, deliberately.** This is a single-screen product with one user. A sidebar or hamburger
with one destination is furniture, so the header carries only the logo (home), the sync chip, and the theme
toggle. No breadcrumbs — there is no depth. If FR-014 (window switching) ever ships, it belongs as a segmented
control **above the grid**, not as navigation chrome.

**Orientation:** the layout is a single column that reflows; landscape phones simply get the ≥ 640 px treatment.
Nothing is position-locked, so rotation cannot break it.

---

## Accessibility (WCAG 2.2 AA) — what Alex must preserve in Blade

- **Semantics:** `header` / `main` / `footer` landmarks, exactly one `h1`, `<table>` + `<caption class="sr-only">`
  describing the grid's shape, `scope` on every header cell.
- **Never colour-alone:** every cell carries an `sr-only` accessible name — `"Tuesday 19 May 2026: 8.2 km"`,
  `"Monday 18 May 2026: rest day, 0 km"`, `"Sunday 6 Sep 2026: not yet"` — plus a `title` for pointer users.
  The legend states numeric kilometre ranges, not just swatches. The ramp is single-hue and ordered by
  luminance, so it survives deuteranopia and protanopia.
- **Skip link** to `#main`, visible on focus.
- **Focus:** `:focus-visible` outline, 2 px accent, 2 px offset, on every interactive element. Do not remove it.
- **Touch targets:** the theme toggle and all buttons are **44 × 44 px**. Grid cells are **not interactive in the
  MVP** — they are static `<td>`s, so the target-size rule does not bind them, which is what lets 7 columns fit
  a 320 px viewport. **When FR-011 (day-cell detail) ships, cells become controls and the rule starts to bind:**
  solve it with a tap-through to a day drawer, *not* by shrinking the grid.
- **Motion:** the loading skeleton pulses only under `prefers-reduced-motion: no-preference`.
- **Live regions:** the loading card uses `aria-busy` + `aria-live="polite"`; the stale-sync banner is
  `role="alert"`.
- **Themes:** contrast verified in both. On first visit follow `prefers-color-scheme`, then persist the user's
  explicit choice (the mockup defaults to light so the primary reference screenshots light).

---

## Brand assets (Elise)

| Asset | Status |
| --- | --- |
| `favicon.svg` | **Delivered.** Four cells from the ramp; no background plate, so it works on light and dark browser chrome. Alex copies to `public/favicon.svg` and links it in the root Blade layout |
| `logo.svg` | **Delivered.** Mark + wordmark; the wordmark uses `currentColor`, so one file serves both themes. Clear space 8 px, minimum width 96 px — below that use the favicon mark alone |
| `apple-touch-icon.png` (180×180) | **Deferred, but worth doing** — this is a product you would add to a phone home screen to check after a run. Export from `favicon.svg` on a `#0f172a` plate |
| `og-default.png` (1200×630) | **Not produced.** No public surface, no sharing — see below |

**Typography:** Inter (400/500/600/700) via `fonts.bunny.net`. Numbers in the grid and totals use
`font-variant-numeric: tabular-nums` so columns align down the page.

---

## Emma — SEO

Not applicable, and that is a decision rather than an omission: the instance is private and single-user.
Every page carries `<meta name="robots" content="noindex, nofollow">`. **No** `robots.txt`, `sitemap.xml`,
`llms.txt`, breadcrumbs, or JSON-LD — there is nothing public to index and shipping them would be theatre.
Lighthouse **Accessibility ≥ 90** still applies as a gate; Performance is trivially met (one page, no JS
framework, one webfont).

*If the product ever gets a public marketing page, this section reopens in full.*

## Lauren — social

Nothing to distribute. Personal project, no public surface, no campaigns, no Open Graph image. The PRD keeps
Lauren silent on Personal projects and this mockup honours that.

## Violet — regulatory

A private single-user instance is not a consumer or public-sector service, so the **European Accessibility Act**
and **EN 301 549** are not triggered and **no accessibility statement page is required**. WCAG 2.2 AA is held
here voluntarily, because the owner uses this on a phone in poor light straight after a run. Only the
first-party session cookie is set — no banner. **Powered by Strava** attribution and activity deep-links
(FR-008) are contractual under the Strava API Agreement, not decorative.

---

## Scope — what is deliberately absent

The mockup shows the MVP and nothing else, so it can be trusted as a build contract. **Not drawn, on purpose:**

- FR-010 manual re-sync button
- FR-011 day-cell detail / per-activity breakdown
- FR-012 disconnect & purge
- FR-013 configurable fixed bands
- FR-014 window switching, rolling totals, streak counters
- FR-015 webhook-driven live updates

If any of these appear in the implementation, the spec changed and this mockup is stale.

## Sample data

`index.html` renders a realistic 16-week block — base, build, an illness week at 13 Jul, rebuild, an 86.8 km
peak, taper, and a partial current week — **68 running days**. The legend's kilometre ranges are **computed from
that data** (p25 = 7.2, p50 = 9.9, p75 = 14.1), not typed in, so the swatches and the numbers cannot disagree.
`states.html` uses a 3-run window to demonstrate the sparse fallback and an empty window for the zero case.

Window shown: **Mon 18 May – Sun 6 Sep 2026**, with "today" as Saturday 5 Sep 2026 so the final Sunday renders
as a future cell.

## Deviations

Differences between the shipped screens and these mockups that were discussed and agreed. Silent deviations are not allowed.

| Date | Screen | What | Why | Decision |
| --- | --- | --- | --- | --- |
| 2026-10-26 | `states.html` | The "not connected", "athlete rejected", "sync stale/failed" and "loading" states are retired; the "empty" state offers only the Garmin CSV import; the rail's detail card reads "Import / Last import / Runs in window" and the header chip "Last import N ago"; the bottom line loses "Runs missing from Strava?" | Strava is removed (US-021): nothing runs in the background, so no state can warn, load or reconnect | Accepted with the spec; decision `c022654e858f07e5` |
