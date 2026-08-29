# Modul 4 — GuV §275 (`mapping`)

```
kind: mapping · id: de-guv · version: 2026.2 · formatVersion: 0.6
contributes: ["mappings"] · dependsOn: [{kind: accounts, id: de-konten}]
data.mapping = { id, kind: "income-statement", version, positions[] }
```

## Zweck

Gliedert die DE-Erfolgskonten (4xxx Erlöse/Erträge, 5xxx/6xxx Aufwand) in die GuV-Positionen
nach § 275 HGB (Gesamtkostenverfahren). Reine Projektions-Daten. Schaltet SF-09 frei.

## Format-Regeln (income-statement)

- Kein `side` (nur `balance-sheet` trägt das). Vorzeichenlogik der Engine: Ertrag Haben−Soll,
  Aufwand Soll−Haben.
- Selektoren `{from,to}` / `{numbers}`; jedes Erfolgskonto in genau eine Position
  (`E_MAPPING_OVERLAP`/`E_MAPPING_GAP`).

## Positionsstruktur (§275 Abs. 2 GKV, auf DE-Konten Modul 1)

| Pos | Label im Modul | Konten |
|---|---|---|
| 1 | Umsatzerlöse | 4000–4099 |
| 1a | Erhöhung oder Verminderung des Bestands an fertigen und unfertigen Erzeugnissen | 4100–4199 |
| 2 | Sonstige betriebliche Erträge | 4900–4999 |
| 3 | Materialaufwand | 5000–5999 |
| 4 | Personalaufwand | 6300–6399 |
| 5 | Abschreibungen | 6500–6599 |
| 6 | Sonstige betriebliche Aufwendungen | 6000–6099, 6700–6999 |

## Beleg

`projections/*` (income-statement-Verhalten); GuV §275 als Mapping-Modul ist Format-konform
in `pack/de-composed-equals-de/` vorgespielt (`de-guv-275`, kind `income-statement`).
