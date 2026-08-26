# Modul 5 — Cash-Basis / Schedule C (`mapping`)

```
kind: mapping · id: us-schedule-c-2026 · version: 2026.2 · formatVersion: 0.6
contributes: ["mappings"] · dependsOn: [{kind: accounts, id: us-accounts-2026}]
data.mapping = { id, kind: "cash-basis-categories", version, positions[] }
```

## Zweck

Die **Cash-Basis-Rechnung** als Projektion über die doppelte Buchung — analog zur deutschen
EÜR (Modul 5 im DE-Pack), hier an den **IRS Schedule C** (Form 1040, *Profit or Loss From
Business*) angelehnt. Für US-Kleinbetriebe, die die **Cash Method** wählen dürfen (§448(c):
3-Jahres-Durchschnittsumsatz ≤ 32 Mio. USD, 2026). Ordnet Konten den Schedule-C-Zeilen zu und
steuert über `includeNonCash`, welche Buchungen ohne Zahlungsfluss zählen.

## Format-Regeln (cash-basis-categories)

- Zahlungsbasis: Standard zählt eine Position **mit** Zahlungsfluss im Buchungsjahr.
- `"includeNonCash": true` an einer Position → Buchungen auf diesen Konten zählen **ohne**
  Zahlungsfluss (v. a. Depreciation). Gleiche Mechanik wie DE-EÜR (Regel R7).
- **transit (1300) ist cash-basis-neutral** (Geldtransit/PSP-Umbuchungen) — keine Position.
- Selektoren `{from,to}` / `{numbers}`.

## Positionsstruktur (Schedule-C-Logik, auf US-Konten Modul 1)

| Pos | Label im Modul | Konten |
|---|---|---|
| L1 | Gross receipts or sales | 4000–4199 |
| L2 | Returns and allowances | 4200–4399 |
| L6 | Other income (incl. gain on disposal) | 4900 |
| L4 | Cost of goods sold | 5000–5999 |
| L13 | Depreciation and section 179 expense | 6300–6399 · `includeNonCash` |
| L26 | Wages | 6100 |
| L23 | Taxes and licenses (incl. employer payroll tax, use tax) | 6110, 6200 |
| L27 | Other expenses (SG&A, bad debt) | 6000, 6400, 6900 |

## Abweichung zu DE (EÜR)

- DE-EÜR trennt **vereinnahmte/gezahlte USt** als eigene Positionen (E3/A6/A7), weil die USt
  durch die EÜR „durchläuft". US-Cash-Basis braucht das nicht: Sales Tax ist treuhänderischer
  Durchlauf (kein Income), es gibt keinen Vorsteuer-Gegenpart.
- Statt amtlicher Kennzahlen referenziert dieses Modul die **Schedule-C-Zeilennummern** als
  semantische Anker (`L1`, `L4`, …).

## Belege

Noch ohne Fixture (US-Pack ist Beschreibung). Build-Beleg analog DE: ein
`cash-basis`-Projektionsbeweis mit `includeNonCash` auf 6500/6510 →
`offene-entscheidungen.md`, Punkt H.
