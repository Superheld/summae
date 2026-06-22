# summae — PHP reference implementation

PHP reference implementation of summae: GoBD-compliant double-entry, cash-basis
accounting (EÜR), VAT, fixed assets and cost accounting — as an embeddable
library. From a user's perspective a Composer package
(`composer require superheld/summae-core`), optionally with the Laravel adapter
(`composer require superheld/summae-laravel`).

The normative source is the conformance suite (`testsuite/` at the repo root): every
implementation must satisfy all fixtures byte-identically and deterministically.

## Documentation

- **Users** (embed, configure, use the package): the
  [handbook](../../docs/handbuch/README.md), complemented by the package READMEs —
  [packages/laravel/README.md](packages/laravel/README.md),
  [packages/cli/README.md](packages/cli/README.md).
- **Contributors** (architecture, workflow, conformance): [docs/](docs/README.md).

## Structure

| Path | Contents |
|---|---|
| `packages/core/` | `superheld/summae-core` — framework-free accounting core (PHP ≥ 8.3, only dependency: brick/math) |
| `packages/laravel/` | `superheld/summae-laravel` — ServiceProvider, database adapter, migrations |
| `packages/cli/` | `superheld/summae-cli` — CLI, JSON output |
| `runner/` | fixture runner for the conformance suite |
| `testsuite/` | copy of the conformance fixtures — **read-only** (maintainer: `make sync`) |
| `SPEC-FINDINGS.md` | findings against spec/fixtures (escalation path) |

## Development

Everything runs in Docker, no local PHP needed:

```bash
make build      # build the PHP 8.3 image (once)
make install    # composer install
make check      # PHPStan (level max) + PHPUnit — exactly what CI checks
make sync       # (maintainer) update the testsuite from the internal source
make shell      # shell in the container
```

Postgres is only needed from JOB-012 on: `docker compose --profile db up -d`
(port 54329, user/DB/password: `rechnungswesen`).

## Iron rules

1. **Fixtures are never edited.** Contradiction found → `SPEC-FINDINGS.md`.
2. **The core stays framework-free.** No `Illuminate\*` in `packages/core`.
3. **Journal append-only, balances are projections.** Never store a balance.
4. **Money is never a float.** `Money` on brick/math, half-up, allocate largest-remainder.
5. Names come from the glossary (EN column), error codes from the error catalog.
