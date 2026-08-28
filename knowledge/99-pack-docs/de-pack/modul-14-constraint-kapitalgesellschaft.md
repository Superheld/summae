# Modul 14 — Eine Kapitalgesellschaft hat kein Privatkonto (`constraint`)

```
kind: constraint · id: de-kapitalgesellschaft · version: 2026.1 · formatVersion: 0.9
contributes: ["constraint"] · dependsOn: [accounts/de-konten, legalForms/de-rechtsformen]
data = accountUsageRules[] (eine Regel, forbidAccountIn + appliesWhen)
```

## Zweck

Das Vermögen einer Kapitalgesellschaft ist von dem ihrer Gesellschafter getrennt
(§ 13 Abs. 1 GmbHG, § 1 Abs. 1 AktG). Was ein Gesellschafter entnimmt, ist deshalb immer **einer von
vier** Vorgängen — Geschäftsführergehalt, Darlehen, offene Gewinnausschüttung oder, wenn es keiner
der drei ist, eine **verdeckte** Gewinnausschüttung (§ 8 Abs. 3 Satz 2 KStG). Keiner davon ist eine
Privatentnahme, und `2400 Privat` ist für keinen von ihnen das richtige Konto.

Die Buchung richtet damit zwei Schäden an: sie mischt Gesellschafts- und Gesellschaftervermögen,
und sie **verdeckt, welcher der vier Vorgänge tatsächlich vorliegt** — was gerade bei der vGA die
Frage ist, die eine Betriebsprüfung stellt.

## Die Regel

| verbietet Konto | wenn Rechtsform | Grund |
|---|---|---|
| `2400` Privat | `gmbh`, `ug`, `ag`, `eg` | § 13 Abs. 1 GmbHG / § 1 Abs. 1 AktG — getrenntes Vermögen; die Entnahme ist Gehalt, Darlehen, oGA oder vGA (§ 8 Abs. 3 Satz 2 KStG) |

Verstoß ist `E_ACCOUNT_USE_FORBIDDEN`.

## Warum das Modul an der **Rechtsform** hängt und nicht am Kontenrahmen

Das ist der Grund, warum es dieses Modul überhaupt gibt, und er ist der eigentliche Inhalt.

`2400` ist für ein **Einzelunternehmen**, eine GbR, eine OHG und eine KG das völlig richtige Konto —
dort ist die Privatentnahme der Normalfall und hat mit dem Gewinn nichts zu tun. Derselbe
Kontenrahmen (`de-konten`) bedient beide Welten, weil das in Deutschland so ist: SKR-Rahmen sind
nicht nach Rechtsform getrennt. Ein Verbot, das am Konto hängt, wäre also entweder für die
Personengesellschaften falsch oder für die Kapitalgesellschaften wirkungslos.

Genau dafür gibt es `appliesWhen` (v0.9, F-CORE-047): die Bedingung steht **an der Regel**, nicht am
Bündel, und wird bei jeder Buchung gegen die per `setEntityProfile` erklärte Rechtsform geprüft.

## Warum `accountUsageRules` und nicht `accountCombinationRules`

Weil die Aussage keine Kombination ist. Gemeint ist „dieses Konto darf dieser Mandant nicht
bebuchen", nicht „diese beiden Konten dürfen nicht zusammen auftreten".

Mit dem alten Vokabular hätte man das als `whenAccountIn: 2400–2400` +
`forbidAccountIn: 0000–9999` schreiben können — jede Buchung hat mindestens zwei Konten, also
greift es. Dieser Trick ist **zweimal falsch**, und ein späterer Pack-Autor soll ihn nicht abschreiben:

1. Er liest sich als Bereich. Wer ihn liest, muss erst herleiten, dass der Bereich „alles" bedeuten
   soll.
2. Kontonummern vergleichen über **Codepoints**. `0000`–`9999` deckt einen Kontenrahmen, dessen
   Nummern mit einem Buchstaben beginnen, gar nicht ab und einen sechsstelligen nur zufällig. Eine
   Verweigerung, deren Richtigkeit davon abhängt, wie ein *fremder* Kontenrahmen nummeriert, ist
   keine Verweigerung.

## Was die Regel bewusst **nicht** sagt

**Was stattdessen zu buchen ist.** Ob eine Entnahme Gehalt, Darlehen, oGA oder vGA ist, entscheidet
der Sachverhalt und nicht die Buchhaltung; die Bibliothek liefert Fähigkeiten, keine Workflows. Die
Regel sagt nur, dass `2400` keine der vier Antworten ist.

**Nichts über Mandanten ohne erklärte Rechtsform.** Wer `setEntityProfile` nie aufgerufen hat, hat
keine Rechtsform, und eine Regel, die auf eine nicht vorhandene Tatsache bedingt ist, **greift
nicht**. Das ist eine Entscheidung, kein Rückfall: eine Buchung abzulehnen, weil der Mandant etwas
nicht konfiguriert hat, bestraft das Fehlen einer Angabe; die Regel trotzdem anzuwenden, würde eine
Voraussetzung unterstellen, die niemand geprüft hat. `tenantConfiguration` meldet die Regel in
beiden Fällen, damit ein Aufrufer sieht, dass sie **ruht**, statt es aus durchgehenden Buchungen
schließen zu müssen.

**Nichts über die anderen vier Rechtsformen.** `einzelunternehmen`, `gbr`, `ohg` und `kg` stehen
nicht in der Bedingung und buchen `2400` weiter wie bisher.

## Belegt durch

- `de-kapitalgesellschaft-privatkonto` — die ausgelieferte Regel: eine GmbH wird abgelehnt, ein
  Einzelunternehmen mit derselben Buchung nicht, und vor `setEntityProfile` ruht die Regel.
- `xx-12-constraint-applies-when` — der Mechanismus an einem Pack, das seine Daten selbst mitbringt.
