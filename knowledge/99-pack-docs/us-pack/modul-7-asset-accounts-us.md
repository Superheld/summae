# Modul 7 — Anlagen-Bewegungskonten US (`assetAccounts`)

```
kind: assetAccounts · id: us-asset-accounts · version: 2026.1 · formatVersion: 0.6
contributes: ["assetAccounts"] · dependsOn: [{kind: accounts, id: us-konten-2026}]
data.default = die fünf Bewegungskonten (per number); optional data.perClass
```

## Zweck

Verdrahtet die Abschreibungs-Mechanik mit den US-Konten: welches Konto bei Anschaffung,
Abschreibung, De-minimis-Sofortabzug und Abgang bebucht wird. Eigenes `kind` neben
`depreciation` (datenformat.md): Daten dort, Konten hier. Identische Konten-Rollen wie das
DE-Pack (gleicher Nummernsatz).

## Die fünf Bewegungskonten (auf US-Konten Modul 1)

```
data.default: {
  acquisitionCounterAccount:  "1200",   // Gegenkonto Anschaffung (Bank/Checking)
  depreciationExpenseAccount: "6500",   // Depreciation Expense
  gwgExpenseAccount:          "6510",   // Immediate Expense (de minimis safe harbor)
  disposalProceedsAccount:    "4900",   // Gain on Disposal of Assets
  disposalLossAccount:        "6900"    // Loss on Disposal of Assets
}
```

Alle fünf Nummern existieren in Modul 1 → **kein** neues Konto nötig. Das Feld
`gwgExpenseAccount` heißt aus Format-Gründen weiter so (geteilter Vertrag mit DE), trägt hier
aber den **De-minimis-Sofortabzug** (6510 „Immediate Expense — low-value / de minimis").

## Optional `perClass`

`data.perClass` kann je `assetClass` abweichende Konten setzen (z. B. eigener
Anschaffungs-Gegenposten je Anlagenart). In v1 nicht belegt — `default` reicht.

## Hinweis Anschaffungs-**Bestands**konto

Das aktivierte Anlagegut selbst bucht auf **0100 (Property, Plant and Equipment,
`fixed_asset`)** bzw. dem am Anlagegut gesetzten `assetAccount`. Das ist Beleg-/Anlagegut-Datum,
nicht Teil der fünf Bewegungskonten — der `acquisitionCounterAccount` ist nur das **Gegenkonto**
(Geldabfluss).
