# US-005 — Manual test results

Checks the `NORMAL` automated bar does not cover (no Playwright/Dusk/Pest browser/axe suites are
planned for this spec). Verified against `feature/US-005-intensity-scale`.

## Verified programmatically (not by eye)

- [x] `npm run build` succeeds and the compiled stylesheet contains `--lv-1`…`--lv-4` in all three
      token blocks (`:root`, `html.dark`, the `prefers-color-scheme` block) with the exact hexes
      from `.larapilot/mockups/heatmap-dashboard/README.md`
- [x] `--lv-run` and `.hm-cell--ran` are fully removed from both the source and the compiled output
- [x] `.hm-cell--l1`…`.hm-cell--l4` present and mapped to the correct custom property
- [x] `.hm-key-swatch` overrides `aspect-ratio: auto` in the compiled output (would otherwise
      inherit `.hm-cell`'s `1 / 1` and inflate)

## Requires a human with a browser (not run in this session)

- [ ] View the dashboard at 375 px and 1280 px in **both** themes; confirm the five ramp steps
      (rest + 4 heat levels) are visually distinguishable in each
- [ ] Confirm the dark ramp reads lightest-at-peak (level 4 is the brightest step, not the darkest)
- [ ] Confirm the legend swatches are square and visually match the cells they describe
- [ ] Confirm the legend is reachable without scrolling interaction at every breakpoint (stacked
      below the grid at < 1024 px per the mockup's responsive contract; top of the right rail at
      ≥ 1024 px)

Automated contrast/axe checking is a `BEST`-bar activity and is not planned under `NORMAL`; the
mockup README's own validator commands remain the documented contrast contract (see README.md →
"The intensity scale").

## Demo data

`TrainingBlockSeeder` produces the calibrated (non-sparse) path — the seeded 16-week block has well
over four non-zero-distance days, so `php artisan migrate:fresh --seed` is enough to exercise the
calibrated legend by eye. The sparse and degenerate fallbacks are covered by
`tests/Feature/Dashboard/HeatmapLegendTest.php` rather than by seeded data, per the plan's Test Data
Strategy (they are edge cases, not demo data).
