# CLAUDE.md — Node/TypeScript-Implementierung

Sprachspezifische Regeln und Befehle für `implementations/node/`. Projektweite Regeln
(Eiserne Invarianten, Qualitätsrichtlinie, `testsuite/` read-only, Git) stehen im
Root-`CLAUDE.md`.

**Stand:** Fachkern (`packages/core`) vollständig — 45/45 Fixtures grün gegen den
In-Memory-Port, Doppellauf deterministisch. Persistenz-Adapter + gleichsprachige CLI
sind **M4 (offen)**.

## Befehle

pnpm-Workspace, lokal (kein Docker nötig):

```bash
pnpm install
pnpm test          # vitest (Unit + Konformitäts-Test)
pnpm test:watch    # vitest im Watch-Modus
pnpm typecheck     # tsc --noEmit
pnpm lint          # eslint
pnpm build         # tsup pro Package (ESM + CJS + .d.ts)
pnpm fixtures      # Konformitäts-Runner (tsx); --strict = Doppellauf byte-identisch
```

## Konventionen

- Node ≥ 22, ESM (`"type": "module"`), pnpm-Workspace.
- TypeScript `strict` inkl. `noUncheckedIndexedAccess`, `exactOptionalPropertyTypes`,
  `verbatimModuleSyntax` (`tsconfig.base.json`) — **nicht aufweichen.**
- **Geld nie als `number`** → `big.js` (Money), gleiche half-up-Regel wie PHP
  (von Null weg, *kein* banker's rounding).
- **Kern framework-frei:** in `packages/core/**` keine Web-/DB-Frameworks — eslint
  `no-restricted-imports` erzwingt das. Strukturelles Pendant zu „kein
  `use Illuminate\…` im Core". Adapter werden eigene Pakete (ab M4).
- Tests mit **vitest**; Determinismus wie projektweit gefordert (injizierbare
  Clock/IdGenerator, Runner nutzt `FixedClock` + `DeterministicIdGenerator`).
- Bewusst ungenutzte Bindungen mit `_`-Präfix kennzeichnen.

## Definition of Green (hier)

`pnpm typecheck` + `pnpm lint` sauber (Pendant zu „PHPStan level max") · `pnpm test`
grün · `pnpm fixtures --strict` (alle Fixtures grün + byte-identischer Doppellauf).

## Publish

`@superheld/summae-core` liegt auf npm. Dev-Exports zeigen auf die TS-Source
(vitest/tsx ohne Build); `publishConfig` schaltet beim Packen auf `dist/`.
Release-Ablauf: `RELEASING.md` (Repo-Root).
