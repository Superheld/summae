# Modul 8 — Policy US (USD) (`policy`)

```
kind: policy · id: us-usd · version: 2026.1 · formatVersion: 0.6
contributes: ["policy"] · dependsOn: []
data.packPolicy = die drei Policy-Felder (§ packPolicy)
```

## Zweck

Die jurisdiktions-**Werte** (Rundung, Steuer-Granularität, Währungsskala) als Pack-Daten —
„Mechanik global, Politik im Pack". Der Kern ist parametrisch; dieses Modul setzt die
US-Parameter. Die **Basiswährung USD** wird beim Anlegen des Mandanten gesetzt, nicht hier
kodiert (das `packPolicy` trägt keine Währung — nur die Skala; vgl. DE-Pack mit EUR).

## packPolicy

```
data.packPolicy: {
  roundingMode:           "halfUpAwayFromZero",
  taxRoundingGranularity: "perVoucher",
  currencyScale:          2
}
```

| Feld | US-Wert | Bedeutung |
|---|---|---|
| `roundingMode` | `halfUpAwayFromZero` | kaufmännisch, jede Rundung auf Währungsskala. Üblich für US-Commercial-Sales-Tax. **Nicht** auf ungerundete Zwischenwerte (≥6 Stellen). |
| `taxRoundingGranularity` | `perVoucher` | Sales Tax je Beleg auf den steuerpflichtigen Gesamtbetrag gerundet (eine Steuerzeile je Satz). Einige Staaten verlangen `perLine` (Bracket-System) — Build-Option. |
| `currencyScale` | `2` | USD, ISO-4217-Exponent. Alle `money.amount` exakt 2 Nachkommastellen. |

Skalenänderung auf bestehendem Mandanten → `E_POLICY_INVALID`.

## Defaults (am Manifest, nicht im policy-`data` — siehe Manifest)

```
defaults: { taxationMethod: "accrual", smallBusiness: false, vatPeriod: "quarterly" }
```

- **`taxationMethod: "accrual"`** — GAAP-Norm als Start (≠ DE-Pack, das `cash`/EÜR startet).
  Cash Method unter §448(c) (≤ 32 Mio. USD, 2026) pro Mandant wählbar. Sign-off-Punkt →
  `offene-entscheidungen.md`, Punkt C.
- **`smallBusiness: false`** — kein §19-Pendant; Flag steht für den Economic-Nexus-Status
  (Wayfair). `true` = unterhalb Nexus → keine Sales-Tax-Zeilen.
- **`vatPeriod: "quarterly"`** — Sales-Tax-Meldefrequenz variiert je Staat/Umsatz; Schema kennt
  nur `monthly`/`quarterly`. Jährliche Frequenz ist Schema-Offenpunkt.
