# Development

## Setup

Everything runs in Docker — **no local PHP** needed.

```bash
make build      # build the PHP 8.3 image (once; bcmath + pdo_pgsql + pcov)
make install    # composer install
make check      # PHPStan (level max) + PHPUnit — this is what CI checks too
make fixtures   # conformance suite against the in-memory core
make shell      # shell in the container
make sync       # update testsuite + schema from the knowledge base
```

Postgres is only needed for the database conformance run:

```bash
docker compose --profile db up -d postgres
docker compose --profile db run --rm -e SUMMAE_DB_DRIVER=pgsql -e SUMMAE_DB_HOST=postgres \
  php php runner/bin/run-fixtures.php --strict --subject=database
```

## What must be green (= CI)

- **PHPStan level max**, no errors (`vendor/bin/phpstan analyse`).
- **PHPUnit** (`vendor/bin/phpunit`), including the `ConformanceTest` over the
  full suite, **plus the coverage gate**: core lines ≥ 88 %, enforced by
  `runner/bin/coverage-gate.php` (pcov is in the image). `make test` runs all of
  this.
- **Conformance suite strict** against both subjects:
  `php runner/bin/run-fixtures.php --strict` and `--strict --subject=database`
  — all fixtures green **and** the double run byte-identical.

The `expected-green.txt` in `runner/` is the regression guard: without
`--strict`, nothing listed there may go red.

## Conventions

- **PSR-12**, PHP ≥ 8.3, `declare(strict_types=1)` everywhere.
- **PHPStan level max** is non-negotiable — no `@phpstan-ignore` without a
  justifying comment.
- **No `use Illuminate\…` in `packages/core`.** (See architektur.md.)
- **Namespace `Summae\…`** (domain), independent of the Composer vendor.
- **Money never as float**, dates never as `DateTime` with time for the posting
  date (zoneless `CalendarDate`). See konformitaet.md.
- German-language comments/docs, English API/class names (from the glossary of
  the knowledge base).

## Branch & commit workflow

- **Never directly on `main`.** One branch per task (`job/…`, `chore/…`, `fix/…`).
- One commit per completed unit; the commit message names the job/topic ID and
  what changed in domain terms (not just "WIP").
- Merge to `main` with `--no-ff` once the unit is green.

## Adding a new operation / projection

1. **Read the model docs in the knowledge base** (`40-domaenenmodell/…`,
   `50-spezifikation/…`) — fresh, the spec is alive.
2. Build the domain logic in `packages/core` (aggregate/service/projection),
   against the in-memory port and with unit tests.
3. Wire it into the `TenantOperations` dispatcher (one place for CLI + runner).
4. If new database persistence is needed: add a port + in-memory **and**
   database adapter, extend the `SchemaInstaller`.
5. `make check` + `make fixtures` (both subjects) green.

## A spec change comes in (retrofit)

1. `make sync` — fetch new/changed fixtures + schema.
2. `make fixtures` — see what goes red (controlled failure, no crash).
3. Read the knowledge-base spec files fresh (not from memory).
4. Adjust until green; on a contradiction between spec/fixture →
   [`SPEC-FINDINGS.md`](../SPEC-FINDINGS.md), do not bend the fixture.

## Determinism hooks (important for testing)

`Clock` and `IdGenerator` are injectable. The conformance runner uses
`FixedClock` + `DeterministicIdGenerator` (a counter instead of randomness), so
the double run including SHA-256 stream hashes is byte-identical. Production
uses `SystemClock` + `UuidV7IdGenerator`. Never write tests against
`now()`/randomness.
