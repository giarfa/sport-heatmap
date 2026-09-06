# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Strava OAuth sign-in: the dashboard is reached by authorising Strava once, with no password and
  no registration (US-002).
- An athlete allowlist read from `STRAVA_ALLOWED_ATHLETE_IDS`, which is what keeps an instance
  private (US-002).
- Transparent access-token refresh, including a configurable safety margin
  (`STRAVA_TOKEN_REFRESH_MARGIN`) so a token cannot expire mid-request (US-002).
- A reconnect state on the connect screen for when the stored refresh token is no longer
  accepted (US-002).
- The foundation schema: athlete, activity and sync-run tables, with factories and a 16-week
  demo training block (US-001).

### Security

- The athlete allowlist is enforced **before** any token, user record, or session is created, so a
  rejected attempt leaves the database untouched.
- The allowlist is re-checked on **every** authenticated request, so removing an athlete ends their
  session immediately rather than whenever it happens to expire.
- A callback granting any write scope is refused and never exchanged: read-only is a property of
  what is stored, not only of what was asked for.
- Only the `activity:read_all` scope is ever requested. The scope is a literal in
  `config/strava.php`, not an environment value, so no deployment can widen it.
- Both OAuth routes are rate limited to 10 requests per minute per IP.
- Access and refresh tokens are stored through Eloquent `encrypted` casts; the client id and
  secret live only in the environment and never reach the database or the repository.
- The OAuth callback is guarded by a single-use `state` parameter compared with `hash_equals`, and
  the session id is regenerated on login.
