---
paths:
  - 'app/**'
---

# App

## Sub-namespace each layer by the integration it serves
Classes that serve an external integration live in a folder named for it inside their layer: app/Actions/Garmin, app/Exceptions/Garmin, app/Support/Garmin, app/Data/Garmin, app/Http/Controllers/Garmin, app/Http/Requests/Garmin. Add a new integration's classes under its own subfolder in each layer rather than flat in the layer root.

## Comment why, in block comments, including mid-method
Non-obvious decisions carry a /* ... */ block above the code stating the reason and the failure mode it avoids, placed inside method bodies where the decision is made. This overrides the general "prefer PHPDoc, avoid inline comments" guidance: here the recorded rationale is the point, not an exception for complex logic.

## Never let credentials reach logs or exception messages
Exception messages and log context carry only non-secret diagnostics: HTTP status, the name of a missing key, an athlete id. Token values, the response body of a token call, and client secrets never appear in a message, a log line, or a reused chained-exception message. Chain the previous exception, but write a fresh message rather than reusing one that may embed a request URL.
