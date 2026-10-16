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
| `database/migrations/` | Publishable, database-agnostic: `entries`, `tags`, `daily` tables |
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

## Decisions

| Decision | Reason |
|---|---|
| Money stored as integer micro-units (millionths) plus `currency` | A single call often costs a fraction of a cent; minor units would round most rows to zero. Integers keep sums exact and portable across databases |
| Query API is the only supported read path; returns final readonly value objects, never Eloquent models | Schema can change without breaking consumers (Filament plugin included) |
| Tags stored only for allowlisted keys, default empty | Nothing personal is recorded unless the app opts in; user IDs are personal data |
| Never store prompt or response text | Privacy; enforced by arch tests |
| Mixed currencies are grouped per currency, never summed | No currency conversion in scope; consumers decide |
| Aggregates read daily rollups for closed days, raw entries for today | Keeps dashboards fast without losing same-day data |
| Stay on 0.x until `filament-headroom` is built against the ledger | The dashboard is what proves the schema and query API before they become 1.0 public API |
| No Docker, FrankenPHP, Makefile, MySQL or `request.http` | Package development only needs PHP and Composer in WSL; Composer scripts replace Makefile targets |
| `composer.lock` not committed | Library; consumers resolve their own versions |
| CI databases: SQLite and PostgreSQL | MySQL/MariaDB jobs pending approval |

## Checks

Run in WSL at the end of every task. Composer scripts stand in for Makefile targets.

| Script | Runs |
|---|---|
| `composer lint` / `lint:test` | `pint` / `pint --test` |
| `composer analyse` | `phpstan analyse --memory-limit=1G` |
| `composer test` / `test:coverage` | `pest` / `pest --coverage --min=90` (pcov, `src/` only) |
| `composer check` | `lint`, `analyse`, `test:coverage` in that order; stops at the first failure |
