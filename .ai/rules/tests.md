---
paths:
  - 'tests/**'
---

# Tests

## Fake HTTP and forbid stray requests
Any test covering code that makes an outbound HTTP call sets Http::preventStrayRequests() in setUp() alongside Http::fake(), so an unfaked endpoint fails the test instead of reaching the live API. Override configuration with Config::set() inside the test, never by editing .env or phpunit.xml.

## Sign athletes in through the TestCase helpers
Use allowlistedAthlete() or allowlistAthletes() from Tests\TestCase whenever a test needs a signed-in athlete. The allowlist is re-checked on every authenticated request, so a test that creates a user straight from the factory and logs them in silently stops exercising the guard instead of failing.
