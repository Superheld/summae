# Modul 12 — Entgeltminderung trägt ihre Steuerkorrektur (`constraint`)

```
kind: constraint · id: de-entgeltminderung · version: 2026.1 · formatVersion: 0.6
contributes: ["constraint"] · dependsOn: [accounts/de-konten]
data = accountCombinationRules[] (zwei Regeln, beide requireAccountIn)
```

## Zweck

§ 17 Abs. 1 UStG: mindert sich das Entgelt, ist der geschuldete Steuerbetrag zu berichtigen —
Satz 2 verlangt dasselbe für den Vorsteuerabzug. Bis 2026-08-28 konnte summae das nicht durchsetzen,
und die Lücke stand als **A-13** auf der Pflichtenliste der einbettenden Anwendung.

Das Tückische daran ist, dass die unvollständige Buchung **alle** anderen Invarianten erfüllt: sie
ist ausgeglichen, sie hat einen Beleg, sie steht in der richtigen Periode. Konten, Saldenliste und
Summen- und Saldenbilanz sehen richtig aus. Falsch ist allein die Zahl, die angemeldet wird — also
genau die Stelle, an die niemand ein zweites Mal schaut.

## Die zwei Regeln

| trifft Konto | verlangt Konto | Grund |
|---|---|---|
| `4020` Gewährte Skonti und Erlösschmälerungen (umsatzsteuerpflichtig) | `3100`–`3110` Umsatzsteuer | § 17 Abs. 1 Satz 1 — ohne Korrektur wird zu **viel** Umsatzsteuer angemeldet |
| `5010` Erhaltene Skonti und Nachlässe (vorsteuerpflichtig) | `1500`–`1510` Vorsteuer | § 17 Abs. 1 Satz 2 — ohne Korrektur bleibt zu **viel** Vorsteuer gezogen |

Verstoß ist `E_COMBINATION_REQUIRED`. Geprüft wird über die **ganze Buchung**, nicht über Seiten:
im richtigen Beleg liegen Skonto und Steuerkorrektur auf *derselben* Seite (beide im Soll, die
Forderung im Haben), ein Prädikat über Soll/Haben hätte den Fall verfehlt, für den es gebaut ist.

## Warum die Pflicht im **Konto** steht und nicht in der Regel

Das Prädikat sieht genau eine Buchung. Es kann deshalb nicht fragen, ob der ursprüngliche Umsatz
steuerpflichtig war — dazu müsste es über Buchungen hinwegsehen, was es ausdrücklich nicht kann.
Eine Regel „jede Erlösschmälerung braucht eine Steuerkorrektur" würde die Minderung einer
innergemeinschaftlichen Lieferung zu Unrecht ablehnen, und ein Constraint, der mehr verweigert als
verlangt, ist schlechter als keiner.

Die Antwort ist deshalb der Kontenrahmen: **`4020` und `5010` sind die steuertragenden
Minderungskonten**. Eine steuerfreie Minderung wird gegen das Ertrags- oder Aufwandskonto gebucht,
das sie mindert (`4030` bei igL, `4040` beim Kleinunternehmer, das jeweilige Aufwandskonto auf der
Eingangsseite) und trifft die Regel gar nicht erst. Das ist auch die Praxis der gängigen deutschen
Kontenrahmen, die Skontokonten nach Steuersatz führen — aus genau diesem Grund.

## Was sich am Kontenrahmen geändert hat (`de-konten` 2026.3 → 2026.4)

- **`4020` heißt jetzt „Gewährte Skonti und Erlösschmälerungen (umsatzsteuerpflichtig)".** Die
  Nummer, der Typ und jede bestehende Buchung bleiben unberührt; der Zusatz sagt, was die Regel
  ohnehin erzwingt. **Migrationshinweis:** wer bisher steuerfreie Minderungen auf `4020` gebucht
  hat, bekommt ab `de@2026.8` `E_COMBINATION_REQUIRED` und bucht sie künftig gegen das Ertragskonto.
- **`5010` „Erhaltene Skonti und Nachlässe (vorsteuerpflichtig)" ist neu.** Der Rahmen hatte für die
  Eingangsseite gar kein Minderungskonto — erhaltene Skonti landeten direkt im Wareneinsatz, und
  damit gab es kein Konto, an dem die Pflicht hängen konnte. Es liegt im Bereich `5000`–`5999` und
  wird von GuV-Position 3 (Materialaufwand) und EÜR-Position A1 ohne Änderung mitgeführt; ein
  Habensaldo auf einem Aufwandskonto ist genau das, was eine Aufwandsminderung ist.

## Was das Modul nicht kann

Es constrainte gern die **Verrechnung** selbst — `settle` bucht aber nicht, sondern nimmt die
Buchung der Anwendung entgegen und rechnet die Zuordnung ab. Dort gibt es keine Buchung, an der ein
Prädikat ansetzen könnte. A-13 wird deshalb über die *Buchung* erreicht, die die Anwendung für das
Skonto macht — die Stelle, an der die Bücher tatsächlich falsch würden. Die Hälfte, die in `settle`
säße, bleibt Sache der Anwendung und steht so in `docs/gobd-conformance.md`.

Fristen („Skonto nur binnen zehn Tagen") kann es ebenfalls nicht: dazu müsste es das
Rechnungsdatum der *anderen* Buchung kennen.

## Fixture

`testing/testsuite/fixtures/pack/de-pack/de-entgeltminderung-erzwungen.json` — beide Verstöße, beide
korrekten Buchungen und der steuerfreie Fall, der durchlaufen muss.
