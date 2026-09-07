---
paths:
  - 'app/Data/**'
---

# Data

## External payloads become readonly DTOs at the boundary
Data crossing an external API boundary is carried in a final readonly class in app/Data, built by named static factories (fromExchangeResponse, fromRefreshResponse) that do the defensive casting. No raw response array reaches a controller, an Action, or Eloquent. These are plain PHP classes — spatie/laravel-data is not installed.
