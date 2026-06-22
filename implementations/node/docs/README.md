# docs/ — Node/TypeScript

In-depth documentation for the Node implementation. Commands/conventions at a glance are in
[`../CLAUDE.md`](../CLAUDE.md); project-wide rules in the repo-root `CLAUDE.md`.

- [`architektur.md`](architektur.md) — packages (`core`/`knex`/`cli`), framework-free core,
  ports & adapters, dispatcher, the data flow of a posting. **Node-specific**; the
  language-neutral mental model is in [`/docs/architektur.md`](../../../docs/architektur.md).
- [`entwicklung.md`](entwicklung.md) — setup (pnpm), what must be green (= CI, incl. coverage thresholds),
  conventions, branch/commit workflow, "adding a new operation/projection", spec retrofit, determinism hooks.
- [`konformitaet.md`](konformitaet.md) — the compatibility contract, how the runner works,
  the most common cross-impl pitfalls, the SPEC-FINDINGS escalation path.
- [`../SPEC-FINDINGS.md`](../SPEC-FINDINGS.md) — documented contradictions between spec/fixture/model (NF-…).

> Build patterns + "new operation = service + one `case` + fixture" are **language-neutral** and live
> once in the root `CLAUDE.md` (section "Bau-Konventionen"). Only the Node idioms are here.
