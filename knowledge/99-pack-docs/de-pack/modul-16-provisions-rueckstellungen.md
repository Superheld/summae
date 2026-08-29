# Modul 16 — Rückstellungen DE (§ 249 HGB) und ihre Abzinsungsregel (§ 253 Abs. 2 HGB) (`provisions`)

```
kind: provisions · id: de-rueckstellungen · version: 2026.1 · formatVersion: 0.6
contributes: ["provisions"] · dependsOn: [{ kind: accounts, id: de-konten }]
data.accounts[]   = { account, expenseAccount, releaseAccount, label }
data.discounting  = { fromMonths, basis }
```

## Zweck

Welche Konten des deutschen Rahmens **Rückstellungen** tragen, wohin ihre Zuführung und ihre
Auflösung buchen, und **ab welcher Restlaufzeit abzuzinsen ist**. Gelesen von den vier
Rückstellungs-Operationen (F-CORE-051).

§ 249 Abs. 1 HGB ist eine **Pflicht**, kein Wahlrecht. Bis zum 2026-08-29 hatte summae dazu gar
nichts — kein Konto, keine Bilanzposition, keine Operation —, und das war nicht bloß Lücke: eine
Bilanz ohne Rückstellungen überzeichnet Ergebnis und Eigenkapital in genau der Richtung, die
schmeichelt.

## Konten

| Rückstellungskonto | `expenseAccount` | `releaseAccount` | |
|---|---|---|---|
| `3600` Sonstige Rückstellungen | `6800` Zuführung sonstige Rückstellungen | `4900` Sonstige betriebliche Erträge | der Regelfall |
| `3610` Rückstellungen für Personalaufwand | `6300` Löhne und Gehälter | `4900` | Urlaub, Tantiemen, Altersteilzeit — die Zuführung gehört in den **Personalaufwand** (§ 275 Abs. 2 Nr. 6), nicht in die sonstigen Aufwendungen |
| `3620` Steuerrückstellungen | `6810` Steueraufwand (Rückstellungszuführung) | `4900` | |

**Die Auflösung geht auf die sonstigen betrieblichen Erträge, und das ist eine Aussage:** eine
aufgelöste Rückstellung ist Ertrag, den das Unternehmen **nie zahlen musste** — etwas anderes als
eine Verpflichtung, die eingetreten ist. Dass beide auf demselben Konto landen wie eine
Teilauflösung durch Neubewertung, ist Absicht: so lassen sie sich im Hauptbuch nicht versehentlich
auseinanderdividieren. Wer sie unterscheiden will, liest das Register, wo jede Bewegung ihre Art
trägt.

## Die Abzinsung — und warum hier kein Zinssatz steht

`discounting.fromMonths: 12` sagt: was länger als zwölf Monate läuft, ist abzuzinsen
(§ 253 Abs. 2 Satz 1 HGB). `discounting.basis` nennt die Fundstelle.

**Ein `rate` gibt es hier bewusst nicht.** § 253 Abs. 2 verlangt den *durchschnittlichen
Marktzinssatz der vergangenen sieben Geschäftsjahre*, und den gibt die Deutsche Bundesbank
**monatlich** bekannt. Eine Zahl in dieser Datei wäre veraltet, bevor irgendjemand das Pack
aktualisiert — und ein veralteter Rechtssatz, der amtlich aussieht, ist schlimmer als ein fehlender:
niemand fragt nach, weil ja einer dasteht. Deshalb trägt das Pack die **Regel**, der Aufrufer den
**Satz** (`discountRate` je Vorgang), und eine abzinsungspflichtige Rückstellung ohne Satz wird mit
`E_PROVISION_DISCOUNT_RATE_REQUIRED` abgewiesen statt undiskontiert gebucht.

Das ist die Ausnahme von der sonstigen Aufteilung „Mechanik im Kern, Zahlen im Pack", und sie hat
einen benennbaren Grund: die AfA-Sätze des Moduls 6 ändern sich mit einem Gesetz, dieser Satz mit
jedem Monatsbericht.

## Was der Kern selbst weiß

- Dass ein Rückstellungskonto den `subtype: "provision"` tragen muss
  (`E_PROVISION_ACCOUNT_INVALID`) — der dreizehnte Wert des geschlossenen Repertoires, mit Leser.
- Dass es **vier** Ereignisse gibt und nicht eines: bilden, verbrauchen, auflösen, neu bewerten.
- Dass ein **Verbrauchs-Überhang** Aufwand des laufenden Jahres ist und keine rückwirkende
  Korrektur — die häufigste Rechnung ist höher als die Schätzung.
- Dass mehr aufzulösen, als die Rückstellung trägt, erfundener Ertrag wäre
  (`E_PROVISION_EXCEEDS_CARRYING`).
- Die Zinskonvention: volle Jahre zinseszinslich, Stub-Monate linear. Sie wird **genannt statt
  unterstellt**, weil eine echte gebrochene Potenz transzendent ist und die beiden Implementierungen
  auf manchen Eingaben einen Cent auseinanderbrächte.

## Was fehlt

Pensionsrückstellungen sind mit `3610` *kontierbar*, aber ihre Bewertung (versicherungsmathematisch,
§ 253 Abs. 2 Satz 2 mit dem pauschalen Fünfzehnjahres-Ansatz) leistet summae nicht und behauptet es
nicht. Wer sie führt, rechnet den Erfüllungsbetrag außerhalb und übergibt ihn.
