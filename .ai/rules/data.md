---
paths:
  - 'app/Data/**'
---

# Data

## External payloads become readonly DTOs at the boundary
Data crossing an external boundary — today a parsed Garmin CSV row — is carried in a final readonly class in app/Data, built by named static factories (fromCsvRow) that do the defensive casting. No raw payload array reaches a controller, an Action, or Eloquent. These are plain PHP classes — spatie/laravel-data is not installed.
