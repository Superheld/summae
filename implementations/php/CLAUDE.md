# CLAUDE.md — PHP implementation

Language-specific rules and commands for `implementations/php/`. Project-wide rules
(iron invariants, quality policy, `testing/testsuite/` read-only, Git) are in the
root `CLAUDE.md`.

## Commands

Everything runs in Docker — **no local PHP needed.** Make targets (in the repo root)
are the orchestration:

```bash
make build      # build PHP 8.3 image (once)
make install    # composer install
make check      # PHPStan (level max) + PHPUnit — exactly what CI checks
make fixtures   # conformance suite against the in-memory core
make test       # PHPUnit only
make stan       # PHPStan level max only
make sync       # mirror testing/testsuite/ from the knowledge base (one-way)
make shell      # shell in the PHP container
```

Behind `make` sits `docker compose run --rm php …`. More direct control
(working dir `/app/implementations/php`):

```bash
# Single test / one suite (suites: core, laravel, cli, runner)
docker compose run --rm php vendor/bin/phpunit --testsuite core
docker compose run --rm php vendor/bin/phpunit --filter MoneyTest

# Conformance runner
docker compose run --rm php php runner/bin/run-fixtures.php \
  --strict --subject=core|database --filter=<name> --expected=<file>
```

`--strict` = all fixtures green **and** suite double run byte-identical.
`runner/expected-green.txt` = regression guard (without `--strict` nothing listed
there may go red). The database subject needs Postgres:

```bash
docker compose --profile db up -d postgres
docker compose --profile db run --rm -e SUMMAE_DB_DRIVER=pgsql -e SUMMAE_DB_HOST=postgres \
  php php runner/bin/run-fixtures.php --strict --subject=database
```

## Conventions

- PHP ≥ 8.3, PSR-12, `declare(strict_types=1)` everywhere.
- PHPStan **level max** is non-negotiable (no `@phpstan-ignore` without a
  justifying comment).
- PHP namespace `Summae\…` (e.g. `Summae\Core\Ledger`), independent of the
  Composer vendor `superheld/`.
- **Pack composition:** resolver `packages/core/src/Composition/PackResolver.php`; loader (reads the
  shipped `pack-library/`) `runner/src/PackLibrary.php`. **Reference** modules/manifests,
  do not duplicate them inline.

## Definition of Green (here)

PHPStan level max without errors · `make test` green (**PHPUnit incl. `ConformanceTest`**
over the full suite **+ coverage gate per package** via `coverage-gate.php`: lines core 91 /
cli 87 / laravel 98 / runner 82) · conformance suite `--strict` against **both** subjects
(`core` and `database`) incl. byte-identical double run.

**`packages/laravel` has its own suite** since 2026-08-16 (NF-015): `AdapterTestCase` (in-memory
SQLite + `summae_*` schema) · `RepositoryRoundTripTest` (write with one tenant instance, read with a
second — everything asserted has been through a column) · `TenantScopingTest` (two tenants on one
database) · `HydratorAndSchemaTest` · `ServiceProviderTest` (the Laravel entry point on
`orchestra/testbench`: singleton binding, merged config, `artisan migrate` really creating the
`summae_*` tables, publishable config). Still run
`--subject=database` and `make cross` after touching the adapter: they cover the Postgres dialect
and the shared-database format, which the SQLite suite cannot.

`make test` includes the **walkthrough scenarios** (`packages/cli/tests/WalkthroughTest.php`),
which drive the CLI through a full lifecycle per shipped configuration from the shared
`testing/scenarios/walkthrough/*.json`, plus the regression guards in `testing/scenarios/regression/` — the same
files the Node `walkthrough.test.ts` reads. Test landscape: `testing/README.md`.

## Deeper: `docs/`

- `docs/architektur.md` — three packages, framework-free core, hexagonal/ports,
  domain layers, `TenantOperations` as the entry point, data flow of a posting.
- `docs/entwicklung.md` — setup, what CI checks, conventions, branch/commit workflow,
  „adding a new operation/projection", spec retrofit, determinism hooks.
- `docs/konformitaet.md` — the compatibility contract, how the runner works,
  the most common cross-impl pitfalls, the SPEC-FINDINGS escalation path.
- `SPEC-FINDINGS.md` — documented contradictions between spec/fixture/model.

The **language-neutral build principles** (pack = primarily data/plug, 1:1 mirroring, test-driven,
framework-free) are in the root `CLAUDE.md`; patterns list in `docs/architektur.md`, the recipe
„new operation = service + `case` + fixture" in `docs/entwicklung.md` — here only the PHP idioms.
