# Modul 8 — Policy DE (EUR) (`policy`)

```
kind: policy · id: de-eur · version: 2026.1 · formatVersion: 0.6
contributes: ["policy"] · dependsOn: []
data.packPolicy = die drei Policy-Felder (§ packPolicy)
```

## Zweck

Die jurisdiktions-**Werte** (Rundung, Steuer-Granularität, Währungsskala) als Pack-Daten —
„Mechanik global, Politik im Pack". Der Kern ist parametrisch; dieses Modul setzt die
DE-Parameter.

## packPolicy

```
data.packPolicy: {
  roundingMode:           "halfUpAwayFromZero",
  taxRoundingGranularity: "perVoucher",
  currencyScale:          2
}
```

| Feld | DE-Wert | Bedeutung |
|---|---|---|
| `roundingMode` | `halfUpAwayFromZero` | kaufmännisch, jede Rundung auf Währungsskala (expandTax, AfA-Raten, allocate-Teilrundung, Projektions-Endwerte). **Nicht** auf ungerundete Zwischenwerte (≥6 Stellen), **nicht** auf die VA-Volle-Euro-Abrundung. |
| `taxRoundingGranularity` | `perVoucher` | eine Steuerzeile je Satz, `baseMoney` = Satz-Summe. (Beleg gegenläufig: XX nutzt `perLine`.) |
| `currencyScale` | `2` | EUR, ISO-4217-Exponent. Alle `money.amount` exakt 2 Nachkommastellen. |

Jeder Wert = heutiges DE-Verhalten; ein fehlendes `packPolicy` ist semantisch identisch
(Default ist bewusst DE-gefärbt). Skalenänderung auf bestehendem Mandanten → `E_POLICY_INVALID`.

## Defaults (am Manifest, nicht im policy-`data` — siehe Manifest)

Aus dem kanonischen `de-complete` (datenformat.md) **und** dem Profil `de-freiberufler-euer`:

```
defaults: { taxationMethod: "cash", smallBusiness: false, vatPeriod: "quarterly" }
```

> **Klärung zur „cash"-Frage:** Der kanonische DE-Default ist **`cash`** (EÜR-Sicht des
> Profils `de-freiberufler-euer`), nicht `accrual`. Das `default`-Pack (jurisdiktionsfrei)
> steht dagegen auf `accrual` — das ist ein anderes Pack, kein Widerspruch. Ob das DE-Pack
> als Default `cash` (EÜR) oder `accrual` (Bilanzierer) tragen soll, ist ein bewusster
> Sign-off-Punkt → `offene-entscheidungen.md`. `taxationMethod` ist pro Mandant ohnehin
> überschreibbar; der Default sagt nur, womit ein neuer DE-Mandant startet.
