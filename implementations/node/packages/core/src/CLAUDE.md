# CLAUDE.md — `core/src/` (Architektur des Fachkerns)

Zwei **Achsen** — beide hier sichtbar halten. Struktur 1:1 identisch in PHP und Node
(dort PascalCase-Ordner). Das große Bild + Baustatus: Root-`CLAUDE.md`.

## Achse 1 — Hexagonal (Framework-/Persistenz-Freiheit)

```
        ┌──────────── adapters (außen) ────────────┐
        │   in-memory · [knex] · [laravel]          │
        │   ┌────────── ports (Kante) ──────────┐   │
        │   │   ┌──────── Domäne (innen) ─────┐  │   │
        │   │   │  substrate (frozen)          │  │   │
        │   │   │  policies = SOCKEL           │  │   │
        │   │   │  composition (Verdrahtung)   │  │   │
        │   │   └──────────────────────────────┘  │   │
        │   └────────────────────────────────────┘   │
        └────────────────────────────────────────────┘
  STECKER (Daten) liegen in /pack-library/ ──injiziert──▶ in die Sockel
  Abhängigkeit zeigt nur nach innen · Pack hängt am Kern, nie umgekehrt.
```

Echte Persistenz (`knex`/`laravel`) sind **eigene Pakete** außerhalb von `core`; in
`core` liegen nur die in-memory-Adapter (Fakes).

## Achse 2 — Substrat → Politiksorten → Pack (Jurisdiktions-Freiheit)

- **`substrate/`** — eingefroren, jurisdiktionsfrei (Buchung Summe 0, Konto, Journal,
  Saldo, Periode). Wächst nicht. **Importiert nichts von oben.**
- **`policies/`** — die DREI Politiksorten; hier nur der **Sockel** (gesetzesfreie Mechanik),
  die **Stecker** (Daten) liegen in `/pack-library/` und werden injiziert:
  - **`expansion/`** — Absicht → ausbalancierte Buchungen (tax · assets · costing · settle-Differenz · reverse)
  - **`projection/`** — Journal → Sicht (Falt-Engines + mappings)
  - **`constraint/`** — Prädikat-Gates (noch dünn; dritte Sorte unfertig)
- **`composition/`** — Resolver · Factory · Tenant · Dispatcher (Dependency Inversion)
- **`records/`** — Belege/Records (voucher · open-item · audit), **keine** Politiksorte
- **`partner/`** — Supporting-Subdomain (Stammdaten), **keine** Politiksorte
- **`ports/` · `adapters/`** — Hexagon-Kante / -außen

## Ziel- vs. Ist-Struktur (ehrlich — sonst driftet die Doku)

Die Ordner oben sind das **Ziel**. **Heute liegt der Code noch flach** — also: **Datei suchen,
nicht den Zielordner annehmen.**

| heute | ≈ Ziel |
|---|---|
| `shared/` | `substrate/` |
| `ledger/` | **gemischt**: substrate + settle/reverse (→`policies/expansion/`) + period (→`policies/constraint/`) + voucher/open-item/audit (→`records/`) |
| `tax/` `assets/` `costing/` | `policies/expansion/` |
| `mapping/` `projection/` | `policies/projection/` |
| `partner/` `composition/` | bleiben (gleicher Name) |

Migration **in Scheiben**, Substrat zuerst; jeder Schritt grün (typecheck/lint/test +
`fixtures --strict` + `make cross`), PHP + Node 1:1.

## Gated — nicht mit Ordnern lösbar

- **In `policies/expansion/` sind Sockel und DE-Paradigma fusioniert**: `tax-service.ts` verzweigt
  auf `reverse_charge`/`intra_community_supply`. Das zu trennen hängt an der offenen
  **closed/open**-Entscheidung (siehe „Zielmodell vs. Stand" unten). Der Ordner zeigt die
  Schicht, **nicht** die Naht darin.
- **`ledger.ts` fusioniert post+settle+reverse** — Split ist Chirurgie, kommt zuletzt.

## Engine-Bündel & Zielmodell vs. Stand

**Engine-Bündel:** Die Engine isst *ein* aufgelöstes `ruleModules`-Bündel (`profiles/chartsOfAccounts/taxCodes/
mappings/assetAccounts/depreciation/packPolicy`); dahin **inline** (Bündel direkt) oder **komponiert** (Manifest →
`PackResolver`). `packPolicy` parametrisiert jurisdiktionsfrei (`currencyScale`→`Currency`, `taxRoundingGranularity`→`TaxService`).

**Zielmodell vs. heutiger Stand (ehrlich — sonst driftet's):** Das Sockel/Stecker-Bild ist das **Ziel**. Heute
injiziert sind nur Infrastruktur-Ports (Clock/Id/Repositories) + das Bündel als *Daten*; die drei Politiksorten
sind **noch nicht** als Ports gebaut (`tax-service.ts`/`asset-service.ts` sind konkrete Klassen). **Offen
entschieden:** ob das Mechanik-Repertoire *abgeschlossen* ist (Kern wächst nie, Pack = nur Auswahl) oder *offen*
(wächst nur gesetzesfrei + sichtbar). Nicht raten.
