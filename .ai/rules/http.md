---
paths:
  - 'app/Http/**'
---

# Http

## Single-action invokable controllers; no Form Requests or Policies
Controllers are single-action classes exposing __invoke, with collaborators type-hinted as __invoke parameters rather than constructor-injected. This app has no user-submitted forms, so there is no app/Http/Requests and no app/Policies — do not add them for query-string input. Read attacker-supplied query parameters through a private helper that returns '' for anything non-string: `?state[]=x` arrives as an array and a plain (string) cast turns into a 500.
