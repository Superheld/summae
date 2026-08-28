# Offene Entscheidungen — DE-Pack

**Durchgesehen am 2026-08-28.** Diese Liste stammt aus der Zeit *vor* dem Build und fragte nach
Sign-offs für Kontonummern, die seit Monaten bebucht werden. Fünf ihrer sechs Punkte waren
entschieden — nicht durch eine Entscheidung, sondern **dadurch, dass gebaut und ausgeliefert
wurde**, und niemand ist zurückgekommen, um sie abzuhaken.

Das ist die schädlichste Sorte veralteter Liste, und zwar aus einem konkreten Grund: sie beschrieb
`4040` als „Unentgeltliche Wertabgaben" und bat um Sign-off dafür. Im ausgelieferten Kontenrahmen
ist `4040` **Erlöse Kleinunternehmer (steuerfrei § 19 UStG)** und die Wertabgabe liegt auf `4050`.
Wer sich auf diese Liste verlassen hätte, hätte eine Regel auf das falsche Konto gelegt — und genau
das ist am 2026-08-28 beinahe passiert, als das Kleinunternehmer-Constraint gebaut wurde.

Erledigtes steht deshalb unten mit dem Stand, gegen den es geprüft wurde, statt gelöscht zu werden:
eine Entscheidung ohne ihre Begründung wird vom nächsten Leser neu aufgemacht.

---

## Noch offen

### RQ-1 / RQ-2 — fachliche Rückfragen (laufen mit der plausibelsten Lesart)

Der **einzige** Punkt dieser Liste, an dem heute Code mit einer unbestätigten Lesart läuft. Beide
stehen ausführlich in [`../../40-domaenenmodell/offene-fragen.md`](../../40-domaenenmodell/offene-fragen.md);
Rechtsstand 06/2026 maßgeblich.

- **RQ-1 — Storno-VA-Zeitraum.** Aktuell: ein Storno zählt im VA-Zeitraum seines **eigenen
  Buchungsdatums** (§ 17 Abs. 1 S. 7 UStG). Trägt das auch reine Fehlbuchungen und § 14c-Fälle,
  oder ist dann die Original-VA zu **berichtigen**? Muss `reverse` den Anlass unterscheiden?
- **RQ-2 — Euro-Abrundung der VA-Basis.** Aktuell: Bemessungsgrundlage je Kennzahl auf volle Euro
  **abgerundet** (nicht kaufmännisch), Steuer centgenau. Richtung und Anwendung auf die
  Kennzahlen-Summe bestätigen; die Abrundung ist **nicht summenerhaltend** — wie behandelt das die
  Jahreserklärung?

### VSt7 (klein)

Konto `1510` Vorsteuer ermäßigter Satz, Kennzahl 66. Existiert im Kontenrahmen, ist aber von keiner
Fixture belegt. Beim nächsten fachlichen Durchgang bestätigen.

### ~~`E_AMOUNT_SCALE_MISMATCH` ohne Fixture~~ — erledigt 2026-08-28

Nicht DE-inhaltlich, aber Teil der Pack-Reife. War der einzige Katalogcode, der deklariert war und
von nichts ausgelöst wurde. Gebaut mit IMPL-040: die Prüfung sitzt auf der **Persistenz-Ebene** —
sie beurteilt einen Betrag, der schon im Bestand steht, nicht einen, den ein Aufrufer anbietet — und
ist deshalb über die Suite nicht erreichbar, wohl aber über einen Adapter-Test je Sprache
(`AmountScaleTest` / `amount-scale.test.ts`, beide Richtungen). Der Bau fand den Defekt darunter:
der Hydrator las jeden Betrag auf der ISO-Standardskala statt auf der des Mandanten, sodass ein
Mandant mit `currencyScale: 3` seine eigenen Bücher nicht zurücklesen konnte.

---

## Durch Ausliefern entschieden (nachgetragen 2026-08-28)

### A — Die DE-Konto-Nummern

Alle im ausgelieferten `de-konten` vorhanden und bebucht; die Nummern sind damit faktisch
irreversibel, was diese Liste 2026 als Grund nannte, sie *vorher* zu klären.

| Nr | Konto (ausgeliefert) | Anmerkung |
|---|---|---|
| `4020` | Gewährte Skonti und Erlösschmälerungen (umsatzsteuerpflichtig) | trägt die § 17-Regel (Modul 12) |
| `4030` | Steuerfreie innergemeinschaftliche Lieferungen | bewusst **ohne** forbid-Regel, siehe Modul 13 |
| `4040` | **Erlöse Kleinunternehmer (steuerfrei § 19 UStG)** | **nicht** „Unentgeltliche Wertabgaben" — die Liste war hier falsch |
| `4050` | Unentgeltliche Wertabgaben (Ertrag) | die Wertabgabe bekam ein eigenes Konto, wie damals empfohlen |
| `6010` / `6020` | Bewirtung abziehbar (70 %) / nicht abziehbar (30 %) | wie vorgeschlagen |

Die inhaltliche Empfehlung von damals — Wertabgabe als **eigenes** Konto statt still auf `4000` —
wurde also befolgt; nur die Nummer wurde eine andere.

### B — § 13b- und Wertabgabe-Steuerkonten

**Entschieden zugunsten der Standard-Konten mit Kennzahl-Trennung.** Es gibt kein `3120`, kein
`1520` und kein `3130`; § 13b und die Wertabgabe laufen über `3100`/`1500` und werden per Kennzahl
(47/67) getrennt. Das ist VA-korrekt, und eigene Konten wären nur buchhalterische Sichtbarkeit
gewesen.

### C — `taxationMethod`-Default

**Bestätigt `cash`** (EÜR-Profil), zusammen mit `smallBusiness: false` und `vatPeriod: quarterly`.
Kein zweites Manifest für Bilanzierer — pro Mandant ohnehin überschreibbar.

### D — `de-complete` in `datenformat.md`

**Erledigt am 2026-08-28**, und schlimmer als gedacht: das Beispiel benannte nicht nur veraltete
Modul-`id`s, sondern ein Pack `de-complete`, das es nie gab, mit Modulen (`summae-base`,
`de-ust-2026`, `afa-de`, `summae-base-asset-accounts`), von denen keines existiert. Dazu eine
Sync-Pflicht auf zwei Dokumente, die es nicht mehr gibt. Ersetzt durch das reale `de`-Manifest mit
dem Hinweis, dass die maßgebliche Liste in `pack-library/de-pack/de.json` steht.

### F — Fehler-Fixtures

`E_POLICY_INVALID` hat seit 2026-08-16 die Fixture `resolver-policy-invalid`; I6/I7/I8 sind über
die Resolver-Fixtures und `F-PACK-RESOLVE` belegt. `E_AMOUNT_SCALE_MISMATCH` ist seit 2026-08-28
gebaut und per Adapter-Test belegt (siehe oben) — damit ist die Liste leer.
