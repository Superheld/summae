# Modul 2 — Ergebnisverwendung (neutral) (`resultAppropriation`)

```
kind: resultAppropriation · id: neutral-appropriation · version: 2026.1 · formatVersion: 0.6
contributes: ["resultAppropriation"] · dependsOn: [{kind: accounts, id: neutral}]
data = allocationAccount + targets{} (je Ziel ein Konto)
```

## Zweck

Der jurisdiktionsfreie Fall: ein Ergebnisverwendungskonto, ein Ziel (Vortrag). Genug, damit
`appropriateResult` auf dem neutralen Rahmen funktioniert, ohne eine Rechtsordnung zu behaupten,
die das `default`-Pack nicht hat.

## Verwendungskonto

`allocationAccount: 2300` — das Konto mit subtype `result_allocation`. Sein Saldo ist
das, was die Bilanzposition mit `includesNetIncome` vom kumulierten Ergebnis abzieht; deshalb muss es
**in genau dieser Position** gemappt sein und in keiner anderen (`PackCompletenessTest` prüft das).

## Ziele

| Ziel (`target`) | Konto | Label im Modul |
|---|---|---|
| `carryForward` | 2100 | Gewinnvortrag |

> Ein Ziel, das hier nicht steht, ist `E_APPROPRIATION_UNSUPPORTED` — und der Fehler nennt die
> angebotenen, damit ein Formular sie auflisten kann statt zu raten. `tenantConfiguration` meldet
> dieselbe Liste als `appropriationTargets[]`.
