# Modul 11 — Legal forms US (`legalForms`)

```
kind: legalForms · id: us-legal-forms · version: 2026.1 · formatVersion: 0.6
contributes: ["legalForms"] · dependsOn: []
data = sizeClasses[] (leer) + forms{} (je Rechtsform label + resolution)
```

## Zweck

Dasselbe `kind` wie im DE-Pack, mit einer bemerkenswert anderen Antwort: **keine** der fünf Formen
kennt eine gesetzliche Frist für einen Ergebnisverwendungsbeschluss. Genau deshalb wird das Modul
mitgeliefert, statt es wegzulassen — ein Pack, das etwas nicht kann, soll das *sagen* (IMPL-032).
Ohne das Modul meldete `unappropriatedResult` `resolutionRequired: null` („niemand hat gesagt, was
diese Firma ist"), mit ihm `false` („in dieser Jurisdiktion beschließt diese Form nichts"). Das ist
der Unterschied zwischen einer offenen Frage und einer Auskunft.

## Die Rechtsformen

| `form` | Label | Beschluss? |
|---|---|---|
| `soleProprietorship` | Sole proprietorship | nein |
| `partnership` | Partnership | nein |
| `llc` | Limited liability company | nein |
| `sCorporation` | S corporation | nein |
| `cCorporation` | C corporation | nein |

Das US-Ergebnis schließt ohne Beschluss in Retained Earnings — deshalb bietet
`us-appropriation` (Modul 10) auch nur `carryForward` und kein `distribution`. Eine
Dividendenausschüttung ist in den USA ein Board-Beschluss über eine *Zahlung*, kein
Feststellungsverfahren mit Frist; sie wird als normale Buchung erfasst, nicht über
`appropriateResult`.

## Größenklassen

```
data.sizeClasses: []
```

Leer, und das ist die Aussage: das US-Pack staffelt keine Frist nach Unternehmensgröße, also gibt es
keine Klasse, in die sich ein Mandant einordnen könnte. `setEntityProfile` lehnt jede angegebene
`sizeClass` mit `E_INPUT_INVALID` ab, statt sie stillschweigend zu schlucken.
