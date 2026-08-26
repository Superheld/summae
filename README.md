# summae

Reusable accounting library: GoBD-compliant double-entry bookkeeping, cash-basis
accounting (EÜR), VAT, fixed assets and cost accounting (KLR) — as an **embeddable**
implementation, **not** an application. Multiple language implementations with an
**identical API and identical data format**, verified against a shared conformance suite.

```
summae/
├── testing/                Every test that is not a unit test
│   ├── testsuite/          The compatibility contract: fixtures/ + schema/
│   │                       (authoritative for all implementations)
│   └── scenarios/          Language-neutral CLI scenarios (walkthrough · regression)
├── implementations/
│   ├── php/                PHP implementation  (core · laravel · cli)
│   └── node/               Node/TypeScript implementation (core · knex · cli)
├── pack-library/           The shipped packs (default · de · us) — product data
├── docs/handbuch/          The handbook: the one user-facing document
├── compose.yaml, docker/   Docker toolchain (PHP)
└── Makefile                Orchestration
```

Both implementations run against the **same `testing/testsuite/`** and produce
byte-identical results — that is the heart of the promise.

## Installation

Embed the core in a project:

```bash
# PHP (Composer)
composer require superheld/summae-core
composer require superheld/summae-laravel   # optional Laravel adapter

# Node (npm/pnpm)
pnpm add @superheld/summae-core
```

Full guidance on configuration, initialization and usage:
**→ [Handbook](docs/handbuch/README.md)**.

### Package names across ecosystems

One product name (`summae`), each ecosystem's convention — the stem stays the
same, only the registry prefix differs:

| Role | PHP (Composer) | Node (npm) | Python (PyPI) |
|---|---|---|---|
| Core | `superheld/summae-core` | `@superheld/summae-core` | `summae-core` |
| CLI | `superheld/summae-cli` | `@superheld/summae-cli` | `summae-cli` |
| Framework adapter | `superheld/summae-laravel` | `@superheld/summae-nestjs` | `summae-django` |

The language lives in the folder (`implementations/<language>/`), not in the name.
Only the framework adapter is named per framework; core and CLI stay uniform.

## Implementations

| | Path | Status | Docs |
|---|---|---|---|
| PHP | `implementations/php/` | Reference, complete | [README](implementations/php/README.md) · [Developer docs](implementations/php/docs/README.md) |
| Node | `implementations/node/` | Complete, parity with PHP | [README](implementations/node/README.md) · [Developer docs](implementations/node/docs/README.md) |

**Users** (embed, configure and use the package) read the
[Handbook](docs/handbuch/README.md). **Contributors** start with the respective
developer docs.

## The compatibility contract (`testing/testsuite/`)

`testing/testsuite/fixtures/**.json` + `testing/testsuite/schema/` are the normative source:
every implementation must satisfy all fixtures byte-identically and
deterministically. Fixtures are **append-only** — a behavior change becomes a
new fixture; existing ones are never silently edited.

> **Maintainer note:** Fixtures are authored in `testing/testsuite/` itself. They used to be
> mirrored in from an external knowledge base; that base is now `knowledge/` in this repo, so
> the copy was retired along with its sync script. `pack-library/` followed on 2026-08-26 —
> nothing in this repository is a copy of something authored elsewhere any more.

## Quick test

```bash
# PHP (Docker, no local PHP required)
make build && make install
make check                     # PHPStan max + PHPUnit
make fixtures                  # conformance suite against the core

# Node
cd implementations/node && pnpm install
pnpm test                      # vitest (unit + conformance)
pnpm fixtures --strict         # conformance suite, deterministic double run
```

## License

MIT — see [LICENSE](LICENSE).
