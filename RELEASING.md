# Releasing

Headroom Ledger follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html). Before 1.0, breaking changes bump the minor version (`0.1.x` to `0.2.0`) and are called out in the changelog.

The ledger stays on 0.x until `filament-headroom` has been built against it. The schema and query API become public API at 1.0, and the dashboard is what proves them.

## Steps

1. Make sure `main` is green: tests on every database, static analysis and the package contents check.
2. In `CHANGELOG.md`, rename `## [Unreleased]` to `## [X.Y.Z] - YYYY-MM-DD`, add a fresh empty `## [Unreleased]` above it, and update the links at the bottom:

   ```markdown
   [Unreleased]: https://github.com/phoenix1331/laravel-ai-headroom-ledger/compare/vX.Y.Z...HEAD
   [X.Y.Z]: https://github.com/phoenix1331/laravel-ai-headroom-ledger/releases/tag/vX.Y.Z
   ```
3. If the release contains a breaking change or a new migration, add an entry to `UPGRADING.md` (create it on the first breaking change).
4. Check the `phoenix1331/laravel-ai-headroom` constraint in `composer.json` still matches the Headroom version the ledger was tested against.
5. Commit: `chore: release vX.Y.Z`.
6. Tag and push:

   ```bash
   git tag vX.Y.Z
   git push origin main vX.Y.Z
   ```

7. The `release` workflow creates the GitHub release using the matching `CHANGELOG.md` section as its notes. It fails if that section is missing.
8. Packagist picks up the tag through the GitHub webhook. Check the new version appears at <https://packagist.org/packages/phoenix1331/laravel-ai-headroom-ledger>.

## First release only

- The README is written and the GitHub Pages site (`docs/index.html`) is published from `/docs` on `main`.
- The repository description and topics are set on GitHub.
- The package is submitted at <https://packagist.org/packages/submit> and the GitHub webhook is connected, so later tags publish automatically.

## Before tagging 1.0.0

- `filament-headroom` works against the ledger using only the query API, and any gaps it exposed are fixed.
- The public API (`Ledger` facade, query builder, value objects, events, artisan commands, config keys) is documented and has not changed for at least one minor release.
- The migrations have not changed for at least one minor release.
