---
paths:
  - 'tests/**'
---

# Tests

## Fake HTTP and forbid stray requests
Any test covering code that makes an outbound HTTP call sets Http::preventStrayRequests() in setUp() alongside Http::fake(), so an unfaked endpoint fails the test instead of reaching the live API. Override configuration with Config::set() inside the test, never by editing .env or phpunit.xml.

## Sign the owner in through the TestCase helpers
Use signInOwner() from Tests\TestCase whenever a test needs a signed-in session: it creates `giarfa` with a password already chosen and acts as them. Every page sits behind `auth` and `password.set`, so a test that creates a user straight from the factory and requests a page gets a redirect to /login or to the first-password page instead of exercising the page.
