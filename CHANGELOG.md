# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - 2026-10-17

First release.

### Added

- Records every provider call that Headroom (`phoenix1331/laravel-ai-headroom` ^0.1) sees as one ledger row: the pre-flight estimate and per-part breakdown, the tokens actually used (input, output, cache read and cache write), estimated and actual cost, context window and percentage used, and whether it was completed, warned, blocked or failed. Starts recording once installed; no code changes beyond Headroom's own setup
- Listeners for `TokensCounted`, `TokensUsed`, `HeadroomLow`, `RequestBlocked` and `RequestFailed` that upsert by request ID, so the row settles the same whatever order queued listeners run in. Usage proves a request was answered, so a failed attempt followed by failover records as completed, against the model that actually answered
- Recording is queued by default (`queue.connection`, `queue.name`), with retries; with the `sync` connection it runs inline and reports failures instead of throwing, so the ledger never breaks the AI request that triggered it
- `LedgerEntryRecorded` and `LedgerEntryCompleted` events for alerts and other extensions, carrying the request ID, invocation ID and outcome
- Query API through the `Ledger` facade, returning readonly value objects rather than Eloquent models: `Ledger::query()` with `between()`, `lastDays()`, `forAgent()`, `forProvider()`, `forModel()`, `forTag()`, `nearLimit()`, `blocked()` and `withOutcome()` filters; `entries()`, `count()`, `spend()`, `tokens()`, `context()` and `accuracy()`; and `byModel()`, `byProvider()`, `byAgent()`, `byDay()`, `byRun()` and `byTag()` groupings
- `Ledger::find($requestId)` for one request and `Ledger::run($invocationId)` for every step of an agent run with totals
- Honest totals: `spend()` keeps reported cost and estimates for requests that never reported usage apart, flags any total that includes an estimate, and never treats a missing figure as zero. Every amount carries its currency, and different currencies are never added together. Money is held as integer millionths, so sub-cent costs are exact
- Estimate accuracy per model and count method: samples, mean absolute error, weighted error percentage and signed bias, from requests answered by the model that was counted
- Daily rollups: `php artisan headroom-ledger:rollup` builds them, catching up every closed day since the last run (up to 31 days), with `--date`, `--from` and `--to` for backfills. Aggregates read closed days from rollups and today from raw entries, giving the same answer as reading every entry. Scheduled on `rollup.schedule` (a cron expression, or `false` to schedule it yourself) on one server, and a day being rebuilt is locked so concurrent runs cannot double it
- Retention: `php artisan headroom-ledger:prune` deletes raw entries after `retention_days.entries` (default 90) and rollups after `retention_days.rollups` (default 730), rolling up any day it is about to prune first so its totals survive; `--pretend` shows the counts. Scheduled on `prune.schedule`. Entries, rollups and rolled-up days also support Laravel's `model:prune`
- `php artisan headroom-ledger:report` prints spend and tokens, context usage and limits, and estimate accuracy as tables, for `--since=7d` or a date, grouped `--by` model, provider, agent, day, run or `tag:<key>`; `--json` for one machine-readable document
- Context tags from `Headroom::resolveContextUsing()` stored only for keys on the `tags.allowed` list (empty by default), with an optional `primary_tag` kept in the daily rollups for fast per-tenant queries
- `Ledger::forgetTag($key, $value)` for data subject requests: deletes the tag everywhere, gives the affected agent runs new random invocation IDs, and clears it from rollups, keeping totals correct
- Privacy guards: no column can hold prompt, instruction, tool or response text; the token breakdown accepts only whole token counts and the outcome reason only a limit type or an exception class and HTTP status, never a message
- Publishable, database-agnostic migrations (`headroom-ledger-migrations`), tested on SQLite, PostgreSQL, MySQL and MariaDB, with an optional separate `connection` and `table_prefix`
- `LedgerEntryFactory` with states for every outcome (`counted`, `completed`, `warned`, `blocked`, `failed`, `streamed`, `abandoned`, `approximateCost`, `failover`, `inRun`) for seeding demos and testing apps and dashboards, with no Faker dependency

[Unreleased]: https://github.com/phoenix1331/laravel-ai-headroom-ledger/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/phoenix1331/laravel-ai-headroom-ledger/releases/tag/v0.1.0
