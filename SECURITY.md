# Security Policy

## Supported Versions

| Version | Supported |
|---------|-----------|
| 0.x     | Latest minor only |

While Headroom Ledger is pre-1.0, fixes are released on the latest minor version only.

## Reporting a Vulnerability

Please do not open a public GitHub issue for security vulnerabilities.

Report security issues by emailing **darren@phoenix1331.co.uk**. Include:

- A description of the vulnerability and its potential impact
- Steps to reproduce or a proof-of-concept
- The package version affected, plus your Headroom version and database type and version

You will receive an acknowledgement within 48 hours. If the issue is confirmed, a fix will be released as soon as possible and you will be credited in the changelog unless you prefer to remain anonymous.

## Scope

Headroom Ledger writes a row to your database for every AI provider call that Headroom sees. It never sends data anywhere else: there are no network calls, telemetry or exporters.

### What is stored

| Stored | Not stored |
|---|---|
| Request ID (ULID) and SDK invocation ID | Prompt, instruction or conversation text |
| Agent class name | Tool definitions, tool arguments or tool results |
| Provider and model names, counted and used | Response text |
| Token counts: estimated, input, output, cached; per-section breakdown (numbers only) | Attachments or file names |
| Context window, percent used | Exception messages (provider errors can echo prompt content) |
| Estimated and actual cost, currency, approximate flag | API keys or request headers |
| Outcome (`counted`, `completed`, `warned`, `blocked`, `failed`); for failures, the exception class and HTTP status only | Tags whose key is not in the allowlist |
| Context tags whose key is in `tags.allowed` | |
| Timestamps | |

An architecture test fails the build if any ledger column or model attribute could hold prompt or response text.

### Potential concerns

**Tags can be personal data** - Tags come from your `Headroom::resolveContextUsing()` callback. The ledger stores only keys listed in `tags.allowed`, which is empty by default, so nothing is recorded unless you opt in. A `user_id` tag is personal data under the UK GDPR and similar laws: record it only if you need it, and include the ledger tables in your data protection records.

**Erasure requests** - `Ledger::forgetTag('user_id', 123)` removes that tag from every entry, so the remaining rows are numbers that no longer identify the person. Daily rollups only keep the optional `primary_tag`; if that is a personal identifier, `forgetTag()` covers the rollups too.

**Retention** - Raw entries and rollups are pruned after `retention_days.entries` and `retention_days.rollups`. Schedule `headroom-ledger:prune` (or Laravel's `model:prune`) and set retention to the shortest period your reporting needs.

**Database access** - Ledger tables reveal usage patterns, spend and, through tags, which tenants or users made requests. Use the `connection` option to keep them in a separate database with its own credentials if your threat model needs it.

**Queued listeners** - In the default queued mode, Headroom's event payloads (numbers, names, IDs and tags, never text) pass through your queue backend. Protect it as you would any other queue.

**Dependency chain** - Headroom Ledger depends on Headroom and `laravel/ai`. Keep your `composer.lock` up to date and use a tool like `osv-scanner` or `composer audit` to monitor for vulnerabilities in the dependency chain.

## Disclosure Policy

Once a fix is released, the vulnerability will be documented in `CHANGELOG.md` under the relevant version. We follow coordinated disclosure and will not publish details until a fix is available.
