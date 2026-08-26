# Modul 4 — Income Statement US-GAAP (`mapping`)

```
kind: mapping · id: us-gaap-income-statement · version: 2026.1 · formatVersion: 0.6
contributes: ["mappings"] · dependsOn: [{kind: accounts, id: us-accounts-2026}]
data.mapping = { id, kind: "income-statement", version, positions[] }
```

## Zweck

Gliedert die US-Erfolgskonten in den **Multi-Step Income Statement** nach US-GAAP. Reine
Projektions-Daten.

## Abweichung zu DE (GuV §275)

US-GAAP gliedert **nach Funktion** (Umsatzkostenverfahren): Net Sales − COGS = Gross Profit,
abzüglich Operating Expenses (SG&A, D&A) = Operating Income, plus/minus Other Income/Expense.
Das DE-GuV nutzt das **Gesamtkostenverfahren** (HGB §275 Abs. 2, nach Aufwandsart). Die
Zwischensummen (Gross Profit, Operating Income) werden — wie beim DE-Pack — vom Konsumenten
abgeleitet, nicht im Mapping kodiert.

## Format-Regeln (income-statement)

- Kein `side` (nur `balance-sheet` trägt das). Vorzeichenlogik der Engine: Ertrag Haben−Soll,
  Aufwand Soll−Haben.
- Selektoren `{from,to}` / `{numbers}`; jedes Erfolgskonto in genau eine Position
  (`E_MAPPING_OVERLAP` / `E_MAPPING_GAP`).

## Positionsstruktur (Multi-Step, auf US-Konten Modul 1)

| Pos | Label im Modul | Konten |
|---|---|---|
| 1 | Net Sales | 4000–4399 |
| 2 | Cost of Goods Sold | 5000–5999 |
| 3 | Selling, General and Administrative Expenses | 6000–6299 |
| 4 | Depreciation and Amortization | 6300–6399 |
| 5 | Other Income | 4900–4999 |
| 6 | Other Expenses | 6400–6999 |

## Offene Punkte

- COGS-Bereich 5000–5999 ist im Nummernsatz nur durch **5000** belegt; bei Bedarf
  Unterkonten (Material, Subcontractors, Freight) als Zusatzkonten + Feingliederung.
- Trennung „Selling" vs. „G&A" ist hier zusammengefasst (SG&A); echte Funktionstrennung
  bräuchte getrennte Aufwandskonten → nachfragegetrieben.
