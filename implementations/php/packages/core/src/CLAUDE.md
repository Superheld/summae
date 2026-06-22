# CLAUDE.md — `core/src/` (Architektur des Fachkerns)

Zwei **Achsen** — beide hier sichtbar halten. Struktur 1:1 identisch in PHP und Node
(dort lowercase-Ordner). Das große Bild + Baustatus: Root-`CLAUDE.md`.

## Achse 1 — Hexagonal (Framework-/Persistenz-Freiheit)

```
        ┌──────────── Adapters (außen) ────────────┐
        │   InMemory · [knex] · [laravel]           │
        │   ┌────────── Ports (Kante) ──────────┐   │
        │   │   ┌──────── Domäne (innen) ─────┐  │   │
        │   │   │  Substrate (frozen)          │  │   │
        │   │   │  Policies = SOCKEL           │  │   │
        │   │   │  Composition (Verdrahtung)   │  │   │
        │   │   └──────────────────────────────┘  │   │
        │   └────────────────────────────────────┘   │
        └────────────────────────────────────────────┘
  STECKER (Daten) liegen in /pack-library/ ──injiziert──▶ in die Sockel
  Abhängigkeit zeigt nur nach innen · Pack hängt am Kern, nie umgekehrt.
```

Echte Persistenz (`laravel`/`knex`) sind **eigene Pakete** außerhalb von `core`; in
`core` liegen nur die in-memory-Adapter (Fakes, `InMemory/`).

## Achse 2 — Substrat → Politiksorten → Pack (Jurisdiktions-Freiheit)

- **`Substrate/`** — eingefroren, jurisdiktionsfrei (Buchung Summe 0, Konto, Journal,
  Saldo, Periode). Wächst nicht. **Importiert nichts von oben.**
- **`Policies/`** — die DREI Politiksorten; hier nur der **Sockel** (gesetzesfreie Mechanik),
  die **Stecker** (Daten) liegen in `/pack-library/` und werden injiziert:
  - **`Expansion/`** — Absicht → ausbalancierte Buchungen (Tax · Assets · Costing · settle-Differenz · reverse)
  - **`Projection/`** — Journal → Sicht (Falt-Engines + Mappings)
  - **`Constraint/`** — Prädikat-Gates (noch dünn; dritte Sorte unfertig)
- **`Composition/`** — Resolver · Factory · Tenant · Dispatcher (Dependency Inversion)
- **`Records/`** — Belege/Records (Voucher · OpenItem · Audit), **keine** Politiksorte
- **`Partner/`** — Supporting-Subdomain (Stammdaten), **keine** Politiksorte
- **`Port/` · `InMemory/`** — Hexagon-Kante / -außen

## Ziel- vs. Ist-Struktur (ehrlich — sonst driftet die Doku)

Die Ordner oben sind das **Ziel**. **Heute liegt der Code noch flach** — also: **Datei suchen,
nicht den Zielordner annehmen.**

| heute | ≈ Ziel |
|---|---|
| `Shared/` | `Substrate/` |
| `Ledger/` | **gemischt**: Substrate + settle/reverse (→`Policies/Expansion/`) + period (→`Policies/Constraint/`) + Voucher/OpenItem/Audit (→`Records/`) |
| `Tax/` `Assets/` `Costing/` | `Policies/Expansion/` |
| `Mapping/` `Projection/` | `Policies/Projection/` |
| `Partner/` `Composition/` | bleiben (gleicher Name) |

Migration **in Scheiben**, Substrat zuerst; jeder Schritt grün (PHPStan/PHPUnit +
`fixtures` + `make cross`), PHP + Node 1:1.

## Gated — nicht mit Ordnern lösbar

- **In `Policies/Expansion/` sind Sockel und DE-Paradigma fusioniert**: `TaxService.php` verzweigt
  auf `reverse_charge`/`intra_community_supply`. Das zu trennen hängt an der offenen
  **closed/open**-Entscheidung (siehe „Zielmodell vs. Stand" unten). Der Ordner zeigt die
  Schicht, **nicht** die Naht darin.
- **`Ledger.php` fusioniert post+settle+reverse** — Split ist Chirurgie, kommt zuletzt.

## Engine-Bündel & Zielmodell vs. Stand

**Engine-Bündel:** Die Engine isst *ein* aufgelöstes `ruleModules`-Bündel (`profiles/chartsOfAccounts/taxCodes/
mappings/assetAccounts/depreciation/packPolicy`); dahin **inline** (Bündel direkt) oder **komponiert** (Manifest →
`PackResolver`). `packPolicy` parametrisiert jurisdiktionsfrei (`currencyScale`→`Currency`, `taxRoundingGranularity`→`TaxService`).

**Zielmodell vs. heutiger Stand (ehrlich — sonst driftet's):** Das Sockel/Stecker-Bild ist das **Ziel**. Heute
injiziert sind nur Infrastruktur-Ports (Clock/Id/Repositories) + das Bündel als *Daten*; die drei Politiksorten
sind **noch nicht** als Ports gebaut (`TaxService.php`/`AssetService.php` sind konkrete Klassen). **Offen
entschieden:** ob das Mechanik-Repertoire *abgeschlossen* ist (Kern wächst nie, Pack = nur Auswahl) oder *offen*
(wächst nur gesetzesfrei + sichtbar). Nicht raten.
