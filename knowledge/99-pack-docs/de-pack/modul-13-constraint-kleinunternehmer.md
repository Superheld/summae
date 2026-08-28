# Modul 13 — Kleinunternehmer-Erlöse tragen keine Umsatzsteuer (`constraint`)

```
kind: constraint · id: de-kleinunternehmer · version: 2026.1 · formatVersion: 0.6
contributes: ["constraint"] · dependsOn: [accounts/de-konten]
data = accountCombinationRules[] (eine Regel, forbidAccountIn)
```

## Zweck

§ 19 Abs. 1 UStG: für die Umsätze eines Kleinunternehmers wird **keine Umsatzsteuer erhoben**.
Eine Buchung, die `4040` (Erlöse Kleinunternehmer) mit einem Umsatzsteuerkonto verbindet, weist
Steuer auf einem Umsatz aus, für den keine entsteht — und § 14c Abs. 2 UStG macht sie dann
**trotzdem geschuldet**. Das ist die teure Hälfte: die Bücher sind falsch *und* das Geld ist fällig.

Dies ist das erste Modul der Bibliothek, das mit `forbidAccountIn` arbeitet. Die zweite Vokabel des
Constraint-Sockels existierte seit 2026-08-28, war aber nur in `xx-8` belegt — also in einem
Fixture, das sein Pack selbst mitbringt. Eine Fähigkeit, die kein ausgeliefertes Pack benutzt, ist
keine Zusage.

## Die Regel

| trifft Konto | verbietet Konto | Grund |
|---|---|---|
| `4040` Erlöse Kleinunternehmer (steuerfrei § 19 UStG) | `3100`–`3110` Umsatzsteuer | § 19 Abs. 1 UStG — kein Steuerausweis; ausgewiesen wird sie nach § 14c Abs. 2 UStG dennoch geschuldet |

Verstoß ist `E_COMBINATION_FORBIDDEN`.

## Warum `4040` und **nicht** `4030`

Das ist die Überlegung, die ein späterer Pack-Autor braucht, und sie ist nicht offensichtlich.

`forbidAccountIn` verweigert eine **Kombination innerhalb einer Buchung**. Brauchbar ist das nur
dort, wo die Kombination auch legitim nie vorkommt. Bei `4030` (steuerfreie innergemeinschaftliche
Lieferung) ist das **nicht** der Fall: eine Sammelrechnung darf einen steuerpflichtigen und einen
innergemeinschaftlichen Umsatz zugleich enthalten, und dann stehen `4000`, `3100` und `4030` völlig
zu Recht in einer Buchung. Eine forbid-Regel auf `4030` würde eine richtige Buchung ablehnen — und
ein Constraint, der mehr verweigert als verlangt, ist schlechter als keiner.

`4040` kann in dieser Lage nicht sein: der Status nach § 19 gilt für ein **ganzes Kalenderjahr**
(§ 19 Abs. 1), deshalb mischt kein einzelner Beleg Kleinunternehmer- und Regelbesteuerungsumsatz.

## Was die Regel bewusst **nicht** sagt

**Den Vorsteuerausschluss.** § 19 Abs. 1 Satz 4 UStG verbietet dem Kleinunternehmer auch den
Vorsteuerabzug. Eine Vorsteuerbuchung enthält aber kein Erlöskonto, also würde eine an `4040`
aufgehängte Regel **nie auslösen**. Das Verbot hängt am **Profil** des Mandanten
(`smallBusiness`), nicht an einer Kontenkombination — und dafür hat das Vokabular keine Bedingung.
Die Lücke hier zu benennen ist besser als eine Regel, die so aussieht, als deckte sie sie ab.

**§ 13b und den innergemeinschaftlichen Erwerb.** Beide bleiben vom Kleinunternehmer geschuldet
(§ 19 Abs. 1 Satz 3 UStG), `3100` trägt dann zu Recht einen Saldo. Diese Buchungen enthalten kein
Erlöskonto und treffen die Regel deshalb nicht — hätte man sie stattdessen an den Kontentyp
`tax_out` gehängt, wäre genau das abgelehnt worden.

## Zusammenspiel mit Modul 12

Das `de`-Pack hat ab `de@2026.9` **zwei** `constraint`-Module. Sie **addieren sich**, sie ersetzen
sich nicht: beide Regelsätze sind gleichzeitig in Kraft, jede Regel wird geprüft, und die
Reihenfolge im Manifest bedeutet nichts. Belegt ist das in `xx-10-constraint-rules-readable`, das
zwei Constraint-Module in einem Pack führt und beide auslöst.

## Fixture

`testing/testsuite/fixtures/pack/de-pack/de-kleinunternehmer-ust-verboten.json` — der Verstoß, die
richtige Buchung, eine Erlösschmälerung auf einen steuerfreien Umsatz (die Modul 12 nicht anfasst)
und der § 13b-Fall, der durchlaufen muss.
