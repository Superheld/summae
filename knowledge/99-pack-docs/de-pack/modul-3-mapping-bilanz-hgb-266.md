# Modul 3 — Bilanz HGB §266 (`mapping`)

```
kind: mapping · id: de-bilanz · version: 2026.5 · formatVersion: 0.6
contributes: ["mappings"] · dependsOn: [{kind: accounts, id: de-konten}]
data.mapping = { id, kind: "balance-sheet", version, positions[] }
```

## Zweck

Gliedert die DE-Konten in die HGB-Bilanzpositionen nach § 266 HGB. Reine Projektions-Daten;
die Bilanz selbst ist berechnet (kein Abschlusszyklus). Schaltet SF-10 frei.

## Format-Regeln (balance-sheet, datenformat.md)

- **Jeder Wurzelknoten trägt `side`**: `assets` | `liabilitiesAndEquity` (explizit, nicht aus
  Reihenfolge). `assets`-Positionen zeigen Soll−Haben, `liabilitiesAndEquity` Haben−Soll.
- `includesNetIncome: true` an einer Passivposition addiert das noch nicht verwendete Ergebnis
  (kumulierte Jahresergebnisse + Saldo der `result_allocation`-Konten).
- Selektoren je Position: `{from,to}` (String-Codepoint-Vergleich) oder `{numbers:[…]}`.
- **Jedes Konto MUSS in genau eine Position fallen** → sonst `E_MAPPING_OVERLAP` (Fehler) /
  `E_MAPPING_GAP` (Warnung, Auffangposition). Baut man die Bereiche lückenlos über 0000–6999.

## Positionsstruktur (auf DE-Konten-Nummern, Modul 1)

**Aktiva** (`side: assets`)

| Pos | Label im Modul | Konten |
|---|---|---|
| A.I | Anlagevermögen | 0000–0999 |
| A.Ia | Vorräte | 1100–1199 |
| A.II | Forderungen und sonstige Vermögensgegenstände | 1400–1599 |
| A.III | Wertpapiere | 1250–1299 |
| A.IV | Kassenbestand, Bundesbankguthaben, Guthaben bei Kreditinstituten und Schecks | 1200–1249, 1300–1349 |
| A.V | Rechnungsabgrenzungsposten | 1900–1999 |

**Passiva** (`side: liabilitiesAndEquity`)

| Pos | Label im Modul | Konten |
|---|---|---|
| P.A1 | Eigenkapital (Kapital und Rücklagen) | 2000–2299, 2400–2499 |
| P.A2 | Jahresergebnis / nicht verwendete Ergebnisse | 2300 + `includesNetIncome` |
| P.B | Rückstellungen | 3600–3699 |
| P.C | Verbindlichkeiten | 3000–3599 |
| P.D | Rechnungsabgrenzungsposten | 3900–3999 |

> **Aufwands-/Ertragskonten (4xxx–6xxx)** gehören nicht in die Bilanz — sie fließen über das
> Jahresergebnis (`includesNetIncome`) ins EK ein. Daher in diesem Mapping **nicht** als
> eigene Positionen, sondern durch die GuV (Modul 4) abgedeckt.

## Offene Punkte

- Feingliederung (§266 Abs. 2/3 Buchstaben-Ebene) so tief wie nötig — Vorschlag oben ist die
  geldwürdige Mindesttiefe. Verfeinerung beim Build, falls eine Fixture sie verlangt.
- Beleg-Fixture: `projections/balance-sheet-mapping.json` (Format-Verhalten verifiziert).
