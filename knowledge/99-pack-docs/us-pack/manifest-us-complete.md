# Manifest — `us-complete`

```
packs/us-complete.json · formatVersion: 0.6 · version: 2026.1
```

## Zweck

Das benannte, kuratierte Bündel der acht US-Module + `packPolicy`-Kopie + `defaults`. Der
Mandant pinnt es per `id`+`version`; `createTenant(us-complete)` löst es **einmal** zum
`ResolvedPack` auf (Resolver, `api.md`). Upgrade ist explizit, nie still. Basiswährung **USD**
wird beim Anlegen des Mandanten gesetzt (nicht im Pack kodiert — `packPolicy` trägt nur
Rundung/Granularität/Skala; vgl. DE-Pack, das EUR nur im Namen führt).

## Modulliste (alle US-eigen — kein externer dependsOn)

```jsonc
{
  "formatVersion": "0.6",
  "id": "us-complete",
  "name": "United States — vollständig (GAAP + Cash-Basis / Schedule C)",
  "version": "2026.1",
  "modules": [
    { "kind": "accounts",      "id": "us-konten-2026",          "version": "2026.1" },
    { "kind": "tax",           "id": "us-salestax-2026",        "version": "2026.1" },
    { "kind": "mapping",       "id": "us-gaap-balance-sheet",   "version": "2026.1" },
    { "kind": "mapping",       "id": "us-gaap-income-statement","version": "2026.1" },
    { "kind": "mapping",       "id": "us-schedule-c-2026",      "version": "2026.1" },
    { "kind": "depreciation",  "id": "macrs-us",                "version": "2026.1" },
    { "kind": "assetAccounts", "id": "us-asset-accounts",       "version": "2026.1" },
    { "kind": "policy",        "id": "us-usd",                  "version": "2026.1" }
  ],
  "overrides": [],
  "taxCodes": ["SALETAX", "USETAX", "EXEMPT"],
  "defaults": { "taxationMethod": "accrual", "smallBusiness": false, "vatPeriod": "quarterly" },
  "packPolicy": {
    "roundingMode": "halfUpAwayFromZero",
    "taxRoundingGranularity": "perVoucher",
    "currencyScale": 2
  }
}
```

## Hinweis zu `defaults` (US-spezifisch)

- **`taxationMethod: "accrual"`** — GAAP-Norm als Start. Die Cash Method ist unter §448(c)
  zulässig, solange der 3-Jahres-Durchschnittsumsatz **32 Mio. USD (2026)** nicht übersteigt;
  pro Mandant überschreibbar. (DE-Pack startet dagegen auf `cash`/EÜR — bewusster Unterschied.)
- **`smallBusiness: false`** — in den USA kein §19-Pendant. Funktional steht der Flag für den
  **Economic Nexus** (*South Dakota v. Wayfair*): erst ab i. d. R. 100.000 USD Umsatz je Staat
  entsteht die Sales-Tax-Erhebungspflicht. `true` = unterhalb Nexus → keine Sales-Tax-Zeilen.
- **`vatPeriod: "quarterly"`** — Sales-Tax-Meldefrequenz variiert je Staat und Umsatz (monatlich/
  quartalsweise/jährlich). Das Schema kennt nur `monthly`/`quarterly`; `quarterly` als gängiger
  Start. Eine jährliche Frequenz ist ein Schema-Offenpunkt → `offene-entscheidungen.md`.

## Resolver-Integrität (muss grün sein)

- **I1** jede `dependsOn`-Referenz auflösbar (tax/mapping/assetAccounts → `us-konten-2026`).
- **I2** jeder `taxAccount`/`inputTaxAccount` existiert im Konten-Modul.
- **I3** jedes Mapping-Konto existiert; Bereiche überschneidungsfrei (`E_MAPPING_OVERLAP`).
- **I4** genau **eine** `policy`; `packPolicy`-Kopie == `policy`-Modul.
- Kein Zyklus, keine Override-Kollision.

→ Bei Verstoß `E_PACK_UNRESOLVED_REF` / `E_PACK_INCOHERENT` / `E_POLICY_INVALID`, fail-loud.

## Varianten (später, via Overrides — Nutzungsweg 2)

- `us-cash` (Schedule-C-Kleinbetrieb): GAAP-Bilanz-/GuV-Mapping per `overrides[remove]` raus,
  `defaults.taxationMethod: cash`.
- `us-gaap` (Bilanzierer): Schedule-C-Mapping raus, `accrual`.
