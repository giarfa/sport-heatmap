<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application running on PHP 8.5. You are an expert with the Laravel ecosystem. Always use the APIs that match the installed major version of each package — do not assume a version.

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

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Always use `search-docs` before making code changes. Do not skip this step. It returns version-specific docs based on installed packages automatically.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project keeps committed, area-grouped rules in `.ai/rules` (settled decisions, non-obvious traps, standing constraints). Framework and package guidelines that only apply to specific paths (testing, frontend, components) also live there, under `.ai/rules/boost` — this is not just recorded decisions, it is load-bearing guidance you have not seen inline. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule.
- Record durable rules with `record-rule` so the next agent or teammate inherits them instead of working them out again. Pass a `glob` (e.g. `app/Http/Controllers/**`), a short `title`, and a few-line `note`. Always use `record-rule`, never your native memory or notes tool — native memory is personal and session-scoped; only `.ai/rules` is shared with the team and persists in the repo.

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

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== phpunit/core rules ===

# PHPUnit

- This application uses PHPUnit for testing. All tests must be written as PHPUnit classes. Use `php artisan make:test --phpunit {name}` to create a new test.
- If you see a test using "Pest", convert it to PHPUnit.
- Every time a test has been updated, run that singular test.
- When the tests relating to your feature are passing, ask the user if they would like to also run the entire test suite to make sure everything is still passing.
- Tests should cover all happy paths, failure paths, and edge cases.
- You must not remove any tests or test files from the tests directory without approval. These are not temporary or helper files; these are core to the application.

## Running Tests

- Run the minimal number of tests, using an appropriate filter, before finalizing.
- To run all tests: `php artisan test --compact`.
- To run all tests in a file: `php artisan test --compact tests/Feature/ExampleTest.php`.
- To filter on a particular test name: `php artisan test --compact --filter=testName` (recommended after making a change to a related file).

=== andreapollastri/larapilot/core rules ===

## Larapilot

Larapilot brings **spec-driven product development** to Laravel projects via [Laravel Boost](https://laravel.com/ai/boost). It turns your AI agent into a disciplined product squad: discovery → backlog → plan → implement → review → ship.

**Three layers:** Boost skills orchestrate the conversation; `php artisan larapilot:*` persists artifacts and enforces workflow via JSON envelopes; `.larapilot/` in the repo is the source of truth between sessions.

**Runtime loading:** at skill activation read `.larapilot/shared-runtime.md` (core rules: settings, personas, language, output economy, sub-agents); each skill names the additional runtime packs it needs (`.larapilot/runtime-discovery.md`, `runtime-delivery.md`, `runtime-ux.md`, `runtime-ship.md`, `runtime-ops.md`). Task body templates: `.larapilot/task-templates.md`.

**Project settings:** `.larapilot/config.yaml` → `settings` (`effort`, `backlog`, `git_mode`, `testing`, `auto_approve`, `lucille`, `github`, `gitlab`, `bitbucket`, `notifications`, `notify_slack`, `notify_discord`, `notify_telegram` — set via `/larapilot-settings`, exposed on `config-show` as `data.settings`). Every skill must read and honor `data.settings` before planning or implementing — canonical matrices in `.larapilot/shared-runtime.md` → **Project Settings**. Note: `GITFLOW` never auto-pushes (only `GITFLOW_PUSH` does); `ECO` never spawns sub-agents and **disables Lucille automatically** (re-enable with `larapilot:settings-set --lucille=YES`); boolean settings are `true`/`false` in YAML and `YES`/`NO` in envelopes; **Lucille is ON by default** otherwise; **GitHub/GitLab/Bitbucket + notifications are OFF by default** (setup in `.larapilot/integrations.md`).

### When to use Larapilot

Use Larapilot skills when the user wants to:

- Define a product vision or write a PRD (guided discovery interview — Project Kind Personal/Website/Application/Package, delivery target, MoSCoW; drop client docs in `.larapilot/client-materials/` and legacy snapshots in `.larapilot/legacy/` first)
- Build or evolve a **PHP/Laravel Composer package** (new or existing path/git) with professional standards, distribution, and docs
- Create or extend a backlog of user stories / specs
- Add **one new feature or evolutiva** on an existing project (`larapilot-feature`)
- Report or triage a **bug** (`larapilot-bug`)
- Link an **external frontend repo** from Laravel (`larapilot-frontend-companion`)
- Publish the repo into a **Backstage developer portal** — catalog entity + TechDocs (`larapilot-backstage`)
- Mirror the backlog into a **project tracker** — Linear, Asana, Jira, Trello, ClickUp, Monday (`larapilot-tracker`)
- Interrogate **time/token tracking** and deadlines with Lucille (`larapilot-usage`)
- Plan a spec with technical tasks and test strategy
- Implement a planned spec in a Laravel codebase
- Review and accept (or reject) a delivered increment
- Ship to production — security gate + deploy per the platform recorded in the PRD
- Create UI mockups before implementation

### Workflow

| Step | Skill | Output |
| --- | --- | --- |
| Discovery | `larapilot-inception` | `.larapilot/docs/PRD.md` |
| Feature / evolutiva | `larapilot-feature` | New `US-XXX` spec (+ optional PRD `FR-XXX`) |
| Bug report | `larapilot-bug` | Fix spec or rework + `.larapilot/docs/support/intake.md` |
| FE companion (split repo) | `larapilot-frontend-companion` | Link FE path, scan code; implement via `repo: frontend` from Laravel |
| Design (optional) | `larapilot-design` | `.larapilot/mockups/{spec}/` (dev route `/mockups/{spec}`); design system per PRD from `.larapilot/design-systems/` |
| Backlog | `larapilot-spec` | `.larapilot/backlog.yaml`, `.larapilot/specs/` |
| Planning | `larapilot-plan` | `.larapilot/plans/US-XXX-plan.yaml` |
| Implementation | `larapilot-implement` | Code, tests, review notes |
| Acceptance | `larapilot-review` | DONE or rework feedback |
| Ship (optional) | `larapilot-ship` | Security assessment + deploy + web launch checks |
| Settings | `larapilot-settings` | Persist `effort` / `backlog` / `git_mode` / `testing` / `auto_approve` / `lucille` in `.larapilot/config.yaml` |
| Usage / time tracking | `larapilot-usage` | Lucille: query ledger (tokens/minutes), schedule drift, export Markdown resoconto |
| Developer portal (optional) | `larapilot-backstage` | `catalog-info.yaml` + TechDocs (`mkdocs.yml`, `.larapilot/techdocs/`) for backstage.io |
| Project tracker (optional) | `larapilot-tracker` | Stories + plan subtasks in Linear/Asana/Jira/Trello/ClickUp/Monday; links in `.larapilot/tracker.yaml` |

### Installation

```bash
composer require andreapollastri/larapilot --dev
php artisan larapilot:install
php artisan boost:install
```

### Update

```bash
composer update andreapollastri/larapilot
php artisan larapilot:update
```

Register the Larapilot MCP server in your editor (in addition to `laravel-boost`): command `php`, args `["artisan", "mcp:start", "larapilot"]`.

### CLI contract

Skills call Artisan commands — never invent persistence logic:

- `php artisan larapilot:config-show`
- `php artisan larapilot:settings-set --effort=… --backlog=… --git-mode=… --testing=… --auto-approve=… --lucille=…`
- `php artisan larapilot:prd-write`
- `php artisan larapilot:validate-prd`
- `php artisan larapilot:frontend-set --path=/abs/fe/repo [--stack=React]`
- `php artisan larapilot:frontend-scan`
- `php artisan larapilot:backstage-export` _(read-only; `--write [--force] [--no-techdocs]` generates the Backstage catalog + TechDocs)_
- `php artisan larapilot:tracker-status` _(read-only; `--ping` verifies the provider credential)_
- `php artisan larapilot:tracker-push` _(backlog → tracker; `--dry-run`, `--spec=`, `--force`)_
- `php artisan larapilot:tracker-pull` _(tracker → drift report; `--apply` writes statuses back, never DONE)_
- `php artisan larapilot:spec-list`
- `php artisan larapilot:spec-add --file=...`
- `php artisan larapilot:spec-show US-001`
- `php artisan larapilot:spec-next`
- `php artisan larapilot:validate-spec --file=...`
- `php artisan larapilot:validate-plan US-001 --file=...`
- `php artisan larapilot:spec-plan US-001 --file=...`
- `php artisan larapilot:spec-start US-001`
- `php artisan larapilot:task-done US-001 TASK-01`
- `php artisan larapilot:quality` _(Pint + Larastan level 5+; `--fix` applies Pint formatting)_
- `php artisan larapilot:spec-review US-001`
- `php artisan larapilot:spec-request-changes US-001 --file=...`
- `php artisan larapilot:spec-approve US-001`
- `php artisan larapilot:metrics`
- `php artisan larapilot:usage-log --category=… --tokens=… --minutes=…` _(Lucille ledger)_
- `php artisan larapilot:usage-report [--insights] [--category=] [--user=] [--skill=] [--spec=] [--from=] [--to=]` _(Lucille query + Markdown resoconto)_
- `php artisan larapilot:schedule-set --deadline=YYYY-MM-DD` _(deadlines / drift notes)_
- `php artisan larapilot:choices-set --from-prd` _(dashboard inception snapshot)_

Parse stdout/stderr as JSON envelopes with schema `larapilot/v1`.

### Laravel-specific planning and implementation

- Use Boost `Search Docs` for version-aware Laravel guidance and `Database Schema` before designing migrations
- Follow Laravel conventions: Form Requests, Policies, Eloquent relationships, Pest/PHPUnit tests; prefer Artisan generators (`make:model`, `make:controller`, …)
- Laravel scaffolding defaults, Git/factories/testing discipline: **Laravel Scaffolding Defaults** and **Git Workflow** in `.larapilot/runtime-delivery.md`
- Mobile-first UX, WCAG, design systems, brand assets: `.larapilot/runtime-ux.md`

### Artifacts live in the repo

PRD `.larapilot/docs/PRD.md` (living product contract — see **PRD Living Document** in `.larapilot/runtime-ops.md`) · backlog `.larapilot/backlog.yaml` · specs `.larapilot/specs/US-XXX.yaml` · plans `.larapilot/plans/US-XXX-plan.yaml` · mockups `.larapilot/mockups/{spec}/` (served at `/mockups/{spec}` outside production) · docs (test-results, review, security, support, launch) under `.larapilot/docs/` · client materials `.larapilot/client-materials/` · legacy `.larapilot/legacy/` · research `.larapilot/research/` · usage ledger `.larapilot/usage/` (Lucille) · choices `.larapilot/choices.yaml` · tracker links `.larapilot/tracker.yaml` (commit it; ids only, never credentials). Dashboard: `/larapilot` (Board · PRD · Settings · Usage — dev/staging only).

### Personas

Larapilot personas are lenses, not costumes — 30 named agents (💎 Mark PM, 📐 John Architect, 🗄️ Mike Database, 📒 Lucille Account, ⌨️ Sarah CLI/Git/Linux, 🔧 Alex Developer, 🧪 Anne Tests, 🛡️ Robert Review, 🔐 Lars Security, 🚀 Jack DevOps, 🎨 Elise UX, …). **Sarah** steps in wherever CLIs, **Git in general** (conflicts, rebase/merge, history hygiene), forge automation, CI pipeline scripts, or Linux/terminal/server shell are needed (Jack keeps Gitflow policy + deploy orchestration). The canonical roster with roles lives in `.larapilot/shared-runtime.md` → **Agent Persona**. Chat output renders speakers as `icon + name`; brevity per **Output Economy** (artifacts, code, and CLI output stay complete and verbatim) — Zoey also posts one **Context estimate** line at skill start and end (see shared-runtime → **Output Economy → Context estimate**); Lucille logs tokens/time every session; optional readonly sub-agents per **Sub-agents** (never under `effort: ECO`).

</laravel-boost-guidelines>
