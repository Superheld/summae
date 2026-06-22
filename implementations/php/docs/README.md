# Developer Documentation

Docs **for co-developers** of `rechnungswesen-php` — not for users of the
package (they find everything in the package READMEs under `packages/*/README.md`).

| Document | Content |
|---|---|
| [architektur.md](architektur.md) | Layers, packages, ports & adapters, data flow — *why* it is cut this way |
| [entwicklung.md](entwicklung.md) | Setup (Docker), tests/PHPStan/fixtures, branch/job workflow, conventions |
| [konformitaet.md](konformitaet.md) | The compatibility contract: fixture suite, determinism, SPEC-FINDINGS |

## The most important rule first

The **normative source** is not this code but the knowledge base
(sister repo "Rechnungswesen"): specification, domain model, and the
conformance fixtures. This implementation is *conformant* when all fixtures are
green. Fixtures are **never edited here** — contradictions go to
[`SPEC-FINDINGS.md`](../SPEC-FINDINGS.md) and flow back through the knowledge
base (see [konformitaet.md](konformitaet.md)).

## Quick start for newcomers

1. Read `packages/core/` — the framework-free core, where all domain logic lives.
2. `docs/architektur.md` — how core / laravel / cli fit together.
3. Run `make check` (Docker) — PHPStan + tests must be green.
4. `make fixtures` — the conformance suite against the core.
