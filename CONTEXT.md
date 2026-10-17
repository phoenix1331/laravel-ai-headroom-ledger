# Context

**Headroom Ledger** (`phoenix1331/laravel-ai-headroom-ledger`) listens to Headroom's events and keeps one database row per AI provider call: the estimate made before the call, the tokens actually used, the cost, how close it came to the context limit, and whether it was warned, blocked or failed. It exposes that data through a typed query API and an artisan report.

This is a Composer **package**, not a Laravel app. There is no `app/`, no routes, no Docker and no database server; it is tested inside Orchestra Testbench on in-memory SQLite.

## Headroom family

| Package | Role |
|---|---|
| `phoenix1331/laravel-ai-headroom` | Counting, guards, calibration, events. Stores nothing |
| `phoenix1331/laravel-ai-headroom-ledger` | **This package.** Records Headroom events, query API, rollups, report |
| `phoenix1331/filament-headroom` | Filament dashboard; reads the ledger only through the query API |

## Architecture at a glance

| Path | Role |
|---|---|
| `src/AiHeadroomLedgerServiceProvider.php` | Merges `config/headroom-ledger.php`; publish tags `headroom-ledger-config`, `headroom-ledger-migrations` |
| `config/headroom-ledger.php` | `enabled`, `connection`, `table_prefix`, `queue` (connection `sync` records inline), `tags.allowed` (default empty), `tags.primary_tag`, `retention_days.entries`/`rollups`, `rollup.enabled`/`time` |
| `database/migrations/` | One publishable migration creating `{prefix}entries`, `{prefix}tags`, `{prefix}daily`; honours `connection` via `getConnection()` and `table_prefix`. Not auto-loaded: apps publish and migrate; tests load it in `TestCase::defineDatabaseMigrations()` |
| `src/Models/` | `@internal` Eloquent models: `LedgerModel` (base: no timestamps, connection from config unless set explicitly), `Entry` (string PK `request_id`, enum casts for `count_method`/`outcome`, `breakdown` array, money as int), `Tag`, `DailyRollup`. Never returned by the public API |
| `src/Enums/Outcome.php` | `counted`, `completed`, `warned`, `blocked`, `failed` |
| `src/Support/` | `@internal`: `TableNames` (prefix + connection from config), `MicroUnits` (amount <-> integer millionths, rounded) |
| `database/factories/` | `LedgerEntryFactory`, shipped for the Filament plugin and app seeding |
| `tests/` | Pest + Testbench; `TestCase` loads laravel/ai, Headroom and the ledger, blocks stray HTTP |
| `testbench.yaml` | Providers and migrations for `vendor/bin/testbench` (manual artisan runs, smoke test) |

Most of `src/` is planned, not yet built. Treat the table as the target layout.

## Event to row mapping

All listeners upsert by `request_id`, so the row is correct whatever order queued listeners run in.

| Headroom event | Writes |
|---|---|
| `TokensCounted` | Counted provider/model, count method, estimate, breakdown, context window, percent used, estimated cost, tags |
| `TokensUsed` | Used provider/model, actual input/output/cached tokens, actual cost, `cost_approximate`, streamed, outcome `completed`. Carries the estimate itself, so accuracy needs no cross-row lookup |
| `RequestBlocked` | Outcome `blocked`; carries a full `TokenCount`, so count fields are written too |
| `HeadroomLow` | Outcome `warned` |
| `RequestFailed` | Outcome `failed`; reason is exception class + HTTP status only, never the message |

Rows that never receive `TokensUsed` (blocked, abandoned stream, `Headroom::for()` without `recordUsage()`) stay `counted` or `blocked`; queries report them as estimate-only, never as zero usage.

## Dependencies and constraints

| Package | Constraint | Why |
|---|---|---|
| `php` | `^8.3` | Matches Headroom |
| `illuminate/*` | `^12.0\|^13.0` | Matches Headroom |
| `phoenix1331/laravel-ai-headroom` | `^0.1` | v0.1.0 ships the event design (shared request ID, invocation ID, estimate on `TokensUsed`, cache-aware `Cost`, context tags) |
| `orchestra/testbench` (dev) | `^10.0\|^11.0` | Testbench 10 = Laravel 12, 11 = Laravel 13 |
| `pestphp/pest` (dev) | `^4.2\|^5.0` | Pest 4 covers PHP 8.3; same reasoning as Headroom |
| `pestphp/pest-plugin-laravel` (dev) | `^4.0\|^5.0` | Matches Pest |
| `laravel/pint` (dev) | `^1.32` | Code style; `pint.json` uses the `laravel` preset plus `declare_strict_types` |
| `larastan/larastan` (dev) | `^3.0` | Static analysis at `level: max`, no baseline; paths `src`, `config`, `database`; `config` declared as a config directory so `env()` there is allowed |
| `husky` (npm dev) | `^9.1.7` | Git hooks in `.husky/` (tracked): `commit-msg` (lowercase conventional commits, no scope), `pre-commit` (`pint --test`, `osv-scanner` on `composer.lock`). Run `npm install` once to activate. Hooks run under `sh`, so POSIX only |

## CI (`.github/`)

| Workflow | Trigger | Runs |
|---|---|---|
| `tests.yml` `test` | push/PR to `main` | PHP 8.3/8.4/8.5 x Laravel 12/13 x prefer-lowest/prefer-stable on SQLite; Pint `--test` on prefer-stable |
| `tests.yml` `databases` | push/PR to `main` | PHP 8.4 x Laravel 12/13 x `postgres:17`, `mysql:8.4`, `mariadb:11` (Laravel's `mariadb` driver) as service containers; waits for a PDO connection, then `DB_*` env overrides `phpunit.xml`, which does not force its values |
| `tests.yml` `coverage` | push/PR to `main` | pcov, `--min=90`, output in the job summary (`shell: bash` so `tee` keeps the failure) |
| `tests.yml` `archive` | push/PR to `main` | `git archive HEAD \| tar -t` must contain only `src/`, `config/`, `database/`, `composer.json`, `README.md`, `LICENSE` |
| `static-analysis.yml` | push/PR to `main` | Larastan with GitHub annotations |
| `release.yml` | tag `v*` | GitHub release with notes from the matching `## [X.Y.Z]` `CHANGELOG.md` section; fails if missing |

Community docs: `CONTRIBUTING.md` (setup, checks, ground rules, adding a report grouping, schema changes), `SECURITY.md` (stored vs not stored, tags as personal data, erasure, retention), `RELEASING.md` (0.x hold until `filament-headroom`), `CODE_OF_CONDUCT.md` (Contributor Covenant 2.1). Issue templates: bug report (database type/version, recording mode), feature request; blank issues off. All export-ignored.

## Decisions

| Decision | Reason |
|---|---|
| Money stored as integer micro-units (millionths) plus `currency` | A single call often costs a fraction of a cent; minor units would round most rows to zero. Integers keep sums exact and portable across databases |
| `request_id` is `string(64)`, not a 26-char ULID column | Headroom generates ULIDs but `Headroom::for()` accepts a caller-supplied ID; invocation IDs are SDK UUIDv7 |
| Cache tokens stored as `cache_read_tokens` and `cache_write_tokens` (brief said one `cached_tokens`) | `TokensUsed` reports both and they are priced differently; `tokens()` can still expose a combined "cached" figure |
| `occurred_on` date column on entries (counted date, else used date) | `byDay()` and rollups group on a plain date, avoiding driver-specific date functions |
| Daily grain: date, agent, provider, model, count method, currency, primary tag key/value | Accuracy is per model and count method; currencies are never summed. Model/provider are the used ones where known, else counted |
| Daily table has no unique key; rollup deletes and rebuilds a day | Nullable grain columns make unique keys unreliable (NULLs are distinct) across databases |
| `percent_used` is `decimal(7,2)`; daily keeps sum, max and sample count | Exact on every driver and can exceed 100 for blocked requests; averages come from sum/count, not `AVG()` |
| Index and foreign key names are explicit, built from `table_prefix` plus a short suffix | MySQL caps names at 64 chars; PostgreSQL needs them unique per schema |
| `DB_FOREIGN_KEYS=true` in `phpunit.xml` | Testbench's in-memory `testing` connection disables SQLite foreign keys by default; real apps have them on, and tag cascade deletes depend on them |
| Internal models are `Entry`, `Tag`, `DailyRollup`, not `LedgerEntry` | `LedgerEntry` is reserved for the public value object returned by `Ledger::find()` |
| `currency` stays a plain 3-letter string on models, not Headroom's `Currency` enum | Headroom's enum only has USD and GBP; old rows must still load if it changes |
| `TestCase::tearDown()` resets `headroom-ledger.connection` and `table_prefix` before `parent::tearDown()` | Testbench rolls migrations back in a callback registered before `defineDatabaseMigrations()`, so a test that changed either would roll back the wrong tables and leak them into the next test on a persistent database |
| `breakdown` key order is not guaranteed | MySQL's JSON type reorders keys; the public value object must normalise the order |
| Query API is the only supported read path; returns final readonly value objects, never Eloquent models | Schema can change without breaking consumers (Filament plugin included) |
| Tags stored only for allowlisted keys, default empty | Nothing personal is recorded unless the app opts in; user IDs are personal data |
| Never store prompt or response text | Privacy; enforced by arch tests |
| `forgetTag()` also clears a matching `primary_tag` in daily rollups | Rollups keep only the primary tag, so erasure must reach them too; promised in `SECURITY.md` |
| Mixed currencies are grouped per currency, never summed | No currency conversion in scope; consumers decide |
| Aggregates read daily rollups for closed days, raw entries for today | Keeps dashboards fast without losing same-day data |
| Stay on 0.x until `filament-headroom` is built against the ledger | The dashboard is what proves the schema and query API before they become 1.0 public API |
| No Docker, FrankenPHP, Makefile, local MySQL or `request.http` | Package development only needs PHP and Composer in WSL; Composer scripts replace Makefile targets |
| No Dependabot | Avoids a stream of automated PRs; dependencies are updated by hand. osv-scanner in the pre-commit hook still flags vulnerable packages |
| `composer.lock` not committed | Library; consumers resolve their own versions |
| CI databases: SQLite, PostgreSQL, MySQL, MariaDB | SQLite is too lenient (loose GROUP BY, typing, lengths) to prove aggregate queries; MySQL/MariaDB are what most Laravel apps run. CI only, no local database needed |

## Checks

Run in WSL at the end of every task. Composer scripts stand in for Makefile targets.

| Script | Runs |
|---|---|
| `composer lint` / `lint:test` | `pint` / `pint --test` |
| `composer analyse` | `phpstan analyse --memory-limit=1G` |
| `composer test` / `test:coverage` | `pest` / `pest --coverage --min=90` (pcov, `src/` only) |
| `composer check` | `lint`, `analyse`, `test:coverage` in that order; stops at the first failure |
