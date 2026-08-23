# Manifest — `de-complete`

```
packs/de-complete.json · formatVersion: 0.6 · version: 2026.1
```

## Zweck

Das benannte, kuratierte Bündel der acht DE-Module + `packPolicy`-Kopie + `defaults`. Der
Mandant pinnt es per `id`+`version`; `createTenant(de-complete)` löst es **einmal** zum
`ResolvedPack` auf (Resolver, `api.md`). Upgrade ist explizit, nie still.

## Modulliste (alle DE-eigen — kein externer dependsOn)

```jsonc
{
  "formatVersion": "0.6",
  "id": "de-complete",
  "name": "Deutschland — vollständig (EÜR + Bilanz)",
  "version": "2026.1",
  "modules": [
    { "kind": "accounts",      "id": "de-konten-2026",      "version": "2026.1" },
    { "kind": "tax",           "id": "de-ust-2026",         "version": "2026.1" },
    { "kind": "mapping",       "id": "de-hgb-bilanz-266",   "version": "2026.1" },
    { "kind": "mapping",       "id": "de-guv-275",          "version": "2026.1" },
    { "kind": "mapping",       "id": "de-anlage-euer-2026", "version": "2026.1" },
    { "kind": "depreciation",  "id": "afa-de",              "version": "2026.1" },
    { "kind": "assetAccounts", "id": "de-asset-accounts",   "version": "2026.1" },
    { "kind": "policy",        "id": "de-eur",              "version": "2026.1" }
  ],
  "overrides": [],
  "taxCodes": ["USt19", "USt7", "VSt19", "VSt7", "RC13b", "igL", "USt19WA"],
  "defaults": { "taxationMethod": "cash", "smallBusiness": false, "vatPeriod": "quarterly" },
  "packPolicy": {
    "roundingMode": "halfUpAwayFromZero",
    "taxRoundingGranularity": "perVoucher",
    "currencyScale": 2
  }
}
```

## Abweichung zum heutigen datenformat.md ⚠

Das kanonische Beispiel in `datenformat.md` listet noch:

- `{ "kind": "accounts", "id": "summae-base" }` → **falsch** nach der Eigenständigkeits-
  Entscheidung. Muss `de-konten-2026` sein.
- `{ "kind": "assetAccounts", "id": "summae-base-asset-accounts" }` → muss `de-asset-accounts`
  sein.

Das ist eine **Doku-Korrektur** in `datenformat.md` (siehe `offene-entscheidungen.md`, Punkt
D). Inhaltlich ändert sich nichts an Engine/Resolver — nur die referenzierten Modul-`id`s.

## Resolver-Integrität (muss grün sein)

- **I1** jede `dependsOn`-Referenz auflösbar (tax/mapping/assetAccounts → `de-konten-2026`).
- **I2** jeder `taxAccount`/`inputTaxAccount` existiert im Konten-Modul.
- **I3** jedes Mapping-Konto existiert; Bereiche überschneidungsfrei (`E_MAPPING_OVERLAP`).
- **I4** genau **eine** `policy`; `packPolicy`-Kopie == `policy`-Modul.
- Kein Zyklus, keine Override-Kollision.

→ Bei Verstoß `E_PACK_UNRESOLVED_REF` / `E_PACK_INCOHERENT` / `E_POLICY_INVALID`, fail-loud.

## Varianten (später, via Overrides — Nutzungsweg 2)

- `de-euer` (nur EÜR): Bilanz-/GuV-Mapping per `overrides[remove]` raus.
- `de-bilanz` (nur Bilanzierer): `defaults.taxationMethod: accrual`, EÜR-Mapping raus.

Beleg für den Override-Pfad: `pack/de-composed-equals-de/de-complete-override-remove-equals-base`.
