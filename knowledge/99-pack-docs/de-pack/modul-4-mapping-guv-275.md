# Modul 4 — GuV §275 (`mapping`)

```
kind: mapping · id: de-guv-275 · version: 2026.1 · formatVersion: 0.6
contributes: ["mappings"] · dependsOn: [{kind: accounts, id: de-konten-2026}]
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

| Pos | Label | Konten |
|---|---|---|
| 1 | Umsatzerlöse | 4000–4019 |
| 1a | – Erlösschmälerungen (gewährte Skonti) | 4020 |
| 1b | Steuerfreie ig. Lieferungen | 4030 |
| 1c | Unentgeltliche Wertabgaben | 4040 ⚠ (falls angelegt, sonst in 1) |
| 4 | Sonstige betriebliche Erträge (Anlagenabgang) | 4900 |
| 5 | Materialaufwand / Wareneinsatz / Fremdleistungen | 5000–5999 |
| 6a | Personalaufwand – Löhne und Gehälter | 6300 |
| 6b | Personalaufwand – soziale Abgaben | 6310 |
| 7 | Abschreibungen | 6500–6519 |
| 8 | Sonstige betriebliche Aufwendungen (inkl. Bewirtung, Forderungsverluste) | 6000–6099, 6700 |
| 8b | – davon nicht abziehbar (Bewirtung 30 %) | 6020 |
| — | Verluste aus Anlagenabgang | 6900 |

- **Bewirtung 70/30** (6010/6020) liegt unter „sonstige betriebliche Aufwendungen"; der
  nicht abziehbare Teil (6020) wird separat ausgewiesen (steuerliche Hinzurechnung ist
  App-/StB-Sache, hier nur sichtbar getrennt).
- ⚠ 4040 nur, wenn die Wertabgabe ein eigenes Konto bekommt (siehe Modul 1 +
  `offene-entscheidungen.md`).

## Beleg

`projections/*` (income-statement-Verhalten); GuV §275 als Mapping-Modul ist Format-konform
in `pack/de-composed-equals-de/` vorgespielt (`de-guv-275`, kind `income-statement`).
