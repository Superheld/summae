# Abdeckungsstand

**Die Zahlen stehen nicht hier, sondern in `python3 validate.py`** — sie driften schneller, als sie jemand nachzieht (bis 2026-08-17 behauptete diese Datei 58 Fixtures und 36/38 Codes, real waren es dreistellig bzw. 40/44). Das Skript zählt Fixtures sowie Fehlercode- und Standardfall-Abdeckung gegen den Bestand; grün/rot liefert der Runner (`make fixtures` bzw. `pnpm fixtures --strict`). Was hier bleibt, ist das, was ein Werkzeug nicht sagen kann: welche Lücken bewusst offen sind und warum.

## Fehlercodes

Ohne Fixture, mit Grund: `E_AMOUNT_SCALE_MISMATCH` ist seit 2026-08-28 gebaut (IMPL-040), sitzt aber auf der **Persistenz-Ebene** und ist über die Suite nicht erreichbar — einen Bestand mit falscher Stellenzahl kann diese Engine nicht erzeugen; belegt durch `AmountScaleTest` / `amount-scale.test.ts` in beiden Sprachen, beide Richtungen. `E_WORKSPACE_INVALID` und `E_NOT_IMPLEMENTED` sind über Fixtures ebenfalls nicht erreichbar und werden pro Sprache durch einen Kontrakt-Test geprüft; `E_UNEXPECTED` ist bewusst kein Katalogcode und wird von `validate.py` nur mitgezählt, weil dessen Regex auch Fließtext greift. `E_POLICY_INVALID` hat seit 2026-08-16 die Fixture `resolver-policy-invalid`.

## Standardfälle

**Auch hier zählt `validate.py`, nicht diese Überschrift.** Sie lautete bis 2026-08-28 „26 / 26 ✅"
und der Absatz darunter „alle SF-01…26" — während fünf Fixtures längst SF-27 abdeckten und das
Skript ihn mitzählte. Bemerkenswert daran ist die Richtung des Fehlers: die Zahl war nicht zu
optimistisch, sondern zu klein, und ein Standardfall existierte nur in der Zählung, weil
`lieferumfang.md` bei SF-26 endete. Beides ist nachgetragen.

SF-15 (Cross-Implementierung: Datenbestand aus Runtime A in B weiterführen) ist der einzige
Standardfall **ohne** Fixture und das mit Absicht: er ist mit der **zweiten Runtime** (Node) erfüllt
und wird vom bidirektionalen PHP↔Node-Cross-Test belegt, nicht von der Suite — Protokoll in
`README.md`. Jeder andere deklarierte Standardfall hat mindestens eine Fixture.

## Determinismus-Pflichtfälle: 5 / 5 ✅

half-up-Falle · USt pro Beleg · allocate largest-remainder · Sortierung führende Nullen · AfA-Monatsraten (1–28 je 27,78, 29–36 je 27,77, Σ exakt 1.000,00).

## Entstehungsgeschichte (Kurz)

Die Suite wuchs über die Versionen: v0.1–0.2 Grundgerüst, v0.3 (Review `review-2026-06-07.md`: Jahresübergang, Skonto/§17, Audit, Reverse Charge, Anzahlung, Wertabgabe …), v0.4 (Buchhalter-/StB-Review: Partner/ZM, Lohn, Bewirtung, Gutschrift, Ergebnisverwendung, Geldtransit, Leistungsdatum, Monats-GuV …), v0.5 (PHP-Findings SPEC-001–007: neue Fehlercodes, Bilanz-`side`, Asset-Konten, Export-Manifest). Detaillierte Befund→Fixture-Zuordnung in den jeweiligen Review-Dateien und im Entscheidungslog.

## Lücken-Befund Gesamt-Review 2026-06-09 (Eigenprüfung: 2 von 4 echt)

Von vier gemeldeten Lücken hielten zwei der Prüfung nicht stand: **§ 17-Korrektur-VA** ist abgedeckt (settlement-discount und settlement-bad-debt projizieren die VA-Korrektur), **Generalumkehr-Vorzeichen** ebenfalls (finalize-reverse-period prüft die negierten Zeilenbeträge). Die zwei echten Lücken sind als Fixtures gebaut (2026-06-09):

1. **`tax/vat-return-reversal`** — Storno → USt-VA: `baseMoney` unverändert kopiert, VA-Projektion negiert per Vorzeichen der Steuerposition (datenformat.md v0.5/SPEC-008); Original Q2, Storno Q3 → ±1000/±190.
2. **`tax/vat-return-cash-basis-rounding`** — Ist-Versteuerung mit krummen Teilzahlungen (400/400/390 auf 1190): anteilige Rundung half-up, Schlusszahlung erhält den Rest (determinismus.md v0.3); schärft `vat-return-cash-basis` (dort glatte 50/50-Teilung).

**Verifikation 2026-06-14 (beide gegen die PHP-Referenz gelaufen):** beide ursprünglich rot — und beide aus unterschiedlichem Grund:
1. `vat-return-cash-basis-rounding` — die erwartete Basis war centgenau (336.13 …), die VA floort aber auf volle Euro (`determinismus.md` Z. 40). **Fixture-Erwartung korrigiert** (SPEC-010): Basis 336/336/327, Steuer unverändert centgenau. Folge sichtbar gemacht: Σ gefloorter Basen = 999 ≠ 1000 (RQ-2).
2. `vat-return-reversal` — die VA-Projektion ordnete das Storno über den geerbten Beleg ins Original-Quartal ein → saldierte sich weg. **PHP gefixt** (SPEC-011): reversierende Buchungen zählen nach eigenem Buchungsdatum (§ 17). Spec-Semantik vorläufig bis RQ-1.

## Fazit

**Kern-Vertrag erfüllt für PHP- und Node-Referenz.** Eine Implementierung, die die Kern-Fixtures unter `fixtures/core/` (plus Determinismus-Doppellauf) besteht, ist für den Buchungskern konform; die Fixtures unter `fixtures/pack/` erweitern den Vertrag um die Resolver-Ebene. *(Hier standen „45 Kern-Fixtures" und „13 Pack-Fixtures" — beide seit langem falsch, in derselben Datei, deren erste Zeile sagt, dass Zahlen hier nicht stehen. Ein Verzeichnisname veraltet nicht.)* Zwei fachliche Rückfragen offen (RQ-1/RQ-2, `40-domaenenmodell/offene-fragen.md`) — beide betreffen nur die VA-Darstellungs-/Zuordnungssemantik, nicht den Buchungskern. SF-15 ist mit der zweiten Runtime (Node) erfüllt: bidirektionaler Cross-Test grün.
