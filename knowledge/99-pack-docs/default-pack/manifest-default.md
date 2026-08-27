# Manifest — `default` (jurisdiktionsfrei)

```
pack: default · version: 2026.2 · formatVersion: 0.6
Datei: pack-library/default-pack/default.json · 2 Module
```

## Zweck

Der neutrale Ausgangspunkt: ein Kontenrahmen ohne Land. `createTenant({"pack": "default"})` ergibt
einen buchungsfähigen Mandanten, der **keine** Jurisdiktion behauptet — kein Steuerschlüssel, kein
Mapping, kein Rechtsformkatalog.

## Modulliste

| `kind` | `id` | Version |
|---|---|---|
| `accounts` | `neutral` | 2026.1 |
| `resultAppropriation` | `neutral-appropriation` | 2026.1 |

## Was dieses Pack bewusst NICHT mitbringt

Und warum das eine Antwort ist, keine Lücke:

- **kein `mapping`** — eine Bilanz- oder GuV-Gliederung ist immer jemandes Recht. `balanceSheet`
  sagt das inzwischen auch so und verweist auf `importMapping`, statt „Parameter `mapping` fehlt" zu
  melden (IMPL-032); `tenantConfiguration.mappings` beantwortet dieselbe Frage ohne Fehler.
- **kein `tax`** — dito, Steuerschlüssel sind national.
- **kein `legalForms`** — eine Rechtsform ist Gesellschaftsrecht. `setEntityProfile` weist deshalb
  jede Angabe ab und sagt *dieses Pack deklariert keine*; `unappropriatedResult` meldet
  `resolutionRequired: null` — **nicht** `false`: hier hat niemand gesagt, dass kein Beschluss
  geschuldet ist, sondern nur, dass es niemand sagen kann.
- **`resultAppropriation` schon** — wohin ein Ergebnis vorgetragen wird, ist Mechanik und kein Recht;
  angeboten wird `carryForward`, mehr nicht.

## `defaults` und `packPolicy`

```jsonc
"defaults": {"taxationMethod": "accrual"},
"packPolicy": {"roundingMode": "halfUpAwayFromZero", "taxRoundingGranularity": "perVoucher", "currencyScale": 2}
```
