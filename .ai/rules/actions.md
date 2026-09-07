---
paths:
  - 'app/Actions/**'
---

# Actions

## Actions are one-job invokable classes that do not authorize
An Action performs one write or one decision and exposes it as __invoke; dependencies are constructor-promoted private readonly. Actions never check authorization — the caller makes the access decision and the Action runs only once it has passed, so the ordering is structural rather than a comment inside one long method.
