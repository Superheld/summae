# Modul 9 — Inventory Costing US (ASC 330 full absorption) (`productionCost`)

```
kind: productionCost · id: us-inventory-costing · version: 2026.1 · formatVersion: 0.6
contributes: ["productionCost"] · dependsOn: []
data.treatments[] = je Kostenbestandteil eine der drei Antworten (mandatory | optional | forbidden)
```

## Zweck

Which cost components enter **inventory cost** — the US counterpart to § 255 Abs. 2 HGB, and
the reason the component vocabulary is shared rather than translated: the *mechanism* is the
same everywhere, the *answers* are not.

ASC 330 requires **full absorption**, so several components that German law leaves optional are
`mandatory` here, and fixed production overhead is allocated on *normal capacity* rather than
actual output. Selling and general administrative costs are `forbidden` on both sides.

## Bestandteile

| Komponente | `treatment` | Begründung im Modul |
|---|---|---|
| `materialDirect` | `mandatory` | direct materials, ASC 330-10-30-1 |
| `productionDirect` | `mandatory` | direct labor, ASC 330-10-30-1 |
| `specialProduction` | `mandatory` | other directly attributable production costs |
| `materialOverhead` | `mandatory` | production overhead on materials — full absorption, ASC 330-10-30-1 |
| `productionOverhead` | `mandatory` | variable and fixed production overhead; fixed overhead allocated on normal capacity, ASC 330-10-30-3 |
| `productionDepreciation` | `mandatory` | depreciation of production facilities, part of production overhead |
| `idleCapacity` | `forbidden` | abnormal idle facility expense is a current-period charge, ASC 330-10-30-7 |
| `administration` | `forbidden` | general and administrative expenses are period charges, ASC 330-10-30-8 — this is where US GAAP differs from § 255 Abs. 2 HGB, which leaves it to the preparer |
| `research` | `forbidden` | research and development is expensed as incurred, ASC 730-10-25-1 |
| `distribution` | `forbidden` | selling expenses are period charges, ASC 330-10-30-8 |

> Gelesen wird das Modul von der `productionCost`-Projektion. Der Kern kennt die Komponenten-Namen
> als Vokabular, aber keine der Antworten — die stehen ausschließlich hier.
