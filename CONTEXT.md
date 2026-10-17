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
| `config/headroom-ledger.php` | `enabled`, `connection`, `table_prefix`, `queue` (connection `sync` records inline), `tags.allowed` (default empty), `tags.primary_tag`, `retention_days.entries`/`rollups`, `rollup.enabled` (build and use rollups), `rollup.schedule` (cron expression, default `15 0 * * *`, or false to schedule the command yourself) |
| `database/migrations/` | One publishable migration creating `{prefix}entries`, `{prefix}tags`, `{prefix}daily`, `{prefix}rollup_days`; honours `connection` via `getConnection()` and `table_prefix`. Not auto-loaded: apps publish and migrate; tests load it in `TestCase::defineDatabaseMigrations()` |
| `src/Models/` | `@internal` Eloquent models: `LedgerModel` (base: no timestamps, connection from config unless set explicitly), `Entry` (string PK `request_id`, enum casts for `count_method`/`outcome`, `breakdown` array, money as int), `Tag`, `DailyRollup`. Never returned by the public API |
| `src/Recording/EntryRecorder.php` | `@internal`. One method per event; each is a single upsert keyed by `request_id` that updates only the columns that event owns. Values pass through `Entry` casts first, so privacy guards apply to upserts too. A null event value never overwrites a known one. Outcome only changes through conditional updates. Stores allowlisted context tags from `TokensCounted`, `TokensUsed` and `RequestBlocked` (one upsert per request on `(request_id, key)`). Methods: `counted()`, `used()`, `blocked()`, `warned()`, `failed()` |
| `src/Listeners/` | `RecordsToLedger` (abstract, `ShouldQueue`, 3 tries with 5/30/120 s backoff, connection and queue from `headroom-ledger.queue`) and one thin subclass per Headroom event: `RecordTokensCounted`, `RecordTokensUsed`, `RecordRequestBlocked`, `RecordHeadroomLow`, `RecordRequestFailed`. Registered in the provider when `enabled` is true at boot; `enabled` is checked again on each run |
| `src/Events/` | Public API: `LedgerEntryRecorded` (after every write) and `LedgerEntryCompleted` (after usage is recorded), both final readonly with `requestId`, `invocationId`, `outcome`; never an Eloquent model |
| `src/LedgerManager.php`, `src/Facades/Ledger.php` | Public entry point (singleton, alias `Ledger`). Built: `query()`, `find($requestId)`, `run($invocationId)` (steps ordered by `coalesce(counted_at, used_at)`), `forgetTag($key, $value)` |
| `src/Data/` | Public, final readonly value objects: `Money` (micro-units, ISO currency, `estimated`, `approximate`; `plus()` throws `CurrencyMismatch`), `Spend` (Money per currency, never summed across; `in()`, `sole()`), `TokenTotals` (with `requests` and `requestsWithUsage`), `LedgerEntry` (nulls for unreported figures; `cost()` actual else estimate), `LedgerEntries` (typed list; `tokens()`, `spend()`, `collect()`), `RunSummary`, `SpendSummary` (`total`, `actual`, `estimatedOnly` as `Spend`, plus request counts), `Breakdown` (typed list of `Group` key/value, natural key order, null group last), `AccuracyStat` / `AccuracyStats` (per counted model and count method; `for()`, `overall()`) |
| `src/Query/LedgerQuery.php` | Public, immutable filter builder from `Ledger::query()`: `between()`, `lastDays()`, `forAgent()`, `forProvider()`, `forModel()`, `forTag()` (repeatable, AND), `nearLimit()`, `blocked(includeWarned: true)`, `withOutcome()`; `entries(?limit)` newest first, `count()`, `spend()`, `tokens()`, `accuracy()`; `byModel()`, `byProvider()`, `byAgent()`, `byDay()`, `byRun()`, `byTag($key)` return a `GroupedQuery`. Filters are stored as data. Private `plan()` splits each aggregate: closed days listed in `rollup_days` (built with the current primary tag when the query uses it) from `{prefix}daily`, every other day from raw entries; raw only when `rollup.enabled` is false or for `nearLimit()`, outcome filters, `byRun()`, non-primary or several tags. Both sides use `exists`/`not exists` against `rollup_days`, never date lists |
| `src/Query/GroupedQuery.php`, `Aggregator.php`, `Grouping.php` | `GroupedQuery` (public): `spend()`/`tokens()` as a `Breakdown`. `Aggregator` (`@internal`): one portable `GROUP BY` query per source per call, per group and currency; rollup sums use the same aliases as raw totals so one merge handles both |
| `src/Query/EntryMapper.php` | `@internal`: `Entry` row to `LedgerEntry`; the only place models become public data |
| `src/Exceptions/CurrencyMismatch.php` | Thrown when amounts in different currencies would be combined |
| `src/Rollup/DailyRollupBuilder.php` | `@internal`. `build($day)` rebuilds one day's `{prefix}daily` rows from its entries in one transaction (lock the day's `rollup_days` row with `FOR UPDATE`, delete, insert in 500-row chunks, record `primary_tag_key`, `rows`, `built_at`) and returns the row count |
| `src/Console/RollupCommand.php` | `headroom-ledger:rollup`: with no options catches up from the last rolled-up day (rebuilt again for late entries), else the oldest entry, to yesterday, at most 31 days; `--date=Y-m-d` for one day; `--from` [`--to`, default yesterday] to backfill further. Strict dates (2026-02-30 rejected); one line per day with row count. Scheduled by the provider via `callAfterResolving(Schedule)` with `->cron(rollup.schedule)`, `withoutOverlapping()->onOneServer()`, when `enabled` and `rollup.enabled` are true and `rollup.schedule` is not false |
| `src/Enums/Outcome.php` | `counted`, `completed`, `warned`, `blocked`, `failed` |
| `src/Support/` | `@internal`: `TableNames` (prefix + connection from config), `MicroUnits` (amount <-> integer millionths, rounded), `TagValue` (scalar tag value as stored and matched: string, bools `1`/`0`) |
| `src/Support/Casts/` | `DateOnly` (stores `Y-m-d` for `occurred_on` and rollup `date`); privacy guards on `Entry`: `TokenBreakdown` (section names to whole token counts only; read back in a fixed section order), `ReasonCode` (a `LimitType` value or `ExceptionClass[:status]`; messages throw) |
| `tests/Arch/` | Arch suite: no debug calls, strict types, Pest `php` and `security` presets, string-backed enums, final support classes, final readonly `Data` and `Events`. `PublicApiTest`: reflection over every public method return type and public property in the facade, manager, `LedgerQuery`, `GroupedQuery`, `Data`, `Events`, `Enums`, `Exceptions` rejects Eloquent models, builders and collections; those namespaces may not import `Models` or Eloquent; facade `@method` list must match the manager |
| `tests/Feature/Privacy/` | Schema guard: exact reviewed column list per table, no text/blob types outside `breakdown`, no column named after prompt/response content, migrations declare no text/binary columns |
| `database/factories/LedgerEntryFactory.php` | Public, shipped for the Filament plugin and app seeding (`Entry::factory()` or `LedgerEntryFactory::new()`). Default is a completed request; states `counted`, `completed`, `warned`, `blocked`, `failed`, `streamed`, `abandoned`, `approximateCost`, `failover`, `inRun`, `forAgent`, `forModel`, `countMethod`, `inCurrency`, `at`, `withTags`. No Faker |
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
| `illuminate/console` | `^12.0\|^13.0` | Rollup command and scheduler |
| `dragonmantank/cron-expression` | `^3.4` | Validates `rollup.schedule`; only suggested by `illuminate/console`, required by `laravel/framework` at the same constraint |
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
| Rollup grain matches the raw query grain exactly: day (`occurred_on`), agent, provider and model answered else counted, count method, currency, primary tag value; accuracy sums use the `accuracy()` rule | Rolled-up totals equal raw totals for the same day (tested on all four databases) |
| Daily table has `requests_with_usage` and `priced_requests` (added to the unreleased migration) | Warned requests are answered too, so neither follows from outcome counts; spend summaries need both |
| Rollup rows store the configured `primary_tag` key even when a request lacked the tag (value null); `forgetTag()` clears only the value | Task 25 can tell whether rollups were built with the current key; a forgotten row reads as untagged, like its entries |
| `primary_tag` is ignored unless it is also allowlisted | No entry can carry a tag that is not recorded |
| Daily table has no unique key; rollup deletes and rebuilds a day | Nullable grain columns make unique keys unreliable (NULLs are distinct) across databases |
| `percent_used` is `decimal(7,2)`; daily keeps sum, max and sample count | Exact on every driver and can exceed 100 for blocked requests; averages come from sum/count, not `AVG()` |
| Index and foreign key names are explicit, built from `table_prefix` plus a short suffix | MySQL caps names at 64 chars; PostgreSQL needs them unique per schema |
| `DB_FOREIGN_KEYS=true` in `phpunit.xml` | Testbench's in-memory `testing` connection disables SQLite foreign keys by default; real apps have them on, and tag cascade deletes depend on them |
| Internal models are `Entry`, `Tag`, `DailyRollup`, not `LedgerEntry` | `LedgerEntry` is reserved for the public value object returned by `Ledger::find()` |
| `currency` stays a plain 3-letter string on models, not Headroom's `Currency` enum | Headroom's enum only has USD and GBP; old rows must still load if it changes |
| `TestCase::tearDown()` resets `headroom-ledger.connection` and `table_prefix` before `parent::tearDown()` | Testbench rolls migrations back in a callback registered before `defineDatabaseMigrations()`, so a test that changed either would roll back the wrong tables and leak them into the next test on a persistent database |
| `breakdown` key order is normalised by the `TokenBreakdown` cast | MySQL's JSON type reorders keys |
| Factory states set only what they change; one `afterMaking` pass derives breakdown, percent used, usage, costs and dates | Any combination of states stays internally consistent (estimate = breakdown sum, costs follow tokens and the answering model's price) |
| Factory uses `random_int()` and fixed model data, not Faker | Faker is usually a dev dependency; apps can seed demo data anywhere |
| `outcome_reason` for failures is `ExceptionClass:status` (class alone when no status) | Exception class and HTTP status only, never the message; one string column, easy to group |
| Privacy is enforced at write time by model casts, not only by listener code | A listener bug or a future contributor cannot store text in `breakdown` or `outcome_reason`; tags remain the only free-form values and are allowlisted |
| Adding a ledger column means updating `tests/Feature/Privacy/SchemaPrivacyTest.php` | Forces a deliberate review that the column cannot hold text |
| Out-of-order safety comes from column ownership, not reads: each upsert writes only its event's columns, and `outcome` is set on insert only by `TokensCounted` | No read-then-write race between queue workers; a late count cannot downgrade `completed`, `warned`, `blocked` or `failed` |
| `TokensUsed` sets outcome with a second conditional update (`where outcome not in (warned, completed)`), not a `CASE` in the upsert | PostgreSQL rejects a bare column in `ON CONFLICT DO UPDATE` as ambiguous, and a table-qualified one needs non-literal raw SQL; two atomic statements need no raw SQL and settle the same in any order |
| Outcome precedence, same in any arrival order: usage -> `warned` if warned else `completed`; no usage -> `blocked` > `warned` > `failed` > `counted` | Warned implies answered; usage proves a blocked or failed row was answered after all (e.g. failover), and clears a failure reason. A warning outranks a failed attempt. Proven by a test that replays every ordering of each event mix on all four databases |
| Several warnings keep the highest-ranked limit in `LimitType::cases()` order | Deterministic reason regardless of arrival order |
| `outcome_reason` holds the `LimitType` value for warned/blocked, never `HeadroomLow`'s generated description | Codes only; the `ReasonCode` cast would reject prose |
| `RequestBlocked` writes the count it carries as insert-only | `TokensCounted` owns the count columns (e.g. `counted_at`); the block's copy only fills a row nothing else has created |
| Inline recording (`sync` connection, configured or the app default) reports failures instead of throwing; queued recording throws so the job retries | The ledger must never break the AI request that triggered it |
| `viaConnection()`/`viaQueue()` read config via `config()`, not injected services | Laravel calls them on an instance built with `newInstanceWithoutConstructor()` |
| Public ledger events read the row back only when something listens for them | No extra query per Headroom event for apps that do not use them |
| `LedgerEntryRecorded` fires per write, not once per request | Insert vs update cannot be told apart portably from an upsert; consumers get the current outcome each time |
| Upsert with no owned non-null columns uses `insertOrIgnore` | Laravel turns `upsert()` with an empty update list into a plain insert, which fails on an existing row |
| `streamed` is only known from `TokensUsed` | An abandoned stream never reports usage, so it stays `counted` with `streamed` false |
| Local PostgreSQL check: throwaway `php:8.4-cli` image with `pdo_pgsql` built from stdin, nothing in the repo | Host PHP has no `pdo_pgsql`; upsert SQL differs most on PostgreSQL |
| `percent_used` capped at 99,999.99 | A huge overflow (e.g. 50M tokens in an 8k window) would otherwise exceed `decimal(7,2)` |
| Query API is the only supported read path; returns final readonly value objects, never Eloquent models | Schema can change without breaking consumers (Filament plugin included) |
| Tags stored only for allowlisted keys, default empty | Nothing personal is recorded unless the app opts in; user IDs are personal data |
| Never store prompt or response text | Privacy; enforced by arch tests |
| `forgetTag()` deletes the tag rows, gives every affected run a fresh random `invocation_id` (all its steps, chunked 500 tags at a time), and keeps other tags and `request_id`; one transaction on the ledger connection; returns the entry count | Breaks the link to the app's logs and stored conversations while runs still group and totals stay correct; tenant-style tags usually are not personal data |
| `forgetTag()` also clears a matching `primary_tag_value` in daily rollups | Rollups keep only the primary tag, so erasure must reach them too; promised in `SECURITY.md` |
| Tag values are stored as strings (bools as `1`/`0`); a value over 191 characters is skipped and reported, not truncated; allowlist keys over 64 characters are ignored | Truncation would keep part of what is probably free text; the column limits are fixed |
| A tag that changes between events keeps the latest value written | One value per key per request (unique index); values normally match across a request's events |
| `LedgerEntries` is its own final readonly list (iterable, countable, `collect()` for a Laravel collection), not a `Collection` subclass | `Collection::map()` returns `static`, which would let a typed collection hold anything |
| A `LedgerEntry` cost is `Money` only when both an amount and a currency were recorded | Rows created by a warning or failure alone have no cost; null is honest, zero is not |
| Date filters are whole days on `occurred_on`, in the app timezone; `lastDays(n)` is today plus the n-1 days before | Same grain as daily rollups, so raw and rolled-up answers agree; uses the `occurred_on` index |
| `occurred_on` and rollup `date` stored as `Y-m-d` via the `DateOnly` cast | Laravel's date cast stores a midnight datetime; SQLite keeps it as text that sorts after the plain date, so a range lost its last day |
| `forModel()`/`forProvider()` match the model that answered, else the one counted | Same rule as `LedgerEntry::model()`; failover requests count against the model that actually ran |
| `blocked()` includes warned by default | Brief's "blocked or warned requests"; `blocked(includeWarned: false)` for blocked only |
| Grouping methods return a `GroupedQuery` rather than switching the result type of `spend()`/`tokens()` | Every method has one return type; filters go before grouping, as in the brief's examples |
| `spend()` returns `SpendSummary` with actual and estimate-only parts kept separate | "Actual where known, else estimated, flagged" without ever treating a request with no usage as zero; requests with neither usage nor an estimate are counted but carry no amount |
| `byDay()` with a date range fills days without requests (zero tokens, empty spend) | Chart-ready time series |
| Aggregates run in SQL, not in PHP over loaded rows | Scales with the ledger; booleans summed via `case when ... then 1 else 0 end` to stay portable |
| Accuracy uses rows with an estimate and reported usage where `model_used = model_counted`, grouped by counted model and count method; stores sums (samples, estimated, actual, absolute error) | Failover answers come from another tokeniser; sums are exactly what the rollup `accuracy_*` columns hold, so rollups must apply the same rule |
| `errorPercent()` is weighted (sum of absolute error / sum of actual); `biasPercent()` is signed, positive = under-estimate | A per-request average would let tiny requests dominate |
| Absolute error computed with `case when a >= b then a - b else b - a end` | MySQL errors when subtracting unsigned integers would go negative, so `abs(a - b)` is not portable |
| `LedgerQuery::rows()` is private; `GroupedQuery` receives a closure | A public `@internal` method still let consumers reach an Eloquent builder via `Ledger::query()->rows()`; the arch test fails if it is made public again (mutation-checked) |
| Scheduled rollups run `onOneServer()` | Each day is rebuilt as delete-then-insert, so concurrent runs on several servers could double a day's rows; needs a lock-capable cache (redis, memcached, database, dynamodb), to be noted in the README |
| `rollup.schedule` is a cron expression (validated with `CronExpression::isValidExpression`, invalid values throw), or false for the app to schedule `headroom-ledger:rollup` itself | Apps choose any frequency or their own scheduling; the README and docs site must say that with false they add their own cron/schedule entry |
| A run without options catches up every closed day since the last rollup (max 31 days), rebuilding the last rolled-up day again | Any schedule is safe (hourly to weekly), late entries are picked up, and an outage repairs itself; today is never rolled up by default |
| `{prefix}rollup_days` records each rolled-up day (even with no entries), its primary tag key, row count and build time; the builder locks its row for the rebuild | Without it an empty rolled-up day looks unrolled, and concurrent rebuilds on PostgreSQL could both insert (each delete misses the other's uncommitted rows); the lock is tested from a second connection on PostgreSQL, MySQL and MariaDB |
| Rollups are a snapshot: an entry arriving for a covered closed day is hidden until that day is rebuilt | Catch-up always rebuilds the last rolled-up day, which covers normal queue lag; older late arrivals need `--from`. Document in the README |
| Combined-query tests compare every aggregate with rollups on against `rollup.enabled = false`, seeded once per test | Proves equivalence (mutation-checked); seeding per case made MySQL/MariaDB runs take many minutes |
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
