# docs/ — Node/TypeScript

Tiefen-Doku zur Node-Implementierung. Befehle/Konventionen im Überblick stehen in
[`../CLAUDE.md`](../CLAUDE.md); projektweite Regeln im Repo-Root-`CLAUDE.md`.

- [`architektur.md`](architektur.md) — Pakete (`core`/`knex`/`cli`), framework-freier Kern,
  Ports & Adapter, Dispatcher, Datenfluss einer Buchung. **Node-spezifisch**; das
  sprachneutrale Denkmodell steht in [`/docs/architektur.md`](../../../docs/architektur.md).
- [`entwicklung.md`](entwicklung.md) — Setup (pnpm), was grün sein muss (= CI), Konventionen,
  Branch-/Commit-Workflow, „neue Operation/Projektion anbauen", Spec-Retrofit, Determinismus-Hooks.
- [`konformitaet.md`](konformitaet.md) — der Kompatibilitätsvertrag, wie der Runner arbeitet,
  die häufigsten Cross-Impl-Fallen, der SPEC-FINDINGS-Eskalationsweg.
- [`../SPEC-FINDINGS.md`](../SPEC-FINDINGS.md) — dokumentierte Widersprüche Spec/Fixture/Modell (NF-…).

> Bau-Patterns + „neue Operation = Service + ein `case` + Fixture" sind **sprachneutral** und stehen
> einmal im Root-`CLAUDE.md` (Sektion „Bau-Konventionen"). Hier nur die Node-Idiome.
