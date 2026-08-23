# Modul 2 — USt Deutschland 2026 (`tax`)

```
kind: tax · id: de-ust-2026 · version: 2026.1 · formatVersion: 0.6
contributes: ["taxCodes"] · dependsOn: [{kind: accounts, id: de-konten-2026}]
data.taxCodes[]  (TaxCode = {code, versions[], datevBu?}; tax-modell.md §1)
```

## Zweck

Die deutschen Steuerschlüssel als versionierte Regeldaten. Jeder Code bündelt Satz +
Konten + VA-Kennzahl(en) + Mechanismus. Konten werden **per `number`** im DE-Konten-Modul
(Modul 1) referenziert. `mechanism` ist ein offener String (real belegt: `standard`,
`reverse_charge`, `intra_community_supply`).

## TaxCode-Version — Felder (aus datenformat.md)

`validFrom`, `validTo` (nullbar), `rate`, `taxAccount`, `reportingKey` (nullbar),
`mechanism` (Default `standard`), `inputTaxAccount`, `inputReportingKey`,
`baseReportingKey` (alle nullbar). Optional `datevBu` (DATEV-BU-Alias) am Code.

## Die Codes (Kennzahlen gegen Fixtures verifiziert)

| Code | Satz | mechanism | taxAccount | Kz (reportingKey) | input-Konto / Kz | base-Kz | Fixture |
|---|---|---|---|---|---|---|---|
| **USt19** | 19,00 | standard | 3100 | **81** | — | — | `tax/vat-return`, `mixed-tax-rates` |
| **USt7** | 7,00 | standard | 3110 | **86** | — | — | `tax/mixed-tax-rates` |
| **VSt19** | 19,00 | standard | 1500 | **66** | — | — | `tax/vat-return` |
| **VSt7** | 7,00 | standard | 1510 | **66** | — | — | Profil `de-freiberufler-euer` ⚠ |
| **RC13b** | 19,00 | reverse_charge | 3100 | **47** | 1500 / **67** | **46** | `tax/reverse-charge` |
| **igL** | 0,00 | intra_community_supply | — (null) | **41** | — | — | `core/partner-and-ec-sales` |
| **USt19WA** | 19,00 | standard | 3100 | **81** | — | — | `tax/non-cash-benefit` |

- Alle Kennzahlen entsprechen exakt den Fixtures (geprüft: 81, 86, 66, 47/67/46, 41).
- **Vorsteuer-Kennzahl 66** bündelt abziehbare Vorsteuer satzunabhängig → VSt19 **und**
  VSt7 melden auf 66. ⚠ VSt7 ist in keiner Fixture belegt, nur im Profil gelistet — beim
  Build mit Fachstand bestätigen.
- `igL`: steuerfrei, **kein** `taxAccount` (null); nur Bemessungsgrundlage auf Kz 41. Erlös
  bucht auf Konto **4030** (Modul 1).
- `RC13b`: erzeugt **zwei** Steuerpositionen aus einem Sachverhalt — USt (3100, Kz 47,
  credit) **und** Vorsteuer (1500, Kz 67, debit), `baseReportingKey` 46; Zahlbetrag = Netto.

## Offene Entscheidung — RC13b- und WA-Steuerkonten

Die Tabelle kollabiert §13b und Wertabgabe auf die **Standard-USt/VSt-Konten** (3100/1500)
und trennt sie nur über die Kennzahlen (47/67 bzw. 81). Die Fixtures nutzen demgegenüber
**eigene SKR-Konten** (§13b: 1787/1577; WA: 1779).

→ **Sign-off nötig:** eigene DE-Konten anlegen (z. B. 3120 USt §13b, 1520 VSt §13b, 3130 USt
Wertabgabe) **oder** bei der Kennzahl-Trennung auf 3100/1500 bleiben. Funktional ist die
Kennzahl-Trennung VA-korrekt; eigene Konten sind nur buchhalterische Sichtbarkeit. Details:
`offene-entscheidungen.md`.

## Versionierung / Rechtsstand

- `validFrom` der aktuellen Sätze: `2024-01-01`, `validTo: null` (Rechtsstand 06/2026).
- Satzwechsel = neue `version` im `versions[]`-Array (nie still überschreiben). Die
  anzuwendende Version folgt dem **Leistungsdatum** (`serviceDate`, Fallback `voucherDate`)
  — § 27 Abs. 1 UStG (datenformat.md v0.4).
- **Kleinunternehmer (§19):** kein eigener Code — bucht **ohne** USt; der Wechsel ist eine
  Profil-/Defaults-Sache (`smallBusiness`), nicht ein Steuerschlüssel. Beleg:
  `tax/small-business-switch`.
