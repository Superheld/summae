# Modul 6 — AfA Deutschland (`depreciation`)

```
kind: depreciation · id: de-afa · version: 2026.7 · formatVersion: 0.6
contributes: ["depreciation"] · dependsOn: []
data = AfA-Tabellen/-Sätze + GWG-Grenzen (assets-modell.md v0.4)
```

## Zweck

Reine **Daten** zur AfA: GWG-Schwellen, degressive Sätze mit Anschaffungszeitraum-Gültigkeit,
Nutzungsdauer-Vorschläge (AfA-Tabellen). Die **Mechanik** (linear, declining,
Methodenwechsel declining→linear, pro-rata monatsgenau) ist **Kern**, nicht hier.

## GWG-Schwellen (Rechtsstand 06/2026, verifiziert)

```
gwgThresholds: [
  { validFrom: "2018-01-01", validTo: null,
    immediateMax: "800.00",      // Sofortabzug bis 800 € netto (§6 Abs.2 EStG)
    poolMin:      "250.01",      // Sammelposten-Untergrenze
    poolMax:      "1000.00",     // Sammelposten-Obergrenze (§6 Abs.2a EStG)
    poolYears:    5,             // Auflösung über 5 Wirtschaftsjahre zu je 1/5 (§6 Abs.2a Satz 2 EStG)
    poolReducedOnDisposal: false } // Abgang mindert den Sammelposten nicht (§6 Abs.2a Satz 4 EStG)
]
```

`poolYears` **und** `poolReducedOnDisposal` sind **Pflicht, sobald eine Pool-Spanne aufgemacht
wird** (Schema-Bedingung in `format.schema.json`, `$defs/depreciationData`). Beide standen vorher
fest im Kern-Code — jede andere Jurisdiktion mit Pool-Regel hätte still die deutsche Frist (SPEC-004)
und die deutsche Abgangsregel (IMPL-025) geerbt. Dass Letzteres keine Selbstverständlichkeit ist,
zeigt der Vergleich: **UK und Australien entnehmen Abgänge ihren Pools**, Deutschland nicht. Der
Wert ist Rechtsstand: §6 Abs. 2a EStG ist 2026 unverändert (250,01–1.000 €, fünf Jahre, Abgänge
ohne Wirkung auf den Posten).

Weiche: ≤ 800 → Sofortabzug (Konto 6510); 250,01–1.000 → wahlweise Sammelposten/Pool;
> 1.000 (bzw. > 800 ohne Pool) → Aktivierung + planmäßige AfA. Beleg:
`assets/gwg-and-depreciation` (datierte Grenzen, `gwgChoice: auto`).

## Degressive AfA — „Investitionsbooster" (Rechtsstand 06/2026, verifiziert)

```
decliningRates: [
  { validFrom: "2025-07-01", validTo: "2027-12-31",
    method: "declining",
    maxFactor: "2.5",   // höchstens 2,5× linearer Satz
    cap:       "0.30" } // gedeckelt auf 30 % pro Jahr
]
```

Anschaffung 01.07.2025–31.12.2027. Der **Methodenwechsel declining→linear** (sobald
Restbuchwert/Restlaufzeit den höheren Satz ergibt) ist Kern-Mechanik — hier nur die Sätze.
§7g (Sonder-AfA bis 40 %, AK-Minderung bei IAB) ist Plan-Mechanik; der IAB selbst ist
außerbilanziell → StB/App (dokumentierte Abgrenzung, datenformat.md v0.4).

## Nutzungsdauer (AfA-Tabellen, überschreibbar)

`assetClasses[]` mit `usefulLifeYears` als Vorschlag aus den amtlichen AfA-Tabellen; im
Anlagegut überschreibbar. Repräsentativ (beim Build gegen AfA-Tabelle vervollständigen):

| assetClass | Nutzungsdauer (Jahre) |
|---|---|
| it-hardware | 3 |
| büromöbel | 13 |
| pkw | 6 |
| maschinen | nach Branche |

> Beleg-Fixture nutzt `assetClass: it-hardware`, monatsgenaue pro-rata (6/36), idempotenter
> AfA-Lauf. Nutzungsdauer-Tabelle ist Daten — Umfang nachfragegetrieben erweiterbar.

## Hinweis

Konten kommen **nicht** aus diesem Modul, sondern aus **Modul 7** (`assetAccounts`) — die
Trennung ist der Format-Vertrag (datenformat.md: `depreciation` = Sätze/Daten, `assetAccounts`
= Bewegungskonten).
