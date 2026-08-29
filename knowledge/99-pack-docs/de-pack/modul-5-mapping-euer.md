# Modul 5 — EÜR (Anlage EÜR) (`mapping`)

```
kind: mapping · id: de-euer · version: 2026.5 · formatVersion: 0.6
contributes: ["mappings"] · dependsOn: [{kind: accounts, id: de-konten}]
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

| Pos | Label im Modul | Konten |
|---|---|---|
| E1 | Umsatzerlöse | 4000–4019, 4020 |
| E2 | Steuerfreie innergemeinschaftliche Lieferungen | 4030 |
| E3 | Vereinnahmte USt | 3100–3199 |
| E4 | Unentgeltliche Wertabgaben | 4050 · `includeNonCash` |
| E5 | Betriebseinnahmen als Kleinunternehmer (§ 19 UStG) | 4040 |
| E6 | Erträge aus Anlagenabgang | 4900 |
| E7 | Bestandsveränderungen (bei der Einnahmen-Überschuss-Rechnung ohne Ansatz) | 4100–4199 |
| A1 | Wareneinsatz und Fremdleistungen | 5000–5999 |
| A2 | Personalkosten | 6300–6399 |
| A3 | Abschreibungen (AfA und GWG) | 6500–6599 · `includeNonCash` |
| A4 | Bewirtungskosten (abziehbar) | 6010 |
| A5 | Sonstige Betriebsausgaben | 6000–6009, 6030–6099, 6700–6899 |
| A6 | Gezahlte Vorsteuer | 1500–1599 |
| A7 | Nicht abziehbare Betriebsausgaben | 6020 |
| A8 | Restbuchwert bei Anlagenabgang | 6900 · `includeNonCash` |

## Belege

`euer-projektions-beweis.md` (R1–R7, 8 Fälle grün), `tax/non-cash-benefit`,
EÜR-Metadaten `recurring`/`due`/`economicYear` + OP-Verknüpfung (Pflicht).
