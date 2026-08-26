# Modul 10 — Herstellungskosten DE (§ 255 Abs. 2 HGB) (`productionCost`)

```
kind: productionCost · id: de-herstellungskosten · version: 2026.1 · formatVersion: 0.6
contributes: ["productionCost"] · dependsOn: []
data.treatments[] = je Kostenbestandteil eine der drei Antworten (mandatory | optional | forbidden)
```

## Zweck

Welche Kostenbestandteile in die **Herstellungskosten** eingehen dürfen, müssen oder nicht
dürfen — die Bewertungsuntergrenze und -obergrenze des § 255 Abs. 2 HGB, als Daten. Das ist
der *bilanzielle* Teil der Kostenrechnung und deshalb bewusst **in** scope, während die
Steuerungsinstrumente der KLR (Plan-/Ist-Vergleich, Deckungsbeitrag) es nicht sind.

`treatment` kennt drei Antworten und alle drei sind Aussagen: `mandatory` = Pflichtbestandteil,
`optional` = Wahlrecht des Bilanzierenden, `forbidden` = Einbeziehungsverbot. Ein Bestandteil,
den das Pack nicht nennt, ist keine vierte Antwort — er ist eine Lücke.

## Bestandteile

| Komponente | `treatment` | Begründung im Modul |
|---|---|---|
| `materialDirect` | `mandatory` | Materialkosten, § 255 Abs. 2 Satz 2 HGB |
| `productionDirect` | `mandatory` | Fertigungskosten, § 255 Abs. 2 Satz 2 HGB |
| `specialProduction` | `mandatory` | Sonderkosten der Fertigung, § 255 Abs. 2 Satz 2 HGB |
| `materialOverhead` | `mandatory` | angemessene Teile der Materialgemeinkosten, § 255 Abs. 2 Satz 3 HGB |
| `productionOverhead` | `mandatory` | angemessene Teile der Fertigungsgemeinkosten, § 255 Abs. 2 Satz 3 HGB |
| `productionDepreciation` | `mandatory` | fertigungsbedingter Werteverzehr des Anlagevermögens, § 255 Abs. 2 Satz 3 HGB |
| `administration` | `optional` | angemessene Teile der Kosten der allgemeinen Verwaltung, § 255 Abs. 2 Satz 4 HGB — Wahlrecht; steuerlich seit 2016 ebenfalls Wahlrecht (§ 6 Abs. 1 Nr. 1b EStG), aber übereinstimmend mit der Handelsbilanz auszuüben |
| `socialBenefits` | `optional` | Aufwendungen für soziale Einrichtungen des Betriebs und für freiwillige soziale Leistungen, § 255 Abs. 2 Satz 4 HGB |
| `pensionContributions` | `optional` | Aufwendungen für betriebliche Altersversorgung, § 255 Abs. 2 Satz 4 HGB |
| `borrowingCosts` | `optional` | Fremdkapitalzinsen für die Finanzierung der Herstellung, soweit auf den Herstellungszeitraum entfallend, § 255 Abs. 3 Satz 2 HGB |
| `idleCapacity` | `forbidden` | Leerkosten — § 255 Abs. 2 Satz 3 HGB lässt nur ANGEMESSENE Teile der Gemeinkosten zu; der auf Unterbeschäftigung entfallende Teil ist es nicht |
| `research` | `forbidden` | Forschungskosten, § 255 Abs. 2 Satz 4 HGB (Einbeziehungsverbot); Entwicklungskosten sind davon zu trennen, § 255 Abs. 2a HGB |
| `distribution` | `forbidden` | Vertriebskosten, § 255 Abs. 2 Satz 4 HGB (Einbeziehungsverbot) |

> Gelesen wird das Modul von der `productionCost`-Projektion. Der Kern kennt die Komponenten-Namen
> als Vokabular, aber keine der Antworten — die stehen ausschließlich hier.
