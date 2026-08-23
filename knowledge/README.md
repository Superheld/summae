# knowledge/ — where summae is thought out

The authoring side of the project: domain knowledge, requirements, the domain model, the
specification, build reports and pack documentation. Written for whoever *builds* summae —
human or agent.

Not to be confused with [`../docs/`](../docs/), which is written for whoever *uses* it
(handbook, architecture overview, GoBD conformance). Same repository, different audience: if
a page answers "how do I post an invoice", it belongs in `docs/`; if it answers "why does
posting work this way", it belongs here.

## Layout

| Folder | What is in it |
|---|---|
| `10-fachwissen/` | Accounting domain knowledge — the subject, independent of summae |
| `20-glossar/` | Terminology, German ↔ English |
| `30-anforderungen/` | Requirements: functional (`F-…`), non-functional (`NF-…`), standard cases (`SF-…`), out of scope |
| `40-domaenenmodell/` | The model: ledger, tax, assets, costing, jurisdiction profile, open questions |
| `50-spezifikation/` | The normative spec in prose: API, data format, determinism, guard rails |
| `80-implementierung/` | Build reports, runtime guide, pack composition, spec findings |
| `99-pack-docs/` | Per-pack documentation (`de-pack`, `us-pack`) |

## Two things that are deliberately *not* here

**The machine-readable spec lives with the tests.** `format.schema.json`,
`api-parameters.json` and `fehlerkatalog.md` are in
[`../testing/testsuite/`](../testing/testsuite/), next to the fixtures that exercise them and
the tests that validate against them. They used to sit under `50-spezifikation/` and be
copied into the test tree by a sync script; one copy is better than two that agree by
convention. The prose spec here points at them; they never point back.

**Project and strategy material is not in this repository.** Roadmaps, the decision log,
governance drafts and the competitive audit stay in the knowledge base outside the repo
(`00-projekt/`). This repository is public — that material is not.

## How this relates to the rest of the repo

- Requirements here are cited by fixtures: a fixture's `covers` field names the `F-…` IDs it
  proves. A requirement without a fixture is a gate gap, not a finished requirement.
- [`../docs/gobd-conformance.md`](../docs/gobd-conformance.md) joins the three: GoBD
  obligation → requirement → the test that proves it.
- ID namespaces (`F-…`, `NF-…`, `SF-…` for requirements; `SPEC-…`, `IMPL-…` for findings) are
  defined in the root `CLAUDE.md`. They are disjoint by prefix, never by padding.

> **Language:** this folder is still German, including the folder names, while everything
> shipped is English (convention since 2026-06-23). Translating it is its own job and has not
> been done — noted here so nobody mistakes it for an oversight.
