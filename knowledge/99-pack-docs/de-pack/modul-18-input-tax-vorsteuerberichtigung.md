# Modul 18 — Vorsteuerberichtigung DE (§ 15a UStG, § 44 UStDV) (`inputTaxAdjustment`)

```
kind: inputTaxAdjustment · id: de-vorsteuerberichtigung · version: 2026.1 · formatVersion: 0.6
contributes: ["inputTaxAdjustment"] · dependsOn: [{ kind: accounts, id: de-konten }]
data.correctionPeriods[] = { assetKind, years, basis }
data.deMinimis           = { inputTaxAtMost, sharePointsAtLeast, amountAtMost, basis }
data.accounts            = { taxAccount, expenseAccount, incomeAccount }
data.reportingKey        = Kennzahl der Voranmeldung
```

## Zweck

Die **Zahlen** des § 15a UStG und der Bagatellgrenzen des § 44 UStDV. Der Kern kennt nur die
Mechanik — anteilige Berichtigung über einen Beobachtungszeitraum, mit zwei Bagatellgrenzen —, und
die ist überall dieselbe, wo es eine solche Regel gibt.

| | |
|---|---|
| `movable` | 5 Jahre (§ 15a Abs. 1 Satz 1 UStG) |
| `immovable` | 10 Jahre (§ 15a Abs. 1 Satz 2 UStG) |
| `inputTaxAtMost` | 1.000,00 € — darunter wird gar nicht beobachtet (§ 44 Abs. 1 UStDV) |
| `sharePointsAtLeast` / `amountAtMost` | 10 Prozentpunkte **oder** 1.000,00 € Berichtigungsbetrag; nur wenn **beides** darunter liegt, unterbleibt die Berichtigung (§ 44 Abs. 2 UStDV) |
| `accounts` | Vorsteuer 1500, Aufwand 6000, Ertrag 4900 |
| `reportingKey` | `63` — die Kennzahl, unter der die Berichtigung in die Voranmeldung geht |

Eine Art des Wirtschaftsguts, für die kein Zeitraum erklärt ist, wird **abgewiesen** statt geraten:
fünf statt zehn Jahre halbierte jede Berichtigung, und die Zahl sähe genauso amtlich aus wie eine
richtige.

## Was hier bewusst nicht steht — und auch nicht in der Bibliothek

**Das Register.** Welche Wirtschaftsgüter unter Beobachtung stehen und bis wann, führt die
einbettende Anwendung. Der Grund hält der Prüfung stand: der Auslöser ist eine
**Nutzungsänderung**, und die wird nie gebucht. Eine Bibliothek, die nur Buchungen sieht, kann den
Tag nicht sehen, an dem ein Transporter anfängt, privat gefahren zu werden.

**Die Frist.** Aus demselben Grund.

Was *nicht* draußen bleibt, ist die **Arithmetik**. Das Argument, das sie hinausgeschoben hatte,
lautete: eine falsch erzeugte Zahl sähe genauso amtlich aus wie eine richtige. Das ist ein Grund,
sie dort zu rechnen, wo Zahlen fixture-gepinnt, deterministisch und über zwei Sprachen geprüft
sind — kein Grund, sie nirgends zu rechnen.

## Der Meldeschlüssel ist keine Zierde

Die Steuerzeile der Berichtigung trägt `reportingKey`. Ohne ihn ginge die Buchung auf, stünde
richtig auf dem Vorsteuerkonto — und trüge **nichts** zur Voranmeldung bei. Genau diesen Defekt
meldet `vatReturn.gapWarnings` sonst als `tax_account_without_tax_code`; hier wird er vermieden,
statt gemeldet zu werden.
