# Manifest — `us` (USA)

```
pack: us · version: 2026.8 · formatVersion: 0.6
Datei: pack-library/us-pack/us.json · 11 Module · frühere Fassungen in versions/
```

## Zweck

Das kuratierte Bündel aller US-Module plus `packPolicy`-Kopie und `defaults`;
`createTenant({"pack": "us", "baseCurrency": "USD"})` löst es **einmal** zum `ResolvedPack` auf.
Die Basiswährung steht **nicht** im Pack — sie wird beim Anlegen des Mandanten gesetzt; `packPolicy`
trägt nur die Nachkommastellen.

> **Der Pack heißt `us`, nicht `us-complete`.** Bis 2026-08-27 beschrieb dieses Dokument ein
> Manifest `us-complete@2026.1` mit acht Modulen unter alten IDs — dasselbe Muster wie beim
> DE-Pack und mit derselben Folge für den, der daraus abschrieb (IMPL-034).

## Modulliste

| `kind` | `id` | Version |
|---|---|---|
| `accounts` | `us-accounts-2026` | 2026.1 |
| `tax` | `us-salestax-2026` | 2026.2 |
| `mapping` | `us-gaap-balance-sheet` | 2026.2 |
| `mapping` | `us-gaap-income-statement` | 2026.1 |
| `mapping` | `us-schedule-c-2026` | 2026.2 |
| `depreciation` | `macrs-us` | 2026.2 |
| `assetAccounts` | `us-asset-accounts` | 2026.2 |
| `policy` | `us-usd` | 2026.1 |
| `productionCost` | `us-inventory-costing` | 2026.1 |
| `inventory` | `us-inventory-accounts` | 2026.1 |
| `resultAppropriation` | `us-appropriation` | 2026.1 |
| `legalForms` | `us-legal-forms` | 2026.1 |

Details je Modul in `modul-1` … `modul-11`, Übersicht im [README](README.md).

## Steuerschlüssel

`SALETAX` · `USETAX` · `EXEMPT`

## `defaults` und `packPolicy`

```jsonc
"defaults": {"taxationMethod": "accrual", "smallBusiness": false, "vatPeriod": "quarterly"},
"packPolicy": {"roundingMode": "halfUpAwayFromZero", "taxRoundingGranularity": "perVoucher", "currencyScale": 2}
```

## Versionierung

Wie beim DE-Pack: eine veröffentlichte `(id, version)` ist eingefroren, jede Modulreferenz-Änderung
hebt auch die Manifest-Version, alte Fassungen bleiben unter `versions/` auflösbar.
