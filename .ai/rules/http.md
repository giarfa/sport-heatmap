---
paths:
  - 'app/Http/**'
---

# Http

## Single-action invokable controllers; Form Requests only for submitted forms; no Policies
Controllers are single-action classes exposing __invoke, with collaborators type-hinted as __invoke parameters rather than constructor-injected. User-submitted forms — so far only the Garmin CSV import (upload and confirm) — validate through Form Requests under app/Http/Requests/{Integration}, with an explicit message per rule; their authorize() returns true because access is the route middleware (auth + strava.fresh, which re-checks the allowlist). There is still no app/Policies: one owner, no per-resource rules. Query-string input gets no Form Request: read attacker-supplied query parameters through a private helper that returns '' for anything non-string: `?state[]=x` arrives as an array and a plain (string) cast turns into a 500.
