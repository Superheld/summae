# Modul 3 — Bilanz HGB §266 (`mapping`)

```
kind: mapping · id: de-hgb-bilanz-266 · version: 2026.1 · formatVersion: 0.6
contributes: ["mappings"] · dependsOn: [{kind: accounts, id: de-konten-2026}]
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

| Pos | Label | Konten |
|---|---|---|
| A | Anlagevermögen | 0100–0999 |
| B.I | Vorräte / Forderungen L+L | 1400–1499 |
| B.II | Sonstige Vermögensgegenstände (inkl. Vorsteuer) | 1450, 1500–1599 |
| B.III | Kassenbestand, Bank, Guthaben | 1200–1399 |

**Passiva** (`side: liabilitiesAndEquity`)

| Pos | Label | Konten | Hinweis |
|---|---|---|---|
| A | Eigenkapital | 2000–2499 | `includesNetIncome: true` an der EK-Wurzel |
| C.1 | Verbindlichkeiten aus L+L | 3000–3099 | |
| C.2 | Steuerrückstellungen / USt | 3100–3199 | |
| C.3 | Sonstige Verbindlichkeiten (Personal, erhaltene Anzahlungen) | 3300–3599 | |

> **Aufwands-/Ertragskonten (4xxx–6xxx)** gehören nicht in die Bilanz — sie fließen über das
> Jahresergebnis (`includesNetIncome`) ins EK ein. Daher in diesem Mapping **nicht** als
> eigene Positionen, sondern durch die GuV (Modul 4) abgedeckt.

## Offene Punkte

- Feingliederung (§266 Abs. 2/3 Buchstaben-Ebene) so tief wie nötig — Vorschlag oben ist die
  geldwürdige Mindesttiefe. Verfeinerung beim Build, falls eine Fixture sie verlangt.
- Beleg-Fixture: `projections/balance-sheet-mapping.json` (Format-Verhalten verifiziert).
