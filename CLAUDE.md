<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

## Foundational Context

This application is a Laravel application running on PHP 8.4. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If a frontend change doesn't show in the UI or you get a "Unable to locate file in Vite manifest" error, run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Use `search-docs` before changes that depend on Laravel ecosystem APIs, behavior, configuration, or version-specific syntax. Skip it for copy-only edits and other changes where package documentation is irrelevant. Reuse sufficient results already in context instead of searching again.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists, including path-scoped framework guidelines under `.ai/rules/boost`. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== herd rules ===

# Laravel Herd

- The application is served by Laravel Herd at `https?://[kebab-case-project-dir].test`. Use the `get-absolute-url` tool to generate valid URLs. Never run commands to serve the site. It is always available.
- Use the `herd` CLI to manage services, PHP versions, and sites (e.g. `herd sites`, `herd services:start <service>`, `herd php:list`). Run `herd list` to discover all available commands.

=== tests rules ===

# Test Enforcement

- Add or update tests for behavior and logic changes when a test provides meaningful regression coverage.
- Pure copy, styling, and layout-only changes do not require new or updated tests.
- When test coverage applies, run the affected tests and ensure they pass.
- Test the changed behavior and its important failure modes, but do not add tests beyond them.
- Read the `testing-best-practices` skill before writing tests.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== andreapollastri/larapilot/core rules ===

## Larapilot

Larapilot is spec-driven product development for Laravel via [Laravel Boost](https://laravel.com/ai/boost): discovery → backlog → plan → implement → review → ship. Boost skills run the conversation, `php artisan larapilot:*` persists artifacts as JSON envelopes (`larapilot/v1`), and `.larapilot/` in the repo is the source of truth between sessions.

### Context protocol

A Larapilot skill starts with **`php artisan larapilot:context {skill}`**. One envelope carries `data.settings`, `data.paths`, `data.project`, and `data.runtime`:

- Read every file under `runtime.read`, from `runtime.dir`, with the editor file-read tool — all in one step when the editor reads in parallel. Never `cat` one. A truncated preview means the rules were not loaded: read the rest before anything else.
- Do not read what `runtime.loaded` lists, nor a file this conversation already read: it is still here.
- Read a `runtime.on_demand` file only when its `when` comes true.
- Pass `--session={data.session}` on every later `context` call of the conversation. After the conversation was compacted, pass `--fresh`: the token survives a summary, the rules do not.

The files are compiled for the project and hold only the rules its settings call for. Honor `data.settings` before planning or implementing, and never apply from memory a rule the files do not show. Outside a skill, load no Larapilot runtime file.

### When to use Larapilot

- New product or PRD → `larapilot-inception` (client docs in `.larapilot/client-materials/`, legacy snapshots in `.larapilot/legacy/`)
- Existing Laravel app with no PRD → `larapilot-adopt`
- One new feature → `larapilot-feature`. A bug → `larapilot-bug`. A request that may be either → `larapilot-triage`, which classifies it and hands off. Security findings from Aikido → `larapilot-aikido`, errors in production from the tracker of the project (Boogle, Sentry, …) → `larapilot-error`, which asks which tracker when none is set: each downloads them, has the user confirm, and hands them to triage group by group
- A change to the PRD that is neither — sharpen, re-prioritize, re-scope, answer an open question, upgrade an older PRD → `larapilot-prd`. A PRD edited by hand → `larapilot-prd` to reconcile history, validation, and backlog
- Backlog → `larapilot-spec`. Plan → `larapilot-plan`. Implement → `larapilot-implement`. Accept → `larapilot-review`
- Mockups → `larapilot-design`. External frontend repo — a single app or a monorepo, its agent rules, a handoff to its team → `larapilot-frontend-companion`
- Ship → `larapilot-ship`. Releases, when `release_mode` is YES → `larapilot-release`
- Upgrade Laravel → `larapilot-laravel-upgrade`, PHP → `larapilot-php-upgrade`, the database or its engine → `larapilot-db-upgrade`: each checks readiness first (`larapilot:upgrade-check`), reports the criticalities, and changes nothing until the user chooses. Vulnerable dependencies (CVE) and the SBOM → `larapilot-vendor-check`
- Settings → `larapilot-settings`. Usage → `larapilot-usage`. Re-plan the order and the dates of delivery → `larapilot-schedule`. Quote → `larapilot-economics`
- Tracker, Backstage, project handbook, custom skills → the matching `larapilot-*` skill

### Working rules

- **Read the slice, not the document** — `spec-list` (the backlog without the bodies), `spec-show` / `spec-next` with `--task=` / `--fields=`, `prd-show --ids=` / `--section=`, `quality` without `-v`. The commands of a skill are in that skill: do not load the whole catalog.
- **Paths** come from `data.paths`. Defaults: PRD `.larapilot/docs/PRD.md`, backlog `.larapilot/backlog.yaml`, specs `.larapilot/specs/`, plans `.larapilot/plans/`, mockups `.larapilot/mockups/{spec}/`, developer domain docs `.larapilot/docs/devs/` (English, every effort level), decision journal `.larapilot/decisions.yaml`. Do not commit absolute paths.
- **Settings** — `GITFLOW` never auto-pushes. `ECO` never spawns sub-agents and turns Lucille off. Lucille and the decision journal default to ON.
- **Hooks** — with `hooks` YES, a transition (`spec-plan`, `spec-start`, `task-done`, `spec-review`, `spec-approve`, …) runs the project's hooks from `.larapilot/hooks.yaml`. `E_PRECONDITION` with `details.hooks` means a hook failed or a skill hook must run first: fix it and repeat, or report it. Never edit the hooks or turn them off to get past one unless the user asks.
- **Personas** are lenses: chat uses `icon + name`, briefly (**Output Economy**). Review and explore sub-agents are readonly. Under `STANDARD` or `MAX`, autopilot may delegate one spec at a time to a writing worker that does not call Larapilot transitions; the parent keeps CLI, questions, and Robert/Lars (**Spec worker**), and does not read `runtime-delivery`, `runtime-dev-docs`, or `task-templates`.

</laravel-boost-guidelines>
