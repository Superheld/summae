# Modul 17 — Rechnungsabgrenzungsposten DE (§ 250 HGB) (`deferrals`)

```
kind: deferrals · id: de-rechnungsabgrenzung · version: 2026.1 · formatVersion: 0.6
contributes: ["deferrals"] · dependsOn: [{ kind: accounts, id: de-konten }]
data.kinds[] = { kind, account, label }
```

## Zweck

Welches Konto die **aktive** und welches die **passive** Rechnungsabgrenzung trägt. Mehr steht hier
nicht, und mehr gehört auch nicht her.

| `kind` | Konto | was es ist |
|---|---|---|
| `prepaidExpense` | `1900` Aktive Rechnungsabgrenzung | gezahlt, Leistung steht aus — ein **Vermögensgegenstand** |
| `deferredIncome` | `3900` Passive Rechnungsabgrenzung | erhalten, Leistung steht aus — eine **Schuld** |

## Was die Lücke war, und was sie nicht war

**Die Konten waren nie die Lücke.** Beide stehen seit der ersten Fassung im Rahmen, beide haben ihre
Bilanzposition (`A.V` und `P.D`). Gefehlt hat der **Plan**: eine im Dezember gezahlte
Versicherungsprämie fürs Folgejahr ließ sich abgrenzen — und musste dann Monat für Monat aus dem
Gedächtnis aufgelöst werden. Genau der Fehler, den `runDepreciation` für dieselbe Arithmetik
verhindert. Zwei Mechaniken, die sich nur darin unterscheiden, ob die Maschine sich erinnert, sind
kein Entwurf, sondern eine Auslassung.

Deshalb hat `runDeferralRelease` mit Absicht die Form des AfA-Laufs: eine Periode, idempotent,
`alreadyRun`. Wer eine Periode schon einmal mit `runDepreciation` geschlossen hat, soll für
denselben Vorgang keine zweite Vokabel lernen müssen.

## Was hier bewusst nicht steht

**Das betroffene Aufwands- oder Ertragskonto.** Das ist eine Tatsache über den *Vorgang* — welche
Versicherung, welche Miete —, nicht über die Rechtsordnung, und es kommt deshalb vom Aufrufer.
Das Pack sagt nur, wo abgegrenzt wird.

**Eine dritte Art.** Zwei Arten sind Gegensätze, keine Varianten: jede Buchung kippt mit ihnen und
sonst nichts. Eine dritte wäre eine dritte Buchungsrichtung, also Kern-Mechanik und keine Pack-Zeile
— die Vokabel ist im Kern geschlossen (`E_INPUT_INVALID` samt Liste).

**Die steuerlichen Sonderfälle.** § 5 Abs. 5 Satz 2 EStG kennt zwei Pflichtposten (Zölle und
Verbrauchsteuern auf Vorräte, Umsatzsteuer auf Anzahlungen), die handelsrechtlich Wahlrechte sind.
Sie sind mit diesem Modul *buchbar* — als gewöhnliche Abgrenzung auf 1900 —, aber summae weiß nicht,
dass sie Pflicht sind, und behauptet es nicht.
