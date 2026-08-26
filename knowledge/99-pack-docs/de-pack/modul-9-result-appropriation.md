# Modul 9 — Ergebnisverwendung DE (`resultAppropriation`)

```
kind: resultAppropriation · id: de-ergebnisverwendung · version: 2026.1 · formatVersion: 0.6
contributes: ["resultAppropriation"] · dependsOn: [{kind: accounts, id: de-konten}]
data = allocationAccount + targets{} (je Ziel ein Konto)
```

## Zweck

Wohin ein beschlossenes Ergebnis gebucht wird. Die **Ergebnisverwendung ist ein Beschluss**
(§ 29 GmbHG, § 174 AktG), kein Rechenschritt — deshalb bucht sie kein Jahresabschluss, sondern
die Operation `appropriateResult`, und deshalb nennt der Aufrufer ein *Ziel* statt einer
Kontonummer.

Das Modul beantwortet genau zwei Fragen: gegen welches Konto die Verwendung läuft
(`allocationAccount`, subtype `result_allocation`) und welche Ziele diese Jurisdiktion kennt.

## Verwendungskonto

`allocationAccount: 2300` — das Konto mit subtype `result_allocation`. Sein Saldo ist
das, was die Bilanzposition mit `includesNetIncome` vom kumulierten Ergebnis abzieht; deshalb muss es
**in genau dieser Position** gemappt sein und in keiner anderen (`PackCompletenessTest` prüft das).

## Ziele

| Ziel (`target`) | Konto | Label im Modul |
|---|---|---|
| `carryForward` | 2100 | Gewinnvortrag / Verlustvortrag |
| `distribution` | 3500 | Verbindlichkeit aus Gewinnverwendung |

> Ein Ziel, das hier nicht steht, ist `E_APPROPRIATION_UNSUPPORTED` — und der Fehler nennt die
> angebotenen, damit ein Formular sie auflisten kann statt zu raten. `tenantConfiguration` meldet
> dieselbe Liste als `appropriationTargets[]`.
