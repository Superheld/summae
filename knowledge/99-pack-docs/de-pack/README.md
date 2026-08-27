# DE-Pack — `de-complete`

Baufertige Beschreibung des Deutschland-Packs. Eine Datei je Modul, jede konkret genug,
dass der Build daraus direkt das Modul-JSON (`modules/<kind>/<id>.json`) schreiben kann.

> **Status:** Spezifikation/Beschreibung (kein Build-Artefakt). Normativer Format-Vertrag:
> `50-spezifikation/datenformat.md` § v0.6. Orakel: `testing/testsuite/fixtures/`.

## Grundprinzip: das DE-Pack ist eigenständig

Der **Kern enthält nichts Festes** — keine Konten, keine Steuersätze, keine Kennzahlen.
Alles kommt über die Pack-Config und wird bei `createTenant(pack)` **einmal** zum
`ResolvedPack` aufgelöst.

Das DE-Pack bringt seinen **eigenen vollständigen Inhalt** mit — eigener Kontenrahmen,
eigene Steuer-Codes, eigene Mappings, eigene Policy. Es gibt **keine Abhängigkeit nach
außen** auf ein geteiltes „Basis"-Modul. Die `dependsOn`-Verweise zeigen ausschließlich
**innerhalb** des Packs (z. B. tax → DE-Konten, für Auflösungsreihenfolge +
Integritätsprüfung).

Präzedenz: Das Test-Pack **XX** (`testing/testsuite/fixtures/pack/conformance-xx/`) ist genau so
gebaut — eigenes `xx-minimal`-Konten-Modul, eigenes `xx-salestax`, eigenes `xx-policy`,
nichts Geteiltes.

`neutral`/`summae-base` ist **nicht** Teil dieses Packs. Es ist der Kontenrahmen des
`default`-Packs (jurisdiktionsfreier Fall) und eine Vorlage zum Abkupfern beim Anlegen
neuer Packs.

## Module (alle DE-eigen)

| # | Datei | `kind` | `id` | Version |
|---|---|---|---|---|
| 1 | `modul-1-konten-de.md` | `accounts` | `de-konten` | 2026.3 |
| 2 | `modul-2-tax-de-ust-2026.md` | `tax` | `de-ust` | 2026.4 |
| 3 | `modul-3-mapping-bilanz-hgb-266.md` | `mapping` | `de-bilanz` | 2026.3 |
| 4 | `modul-4-mapping-guv-275.md` | `mapping` | `de-guv` | 2026.1 |
| 5 | `modul-5-mapping-euer.md` | `mapping` | `de-euer` | 2026.4 |
| 6 | `modul-6-depreciation-afa-de.md` | `depreciation` | `de-afa` | 2026.7 |
| 7 | `modul-7-asset-accounts-de.md` | `assetAccounts` | `de-assets` | 2026.1 |
| 8 | `modul-8-policy-de-eur.md` | `policy` | `de-policy` | 2026.1 |
| 9 | `modul-9-result-appropriation.md` | `resultAppropriation` | `de-ergebnisverwendung` | 2026.1 |
| 10 | `modul-10-production-cost-de.md` | `productionCost` | `de-herstellungskosten` | 2026.1 |
| 11 | `modul-11-legal-forms-de.md` | `legalForms` | `de-rechtsformen` | 2026.1 |

Dazu:

- `manifest-de-complete.md` — das Pack-Manifest, das diese Module bündelt.
- `vatreturn-mapping-frei.md` — warum die USt-VA **kein** Modul ist.
- `offene-entscheidungen.md` — die menscheneigenen Sign-off-Punkte (Konto-Nummern,
  RC13b/Wertabgabe-Konten, fachliche RQ-1/RQ-2) + die Doku-Korrektur in `datenformat.md`.

## Was vom DE-Pack abgedeckt wird

Soll-/Ist-Versteuerung, USt-VA-Kennzahlen, §13b Reverse-Charge, ig. Lieferung, unentgeltliche
Wertabgabe, Skonto/§17-Korrektur, Bewirtungs-70/30-Split, GWG/AfA (linear, degressiv,
Methodenwechsel), Bilanz (HGB §266), GuV (§275), EÜR. Belegt durch die Fixtures in
`testing/testsuite/fixtures/{tax,core,assets,projections}/`.

## Build-Reihenfolge (Auflösung)

1. `accounts` (Modul 1) — keine Abhängigkeit.
2. `tax`, `mapping`, `assetAccounts` — `dependsOn` Modul 1 (referenzieren Konten per `number`).
3. `depreciation`, `policy` — datenrein, keine Konten-Referenz.
4. Manifest `de-complete` listet 1–8 + `packPolicy`-Kopie + `defaults`.
