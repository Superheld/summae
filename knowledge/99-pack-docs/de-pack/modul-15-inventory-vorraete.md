# Modul 15 — Vorratskategorien DE (§ 266 Abs. 2 B. I. HGB) (`inventory`)

```
kind: inventory · id: de-vorraete · version: 2026.1 · formatVersion: 0.6
contributes: ["inventory"] · dependsOn: [{ kind: accounts, id: de-konten }]
data.categories[] = { account, changeAccount, label }
```

## Zweck

Welche Konten des deutschen Rahmens **Vorräte** halten und **wohin die Bestandsveränderung
gebucht wird**. Gelesen wird das Modul von `valuateInventory` (F-CORE-050): der Kern rechnet den
Wertansatz, bildet die Differenz gegen den Buchwert und bucht sie — auf das Vorratskonto und auf
das Konto, das *hier* steht.

Das Modul ist der letzte Baustein einer Kette, die vorher im Leeren endete: Kostenstellen → Umlage
→ Zuschlagssätze → Herstellungskosten (Modul 10) → **Bewertung** → Bilanz. Ohne diesen Schritt war
die Herstellungskostenrechnung rechenbar und erreichte kein Konto, und die Aussage des Handbuchs,
dies sei „die eine Zahl der Kostenrechnung, die in die Bilanz kommt", stimmte nur, wenn die
einbettende Anwendung sie auf ein selbst erfundenes Konto buchte.

## Kategorien

| Vorratskonto | `changeAccount` | Warum dieses Gegenkonto |
|---|---|---|
| `1100` Roh-, Hilfs- und Betriebsstoffe | `5000` Wareneinsatz | § 275 Abs. 2 Nr. 5 — der **Materialaufwand** ist der Verbrauch, also Einkauf minus Bestandsaufbau; die Bestandsveränderung korrigiert ihn, sie ist keine eigene Zeile |
| `1110` Unfertige Erzeugnisse | `4100` Bestandsveränderungen | § 275 Abs. 2 **Nr. 2** — eigene Position der GuV („Erhöhung oder Verminderung des Bestands an fertigen und unfertigen Erzeugnissen") |
| `1120` Fertige Erzeugnisse | `4100` Bestandsveränderungen | ebenda |
| `1130` Waren | `5000` Wareneinsatz | wie `1100`: bezogene Waren stehen im Materialaufwand |

## Warum das Pack-Daten sind und nicht Kern

Die **Zweiteilung ist deutsch**, nicht mechanisch. Das us-Pack (`us-inventory-accounts`) nennt für
alle vier Kategorien **dasselbe** Gegenkonto, weil eine US-GuV keine Zeile „Bestandsveränderung"
kennt: dort korrigiert jede Bestandsänderung die *cost of goods sold*. Beide Packs sind richtig,
beide fahren denselben Kern, und der ganze Unterschied sind diese vier Zeilen — das ist der
Litmustest dieses Projekts in seiner kürzesten Form.

## Was der Kern trotzdem selbst weiß

- Dass ein Vorratskonto den `subtype: "inventory"` tragen muss (`E_INVENTORY_ACCOUNT_INVALID`) —
  der zwölfte Wert des geschlossenen Repertoires, mit seinem Leser.
- Dass **die Differenz** gebucht wird, nicht der Bestand, und dass eine unveränderte Periode
  deshalb nichts bucht.
- Dass der niedrigere von Wertansatz und übergebenem Marktwert gilt und die Zeile sagt, welcher
  genommen wurde. **Ob** der Vergleich Pflicht ist (§ 253 Abs. 4 HGB: strenges Niederstwertprinzip),
  steht nicht im Kern — der Kern nimmt den kleineren von zwei Zahlen.

## Was bewusst fehlt

**Kein Lager.** Kein Artikelstamm, keine Warenbewegung, keine Stückliste, kein fortgeschriebener
Bestand. Mengen sind **Eingabe** eines Bewertungsakts. Die Folge ist ehrlich zu benennen: die
**Verbrauchsfolge** (§ 256 HGB, Fifo/Lifo) braucht die Historie der Zugangswerte und ist deshalb
nicht gebaut — und sie wird nicht still gebaut, sondern ist Zeile 6 der offenen Liste in
`docs/hgb-conformance.md`. Ebenso ist die **permanente Inventur** (§ 241 Abs. 2 HGB) nichts, was
dieses Pack ermöglicht: wer sie führt, braucht ein System, das zählt.
