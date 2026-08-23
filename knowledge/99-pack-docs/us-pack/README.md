# US-Pack — `us-complete`

Baufertige Beschreibung des USA-Packs. Eine Datei je Modul, jede konkret genug,
dass der Build daraus direkt das Modul-JSON (`modules/<kind>/<id>.json`) schreiben kann.

> **Status:** Spezifikation/Beschreibung (kein Build-Artefakt). Normativer Format-Vertrag:
> `50-spezifikation/datenformat.md` § v0.6. Aufgebaut **analog zum `/de-pack`** — gleiche
> Modulsorten, gleiche Manifest-Mechanik; die Unterschiede liegen ausschließlich in der
> **Fachlogik** (Sales/Use Tax statt USt, US-GAAP statt HGB, MACRS/De-minimis statt AfA/GWG).

## Grundprinzip: das US-Pack ist eigenständig

Der **Kern enthält nichts Festes** — keine Konten, keine Steuersätze, keine Kennzahlen.
Alles kommt über die Pack-Config und wird bei `createTenant(pack)` **einmal** zum
`ResolvedPack` aufgelöst.

Das US-Pack bringt seinen **eigenen vollständigen Inhalt** mit — eigener Kontenrahmen,
eigene Steuer-Codes, eigene Mappings, eigene Policy. Es gibt **keine Abhängigkeit nach
außen** auf ein geteiltes „Basis"-Modul. Die `dependsOn`-Verweise zeigen ausschließlich
**innerhalb** des Packs (z. B. tax → US-Konten, für Auflösungsreihenfolge + Integritätsprüfung).
Gleiche Eigenständigkeits-Entscheidung wie beim DE-Pack.

Die USA kennen — anders als Deutschland (SKR, HGB-Bilanzpflicht) — **keinen gesetzlich
vorgeschriebenen Kontenrahmen**. US-GAAP lässt die Kontengliederung frei. Wir führen die
Konten daher selbst, auf demselben abgenommenen Nummernsatz wie das DE-Pack (4-stellig,
Kontenklasse = führende Ziffer), nur mit englischen Bezeichnungen und US-spezifischen Konten
in den Lücken.

## Module (alle US-eigen)

| # | Datei | `kind` | `id` (Vorschlag) | beiträgt |
|---|---|---|---|---|
| 1 | `modul-1-konten-us.md` | `accounts` | `us-konten-2026` | accounts |
| 2 | `modul-2-tax-us-salestax-2026.md` | `tax` | `us-salestax-2026` | taxCodes |
| 3 | `modul-3-mapping-balance-sheet-gaap.md` | `mapping` | `us-gaap-balance-sheet` | mappings |
| 4 | `modul-4-mapping-income-statement.md` | `mapping` | `us-gaap-income-statement` | mappings |
| 5 | `modul-5-mapping-schedule-c.md` | `mapping` | `us-schedule-c-2026` | mappings |
| 6 | `modul-6-depreciation-us.md` | `depreciation` | `macrs-us` | depreciation |
| 7 | `modul-7-asset-accounts-us.md` | `assetAccounts` | `us-asset-accounts` | assetAccounts |
| 8 | `modul-8-policy-us-usd.md` | `policy` | `us-usd` | policy |

Dazu:

- `manifest-us-complete.md` — das Pack-Manifest, das diese Module bündelt.
- `salestaxreturn-mapping-frei.md` — warum die Sales-Tax-Erklärung **kein** Modul ist
  (und wo der US-Fall vom deutschen UStVA-Fall abweicht).
- `offene-entscheidungen.md` — die menscheneigenen Sign-off-Punkte (Konto-Nummern,
  Use-Tax-Verdrahtung, Default-Methode, Mehr-Staaten-Strategie).

## Was vom US-Pack abgedeckt wird

Sales Tax (erhoben, Verbindlichkeit), Use Tax (selbst veranlagt, Aufwand+Verbindlichkeit),
steuerfreie/Resale-/Interstate-Umsätze, Sales Returns/Discounts, De-minimis-Sofortabzug,
MACRS-Nutzungsdauern, US-GAAP Classified Balance Sheet, Multi-Step Income Statement,
Cash-Basis-Projektion (Schedule-C-Logik). Economic-Nexus-Status (Wayfair) über `smallBusiness`.

> **Noch ohne Fixtures.** Anders als das DE-Pack (durch `testing/testsuite/fixtures/` belegt) ist das
> US-Pack reine Beschreibung; die Konformitäts-Fixtures (`us-pack-resolves`, `us-sales-tax`,
> `us-use-tax`, `us-balance-income`, `us-fiscal-year`) und der CLI-Smoke `summae init --pack us`
> sind Teil des Builds → `offene-entscheidungen.md`, Punkt H.

## Build-Reihenfolge (Auflösung)

1. `accounts` (Modul 1) — keine Abhängigkeit.
2. `tax`, `mapping`, `assetAccounts` — `dependsOn` Modul 1 (referenzieren Konten per `number`).
3. `depreciation`, `policy` — datenrein, keine Konten-Referenz.
4. Manifest `us-complete` listet 1–8 + `packPolicy`-Kopie + `defaults`.
