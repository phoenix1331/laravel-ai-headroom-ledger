# Contributing to Headroom Ledger

Thanks for helping. Bug reports, new report groupings and database compatibility fixes are all welcome.

Questions are best asked in [GitHub Discussions](https://github.com/phoenix1331/laravel-ai-headroom-ledger/discussions) rather than issues.

## Setup

Prerequisites: PHP 8.3+ with `mbstring` and `pdo_sqlite`, Composer 2, Node and npm. `osv-scanner` is required by the pre-commit hook (`go install github.com/google/osv-scanner/cmd/osv-scanner@latest`).

```bash
git clone https://github.com/phoenix1331/laravel-ai-headroom-ledger
cd laravel-ai-headroom-ledger
composer install
npm install   # activates the git hooks
```

## Running checks

| Command | What it does |
|---|---|
| `composer test` | Unit and feature tests on in-memory SQLite |
| `composer test:coverage` | Tests with coverage; fails below 90% on `src/` (needs `pcov` or `xdebug`) |
| `composer lint` | Fixes code style with Pint |
| `composer lint:test` | Checks code style without changing files |
| `composer analyse` | Larastan at max level |
| `composer check` | `lint`, `analyse` and `test:coverage` in order |

The tests use Pest with Orchestra Testbench, so no database server needs to be running. Every PHP file declares `strict_types`; Pint adds it if you forget.

The base `TestCase` calls `Http::preventStrayRequests()`. The ledger never talks to an AI provider, so any HTTP request in a test is a bug.

### Other databases

CI also runs the suite against PostgreSQL, MySQL and MariaDB. To reproduce a failure locally, point the tests at any database you have running; the `DB_*` variables override the SQLite defaults in `phpunit.xml`:

```bash
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=testing DB_USERNAME=root DB_PASSWORD=password composer test
```

## Git hooks

`npm install` installs two Husky hooks:

- `commit-msg` - commit messages must be entirely lowercase and follow [Conventional Commits](https://www.conventionalcommits.org/) without a scope, e.g. `feat: add report grouping by provider`. `chore(deps): ...` is rejected
- `pre-commit` - runs `pint --test` and an `osv-scanner` audit of `composer.lock`

## Ground rules

These protect users' data and anyone building on the ledger, so pull requests that break them cannot be merged.

| Rule | Why |
|---|---|
| Never store prompt, instruction, tool or response text | The ledger holds numbers, model names, agent class names and allowlisted tags only. An arch test fails if a column could hold text |
| Read through the query API only | Consumers, including `filament-headroom`, never touch the tables or Eloquent models, so the schema can change without breaking them |
| Query API results are final readonly value objects | No Eloquent model escapes the public API |
| Migrations stay database-agnostic | No MySQL-specific or PostgreSQL-specific column types, raw SQL or date functions. Everything must pass on SQLite, PostgreSQL, MySQL and MariaDB |
| Money is integer micro-units plus a currency | Sub-cent costs survive and sums stay exact. Never sum across currencies |
| Rows without `TokensUsed` are estimate-only | Blocked, counted-only and abandoned requests are flagged, never treated as zero usage |

## Adding a report grouping

Groupings (`--by=agent`, `--by=model` and so on) are a good first contribution.

1. Add the grouping method to the query builder, e.g. `byProvider()`, reading rollups where the column exists there and raw entries otherwise.
2. Add the `--by` value to `headroom-ledger:report`.
3. Test it with `LedgerEntryFactory` data: table output, `--json` output and an empty range.
4. Document it in `README.md` and on the docs site (`docs/index.html`).
5. Add a changelog line under `## [Unreleased]`.

## Changing the schema

- Add a new migration rather than editing a released one; apps have already run the old one
- Keep it portable (see ground rules) and give indexes short explicit names, since MySQL limits them to 64 characters and `table_prefix` adds to the length
- Update `LedgerEntryFactory` and any rollup columns affected
- Note it in `CHANGELOG.md`, and in `UPGRADING.md` if apps need to publish and run anything

## Changelog

Every user-facing change adds a line under `## [Unreleased]` in `CHANGELOG.md`, in the same pull request, using the [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) headings (`Added`, `Changed`, `Fixed`, `Removed`, `Deprecated`, `Security`). Internal refactors and test-only changes do not need an entry.

## Pull requests

- Keep each pull request to one change
- Include tests; coverage must stay at or above 90%
- `composer lint:test` and `composer analyse` must pass
- Fill in the pull request checklist

Releases are covered in [RELEASING.md](RELEASING.md).
