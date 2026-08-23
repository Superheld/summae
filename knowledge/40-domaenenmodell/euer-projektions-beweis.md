# EÜR-Projektions-Beweis

**These (Entscheidung 2026-06-07):** Ein doppisches Journal genügt — die EÜR ist eine Projektion darüber, kein eigenes Buchungsmodell.

**Ergebnis: Beweis erbracht.** Prototyp `60-prototyp/archiv/euer_projektion.py` (Wegwerf, seit dem Beweis archiviert) rechnet alle 8 Testfälle korrekt (Lauf 2026-06-07, alle grün); die Regeln R1–R6 leben produktiv in den Referenz-Implementierungen. Die Fixtures sind Vorarbeit für die Konformitäts-Testsuite (SF-08).

## Externe Bestätigung (Recherche 06/2026)

- **Lexware Office** arbeitet intern mit doppelter Buchführung, unabhängig davon, ob der Nutzer EÜR oder Bilanz wählt — gleicher Ansatz.
- **QuickBooks** erzeugt Cash-Basis-Berichte als Konversion aus dem Accrual-Ledger. Dokumentierte Schwäche: Die Konversion ist heuristisch („entferne Unbezahltes") und in Randfällen falsch (verknüpfte Bestandskonten, Steuerverbindlichkeiten). **Lehre:** explizite Regeln pro Kontotyp statt Heuristik.
- **Xero** löst es per Flag an jeder Buchung — funktioniert, verlagert aber die Korrektheit auf den Erfasser. Verworfen.
- **10-Tage-Regel:** Selbst Lexware Office bildet sie nicht automatisch ab (offizieller Workaround: manuelle Korrektur). Unsere Regelmodul-Lösung wäre über Marktstandard.

## Projektionsregeln

- **R1 Zahlungswirksamkeit:** EÜR-relevant sind Buchungen, die ein Geldkonto berühren. Bei OP-Ausgleich (Zahlung auf Forderung/Verbindlichkeit) werden die Kategorien **über den OP-Link aus dem Ursprungsbeleg** geholt (Zahlung → Rechnung → Erlöskonto). ⚠️ Härteste Anforderung der Projektion: ohne saubere OP-Verknüpfung keine EÜR-Kategorie.
- **R2 10-Tage-Regel:** Wiederkehrende Zahlung, **gezahlt UND fällig** im Fenster 22.12.–10.01. → Jahr der wirtschaftlichen Zugehörigkeit. Fälligkeit außerhalb (z. B. Dauerfristverlängerung, BFH 13.12.2022) → keine Umqualifizierung. Benötigte Buchungs-Metadaten: `recurring`, `due`, `economic_year`.
- **R3 USt erfolgswirksam:** USt-/Vorsteuer-Positionen zählen beim Zahlungsfluss als Einnahme/Ausgabe (in der Doppik-Sicht bleiben sie erfolgsneutral — dieselben Buchungen, zwei korrekte Sichten).
- **R4 Anlagen:** Zahlung aufs Anlagekonto ist nicht abziehbar; die (nicht zahlungswirksame!) AfA-Buchung zählt als Betriebsausgabe ihres Buchungsjahres. GWG via Sofortabschreibungskonto.
- **R5 Erfolgsneutrale Zahlungen:** Darlehen (nur Zins zählt), Privat, durchlaufende Posten → keine Einnahme/Ausgabe.
- **R6 Kategorien-Mapping:** Konto → Anlage-EÜR-Zeile ist Regelmodul-Inhalt (hier vereinfacht: Kontoname).
- **R7 Nicht zahlungswirksame Pflichtkategorien (v0.3):** Positionen auf Konten, die das cash-basis-Mapping als `includeNonCash` markiert, zählen im Buchungsjahr ohne Zahlungsfluss — AfA als Ausgabe (formalisiert die R4-Sonderbehandlung), unentgeltliche Wertabgaben als Einnahme (Review M7). Abweichende Geschäftsjahre lehnt die EÜR-Projektion ab (`E_CASHBASIS_DEVIATING_FISCAL_YEAR`) — EÜR ist kalenderjahrgebunden.

## Testfälle

| TC | Fall | Erwartung | Ergebnis |
|---|---|---|---|
| 1 | Rechnung 11.12.25 (1.000 + 190 USt), Zahlung 15.01.26 | Doppik-Ertrag 2025; EÜR-Einnahme 2026 (1.000 + 190) | ✅ |
| 2a | USt-VA Dez 25, fällig 10.01., gezahlt 08.01.26 | EÜR-Ausgabe **2025** (10-Tage-Regel) | ✅ |
| 2b | wie 2a, gezahlt 15.01.26 | EÜR-Ausgabe 2026 (Fenster verfehlt) | ✅ |
| 2c | Dauerfrist: fällig 10.02., gezahlt 05.01.26 | EÜR-Ausgabe 2026 (BFH: Fälligkeit außerhalb) | ✅ |
| 3 | Eingangsrechnung 100 + 19 VSt, sofort bezahlt | Ausgabe 100 + VSt 19 getrennt | ✅ |
| 4 | Anlagekauf 3.000, ND 3 J., Kauf 01.07.25 | keine Sofortausgabe; AfA 500 (6/36); VSt 570 bei Zahlung | ✅ |
| 5 | GWG 600 netto | Sofortabzug 600 | ✅ |
| 6 | Darlehen 10.000 auf, Rate 1.000 + 200 Zins | nur 200 Ausgabe | ✅ |
| 7 | Durchlaufender Posten 500 rein/raus | neutral | ✅ |
| 8 | Privatentnahme 2.000 | neutral | ✅ |

## Erkenntnisse für das Modell (über den Beweis hinaus)

1. **OP-Verknüpfung ist Pflicht im Kern**, nicht Komfort: Die EÜR-Projektion braucht den Pfad Zahlung → Ursprungsbeleg → Kategorie (R1). Bestätigt F-CORE-003 und erweitert sie: Zahlungen referenzieren ausgeglichene Posten.
2. **Buchungs-Metadaten:** `recurring`, `due` (Fälligkeit) und `economic_year` müssen am Beleg erfassbar sein — sonst ist R2 nicht automatisierbar (genau daran scheitert Lexware).
3. **Eine Buchung, zwei Sichten:** Dieselbe USt-Position ist doppisch erfolgsneutral und in der EÜR erfolgswirksam (R3) — die Projektion interpretiert, das Journal bleibt unangetastet. Das ist der Kern der These, und er trägt.
4. **Teilzahlungen** (anteiliger OP-Ausgleich) fehlen im Prototyp — als Fixture für die echte Testsuite vormerken (proportionale Kategorie-Aufteilung).
5. Prototyp ist Wegwerfcode; die Regeln R1–R6 und die Fixtures sind das bleibende Ergebnis.
