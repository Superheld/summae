# Modul 5 — EÜR (Anlage EÜR) (`mapping`)

```
kind: mapping · id: de-anlage-euer-2026 · version: 2026.1 · formatVersion: 0.6
contributes: ["mappings"] · dependsOn: [{kind: accounts, id: de-konten-2026}]
data.mapping = { id, kind: "cash-basis-categories", version, positions[] }
```

## Zweck

Die Einnahmen-Überschuss-Rechnung (§4 Abs.3 EStG) als **Projektion über die doppelte
Buchung** — kein eigener Buchungsstil. Ordnet Konten den EÜR-Zeilen zu und steuert über
`includeNonCash`, welche Buchungen ohne Zahlungsfluss zählen. Validiert (8 Testfälle,
`euer-projektions-beweis.md`, Regeln R1–R7).

## Format-Regeln (cash-basis-categories)

- Zahlungsbasis: Standard zählt eine Position **mit** Zahlungsfluss im Buchungsjahr.
- `"includeNonCash": true` an einer Position → Buchungen auf diesen Konten zählen **ohne**
  Zahlungsfluss (AfA-Aufwand, unentgeltliche Wertabgabe als Einnahme). Regel R7 /
  datenformat.md.
- **transit (1300) ist EÜR-neutral** (Geldtransit/PSP-Umbuchungen) — gehört in keine Position.
- Selektoren `{from,to}` / `{numbers}`.

## Positionsstruktur (auf DE-Konten Modul 1)

**Betriebseinnahmen**

| key | Label | Konten | includeNonCash |
|---|---|---|---|
| E1 | Umsatzerlöse (19 % / 7 %) | 4000–4019 | — |
| E2 | Steuerfreie ig. Lieferungen | 4030 | — |
| E3 | Vereinnahmte Umsatzsteuer | 3100–3199 | — |
| E4 | Unentgeltliche Wertabgaben | 4040 ⚠ | **true** |
| E5 | USt auf unentgeltliche Wertabgaben | (WA-USt-Konto, s. Modul 2) | **true** |
| E6 | Anlagenabgang (Erlös) | 4900 | — |

**Betriebsausgaben**

| key | Label | Konten | includeNonCash |
|---|---|---|---|
| A1 | Waren / Fremdleistungen | 5000–5999 | — |
| A2 | Personalkosten | 6300, 6310 | — |
| A3 | Abschreibungen (AfA + GWG) | 6500–6519 | **true** |
| A4 | Bewirtung, abziehbar (70 %) | 6010 | — |
| A5 | Sonstige Betriebsausgaben | 6000–6099, 6700 | — |
| A6 | Gezahlte Vorsteuer | 1500–1599 | — |
| A7 | An das Finanzamt gezahlte USt | (Zahlungen) | — |

- **Bewirtung nicht abziehbar (6020)** ist **keine** Betriebsausgabe der EÜR (§4 Abs.5 EStG)
  → bewusst in keiner Ausgaben-Position; nur der abziehbare Teil (6010) in A4.
- **AfA (A3)** und **Wertabgabe (E4/E5)** sind die `includeNonCash`-Positionen — der Kern
  der R4/R7-Sonderbehandlung. Beleg: `tax/non-cash-benefit` (includeNonCash auf 8924/1779).
- ⚠ E4/E5: hängen an der Wertabgabe-Konto-Entscheidung (Modul 1 + `offene-entscheidungen.md`).

## Belege

`euer-projektions-beweis.md` (R1–R7, 8 Fälle grün), `tax/non-cash-benefit`,
EÜR-Metadaten `recurring`/`due`/`economicYear` + OP-Verknüpfung (Pflicht).
