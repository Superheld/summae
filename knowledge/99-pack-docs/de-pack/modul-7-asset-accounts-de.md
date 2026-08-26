# Modul 7 — Anlagen-Bewegungskonten DE (`assetAccounts`)

```
kind: assetAccounts · id: de-assets · version: 2026.1 · formatVersion: 0.6
contributes: ["assetAccounts"] · dependsOn: [{kind: accounts, id: de-konten}]
data.default = die fünf Bewegungskonten (per number); optional data.perClass
```

## Zweck

Verdrahtet die AfA-Mechanik mit den DE-Konten: welches Konto bei Anschaffung, AfA, GWG und
Abgang bebucht wird. Eigenes `kind` neben `depreciation` (datenformat.md): Daten dort,
Konten hier.

## Die fünf Bewegungskonten (auf DE-Konten Modul 1)

```
data.default: {
  acquisitionCounterAccount:  "1200",   // Gegenkonto Anschaffung (Bank)
  depreciationExpenseAccount: "6500",   // Abschreibungen auf Anlagen
  gwgExpenseAccount:          "6510",   // GWG-Sofortabschreibung
  disposalProceedsAccount:    "4900",   // Erträge aus Anlagenabgang
  disposalLossAccount:        "6900"    // Verluste aus Anlagenabgang
}
```

Alle fünf Nummern existieren in Modul 1 → **kein** neues Konto nötig. (Die Fixture
`assets/gwg-and-depreciation` führt lokal SKR-Nummern 1200/4830/4855; hier auf die DE-Konten
umgesetzt.)

## Optional `perClass`

`data.perClass` kann je `assetClass` abweichende Konten setzen (z. B. eigener
Anschaffungs-Gegenposten je Anlagenart). In v1 nicht belegt — `default` reicht für die
Fixtures. Tür offen.

## Hinweis Anschaffungs-**Bestands**konto

Das aktivierte Anlagegut selbst bucht auf **0100 (Anlagen, `fixed_asset`)** bzw. dem am
Anlagegut gesetzten `assetAccount`. Das ist Beleg-/Anlagegut-Datum, nicht Teil der fünf
Bewegungskonten — der `acquisitionCounterAccount` ist nur das **Gegenkonto** (Geldabfluss).
