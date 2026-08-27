# Modul 11 — Rechtsformen DE (`legalForms`)

```
kind: legalForms · id: de-rechtsformen · version: 2026.1 · formatVersion: 0.6
contributes: ["legalForms"] · dependsOn: []
data = sizeClasses[] + forms{} (je Rechtsform label + resolution)
```

## Zweck

Beantwortet die zwei Fragen, die `appropriateResult` nicht beantworten konnte: **muss** über das
Ergebnis überhaupt beschlossen werden, und **bis wann**. Beides hängt nicht am Land allein, sondern
an der Rechtsform — ein Einzelunternehmer fasst gar keinen Beschluss, eine GmbH hat acht Monate,
eine kleine elf, eine Genossenschaft sechs. Der Kern kennt davon nichts: er rechnet nur
Geschäftsjahresende + n Monate → Monatsletzter. Jede Zahl und jede Fundstelle kommt aus diesem
Modul.

Kein `dependsOn`: das Modul nennt keine Konten. Es ist reines Rechtsformwissen und damit auch für
Packs verwendbar, die einen anderen Kontenrahmen mitbringen.

## Die Rechtsformen

| `form` | Beschluss? | Frist | klein | Fundstelle |
|---|---|---|---|---|
| `einzelunternehmen` | nein | — | — | — |
| `gbr` | nein | — | — | — |
| `ohg` | nein | — | — | — |
| `kg` | nein | — | — | — |
| `gmbh` | **ja** | 8 Monate | 11 Monate | § 42a Abs. 2 GmbHG |
| `ug` | **ja** | 8 Monate | 11 Monate | § 42a Abs. 2 GmbHG |
| `ag` | **ja** | 8 Monate | — | § 175 Abs. 1 AktG |
| `eg` | **ja** | 6 Monate | — | § 48 Abs. 1 GenG |

`resolution.required: false` ist eine **Antwort, keine Lücke**. Bei den Personengesellschaften und
beim Einzelunternehmen richtet sich die Ergebnisverwendung nach Gesellschaftsvertrag bzw. Entnahme,
nicht nach einer gesetzlichen Frist — `unappropriatedResult` meldet dann `resolutionRequired: false`
und `resolutionDueBy: null`, und das ist die richtige Auskunft, nicht das Fehlen einer.

⚠ **Die GmbH & Co. KG ist eine `kg`.** § 42a GmbHG gilt für die GmbH, nicht für die KG, deren
Komplementärin sie ist; die Feststellung folgt dem Gesellschaftsvertrag. Was für sie über § 264a HGB
zusätzlich gilt, betrifft die **Offenlegung**, nicht den Verwendungsbeschluss — und Offenlegung ist
Workflow der einbettenden Anwendung, nicht Bibliotheksdatum.

## Größenklassen

```
data.sizeClasses: ["small", "medium", "large"]
```

Nur `small` ist belegt (§ 42a Abs. 2 Satz 2 GmbHG über § 267 Abs. 1 HGB); `medium` und `large`
existieren als Vokabular, damit ein Mandant sich einordnen kann, ohne dass daraus eine abweichende
Frist folgt. **Die Klasse wird deklariert, nicht gerechnet:** § 267 misst Bilanzsumme, Umsatz *und*
durchschnittliche Arbeitnehmerzahl an zwei aufeinanderfolgenden Stichtagen — die Arbeitnehmerzahl
steht in keiner Buchhaltung. Wer nichts sagt, bekommt die Regelfrist.

## Wie es beim Mandanten ankommt

`setEntityProfile { legalForm, sizeClass? }` — geprüft gegen genau diese Tabelle, eine unbekannte
Rechtsform wird mit `E_INPUT_INVALID` und der Liste der angebotenen abgelehnt. Gespeichert wird sie
(anders als `actorAuthentication`): sie beschreibt das Unternehmen, dessen Bücher das sind, und ihre
Änderung ist ein Audit-Ereignis mit Datum. `tenantConfiguration` meldet sie zurück, samt der Liste,
aus der eine Maske ihr Auswahlfeld bauen kann.
