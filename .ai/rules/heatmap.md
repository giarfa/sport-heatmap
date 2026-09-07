---
paths:
  - 'app/Support/Heatmap/**'
---

# Heatmap

## Percentile method for the intensity scale is pinned to R-7
`CalibrateIntensityScale` computes p25/p50/p75 with R-7 linear interpolation (the numpy / Excel `PERCENTILE.INC` default), not nearest-rank. This is not a style preference: R-7 is the only method that reproduces the mockup's validated legend (7.2 / 9.9 / 14.1 km over its 68-day dataset). Nearest-rank yields 7.0 / 9.8 / 14.0 km instead — it passes a naive unit test but silently desynchronises the legend from the design contract. `tests/Unit/Support/Heatmap/CalibrateIntensityScaleTest.php` pins this with a regression test against that exact dataset; do not swap the method without updating both the mockup contract and that test.
