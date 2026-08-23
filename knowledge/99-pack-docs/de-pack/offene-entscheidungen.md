# Offene Entscheidungen — DE-Pack

Was vor / während des Builds menschlich entschieden werden muss. Konto-Nummern sind
quasi-irreversibel (sobald gebucht), darum zuerst.

## A — Die fünf DE-Konto-Nummern (irreversibel, Sign-off zuerst)

| Nr | Konto | Status |
|---|---|---|
| 4020 | Gewährte Skonti / Erlösschmälerung | Vorschlag, ok? |
| 4030 | Steuerfreie ig. Lieferungen | Vorschlag, ok? |
| 6010 | Bewirtung abziehbar (70 %) | Vorschlag, ok? |
| 6020 | Bewirtung nicht abziehbar (30 %) | Vorschlag, ok? |
| 4040 | **Unentgeltliche Wertabgaben** | **neu** — eigenes Konto oder still auf 4000? |

Punkt 4040 ist die vom ursprünglichen Review übersehene fünfte Lücke (Modul 1). Empfehlung:
eigenes Konto (sauberer EÜR-Ausweis E4/E5, GuV-Position 1c). Alternative: auf 4000 kollabieren
lassen (weniger Konten, aber Wertabgabe nicht mehr getrennt sichtbar).

## B — RC13b- und Wertabgabe-Steuerkonten (Design, verändert keine Nummern)

§13b und Wertabgabe im tax-Modul (Modul 2) auf die **Standard-Konten 3100/1500** legen und
nur per Kennzahl trennen — **oder** eigene Konten anlegen (Vorschlag: 3120 USt §13b, 1520 VSt
§13b, 3130 USt Wertabgabe).

- Fixtures nutzen eigene SKR-Konten (1787/1577 bzw. 1779).
- Funktional ist Kennzahl-Trennung (47/67) VA-korrekt; eigene Konten sind nur buchhalterische
  Sichtbarkeit. Entscheidet, wie Modul 2 geschrieben wird.

## C — `taxationMethod`-Default des DE-Packs

Kanonisch heute `cash` (EÜR-Profil). Bestätigen oder auf `accrual` (Bilanzierer) stellen —
oder zwei Manifeste (`de-euer` cash / `de-bilanz` accrual). Pro Mandant ohnehin überschreibbar.

## D — Doku-Korrektur in `datenformat.md` (kein Sign-off, nur Pflege)

Das `de-complete`-Beispiel referenziert noch `summae-base` / `summae-base-asset-accounts`.
Nach der Eigenständigkeits-Entscheidung muss es `de-konten-2026` / `de-asset-accounts` heißen.
Engine/Resolver unberührt — nur Modul-`id`s. (Begleitend: klären, was `neutral`/`summae-base`
künftig ist — Kontenrahmen des `default`-Packs + Vorlage, **nicht** DE-Abhängigkeit.)

## E — Fachliche Rückfragen (laufen mit plausibelster Lesart, vor „normativ" bestätigen)

Beide in `40-domaenenmodell/offene-fragen.md`, Rechtsstand 06/2026 maßgeblich.

- **RQ-1 — Storno-VA-Zeitraum.** Aktuell: Storno zählt im VA-Zeitraum des **eigenen
  Buchungsdatums** (§17 Abs.1 S.7 UStG). Trägt das auch reine Fehlbuchungen / §14c-Fälle, oder
  ist dann die Original-VA zu berichtigen? Muss `reverse` den Anlass unterscheiden?
- **RQ-2 — Euro-Abrundung der VA-Basis.** Aktuell: Bemessungsgrundlage je Kennzahl auf volle
  Euro **abgerundet** (nicht kaufmännisch), Steuer centgenau. Richtung + Anwendung auf die
  Kennzahlen-Summe bestätigen; nicht summenerhaltend → wie behandelt die Jahreserklärung das?

## F — Fehler-Fixtures (Code da, Fixture fehlt — Gate-2-Backlog)

`E_POLICY_INVALID`, `E_AMOUNT_SCALE_MISMATCH`, I6/I7/I8 — nicht DE-inhaltlich, aber Teil der
Pack-Reife. `00-projekt/backlog-pack-gate2.md`.

## VSt7 (klein)

In keiner Fixture belegt, nur im Profil `de-freiberufler-euer` gelistet. Beim Build mit
Fachstand bestätigen (Kennzahl 66, Konto 1510).
