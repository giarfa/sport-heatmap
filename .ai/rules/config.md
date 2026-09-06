---
paths:
  - 'config/**'
---

# Config

## Product invariants are literals, not env() lookups
Settings that are product invariants rather than deployment choices — OAuth scope, provider endpoint URLs — are hardcoded literals in the config file, so no environment file can widen a scope or redirect a secret to another host. env() is read only inside config/, never from app code, because it returns null once config:cache has run. When an env value is parsed into a list, filter it so malformed input yields an empty array rather than a entry that accidentally matches.
