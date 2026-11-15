# Red-Team Report — v3.2.0

**Assessor:** Oliver (Larapilot Ethical Hacker) — reports to 🔐 Lars
**Date:** 2026-11-15
**Target:** `develop` at the `v3.2.0` tag, probed locally through the HTTP kernel (no staging host; production runs v3.1.0)
**Scope:** the aerobic-efficiency card (US-024) and what it reaches — `GET /` with `?to=`, the grid cache, the rendered Blade/SVG

## Attack surface delta

- **No new route, no new input.** `routes/` and `bootstrap/` are unchanged since v3.1.0; `DashboardController` only injects one more read (`EfficiencyRuns`).
- **No new outbound call, no new secret, no migration, no dependency change.**
- **New output:** one Blade component, `aerobic-efficiency.blade.php` — text from server-computed strings, SVG coordinates from server-computed numbers.
- **New cached shape:** `AerobicEfficiency`, `EfficiencyDot`, `EfficiencyWeek` and two enums join the grid object in the cache.

## Probes

| # | Probe | Result |
| --- | --- | --- |
| 1 | Signed out: `GET /`, `/?to=1900-01-01`, `/?to=<script>`, `/?to=2026-11-15' OR 1=1--` | 302 → `/login` on every one; nothing rendered before auth |
| 2 | Signed in: malformed / future / pre-history `?to=` | Covered by `WindowNavigationTest` data provider: a bad value renders the current window; `?to=` never reaches SQL as text (parsed to a date, bound) |
| 3 | Stored XSS through the card | No `{!! !!}` in the component or the dashboard; the card prints no run name or other stored text — only numbers, dates, and fixed copy, all through `{{ }}` |
| 4 | CSS / attribute injection via `style="left: …%"` and `points="…"` | Values are typed floats and pre-formatted coordinate strings built in PHP from integers in the database; no request input reaches them |
| 5 | Cache poisoning / PHP object injection | Key is `heatmap:grid:v7:{user}:g{generation}:{window start}:{today}` — no free text from the request; `unserialize()` is limited by the `serializable_classes` allowlist, which lists the five new classes and nothing broader |
| 6 | IDOR across athletes | Both new reads go through `$athlete->activities()`; there is one account and no id in the request |
| 7 | Cost amplification via `?to=` far in the past | A pre-history window is built uncached (existing guard against cache flooding) and now costs 2 more indexed reads; owner-only, behind sign-in and the forced password change |

## Findings

### [Low] Pre-history windows cost two more uncached reads

- **Location:** `GET /?to=…` → `EfficiencyRuns`
- **PoC:** signed in, request `/?to=2000-01-01` repeatedly.
- **Risk:** negligible CPU on a single-owner instance; unreachable signed out.
- **Recommendation:** none.

No Critical, High, or Medium finding.
