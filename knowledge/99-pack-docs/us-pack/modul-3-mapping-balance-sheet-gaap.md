# Modul 3 — Balance Sheet US-GAAP (`mapping`)

```
kind: mapping · id: us-gaap-balance-sheet · version: 2026.1 · formatVersion: 0.6
contributes: ["mappings"] · dependsOn: [{kind: accounts, id: us-konten-2026}]
data.mapping = { id, kind: "balance-sheet", version, positions[] }
```

## Zweck

Gliedert die US-Konten in den **Classified Balance Sheet** nach US-GAAP. Reine
Projektions-Daten; die Bilanz ist berechnet (kein Abschlusszyklus).

## Abweichung zu DE (HGB §266)

US-GAAP ordnet die **Aktiva nach Liquidität** (liquideste zuerst: Cash → Receivables →
Prepaid → PP&E) — die **umgekehrte** Reihenfolge zum HGB §266, das mit dem Anlagevermögen
beginnt. Außerdem klassifiziert GAAP explizit **current vs. non-current**.

## Format-Regeln (balance-sheet, datenformat.md)

- **Jeder Wurzelknoten trägt `side`**: `assets` | `liabilitiesAndEquity` (explizit). `assets`
  zeigt Soll−Haben, `liabilitiesAndEquity` Haben−Soll.
- `includesNetIncome: true` an einer Eigenkapitalposition addiert das noch nicht verwendete
  Ergebnis (kumulierte Jahresergebnisse + Saldo der `result_allocation`-Konten).
- Selektoren je Position: `{from,to}` (String-Codepoint-Vergleich) oder `{numbers:[…]}`.
- **Jedes Bilanzkonto MUSS in genau eine Position fallen** → sonst `E_MAPPING_OVERLAP` /
  `E_MAPPING_GAP`.

## Positionsstruktur (auf US-Konten Modul 1)

**Assets** (`side: assets`) — nach Liquidität

| Pos | Label | Konten |
|---|---|---|
| A.I | Cash and Cash Equivalents | 1200–1399 |
| A.II | Accounts Receivable and Other Current Assets | 1400–1599 |
| A.III | Prepaid Expenses | 1900–1999 |
| A.IV | Property, Plant and Equipment | 0000–0999 |

**Liabilities and Equity** (`side: liabilitiesAndEquity`)

| Pos | Label | Konten | Hinweis |
|---|---|---|---|
| L.A | Accounts Payable | 3000–3099 | Current |
| L.B | Sales and Use Tax Payable | 3100–3199 | Current; umfasst 3130/3140 |
| L.C | Payroll Liabilities | 3300–3399 | Current |
| L.D | Other Current Liabilities | 3500–3599 | inkl. Customer Deposits |
| L.E | Deferred Revenue | 3900–3999 | Current/Long-term |
| L.F | Stockholders' Equity (Capital and Retained Earnings) | 2000–2499 | |
| L.G | Net Income | — | `includesNetIncome: true`, keine Konten |

> **Erfolgskonten (4xxx–6xxx)** gehören nicht in die Bilanz — sie fließen über das
> Jahresergebnis (`includesNetIncome`, Position L.G) ins Eigenkapital ein und werden durch das
> Income Statement (Modul 4) abgedeckt.

## Offene Punkte

- **Current vs. non-current** ist hier über die Position­labels ausgedrückt, nicht als eigene
  Strukturebene. Der Nummernsatz hat keine eigenen Long-term-Debt-Konten (alle Verbindlichkeiten
  3000–3599 sind faktisch current) — eine spätere Long-term-Position (z. B. Notes Payable) wäre
  ein Zusatzkonto + eigene Position. → `offene-entscheidungen.md`.
- Feingliederung so tief wie nötig; Vorschlag oben ist die geldwürdige Mindesttiefe.
