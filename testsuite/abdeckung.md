# Abdeckungsstand (2026-06-14)

Automatisch prüfbar via `python3 validate.py`. **45 Fixtures — alle von der PHP-Referenz bestanden** (45/45 grün gegen In-Memory, Doppellauf deterministisch, PHPStan max, 93 Unit-Tests; verifiziert 2026-06-14). Die zwei am 2026-06-09 ergänzten Steuer-Fixtures (`vat-return-reversal`, `vat-return-cash-basis-rounding`) sind damit verbindlich; ihre Verifikation förderte F-010 (Fixture-Erwartung korrigiert) und F-011 (PHP-Bug gefixt) zutage — `../80-implementierung/SPEC-FINDINGS.md`.

## Fehlercodes: 34 / 34 ✅

Alle Codes aus `50-spezifikation/fehlerkatalog.md` haben ≥ 1 Fixture.

## Standardfälle: 25 / 26 ✅ (offen nur SF-15)

SF-15 (Cross-Implementierung: Datenbestand aus Runtime A in B weiterführen) ist per Definition erst mit der **zweiten Runtime** (Node) testbar — Cross-Test-Protokoll in `README.md`. Alle übrigen SF-01…26 haben mindestens eine Fixture.

## Determinismus-Pflichtfälle: 5 / 5 ✅

half-up-Falle · USt pro Beleg · allocate largest-remainder · Sortierung führende Nullen · AfA-Monatsraten (1–28 je 27,78, 29–36 je 27,77, Σ exakt 1.000,00).

## Entstehungsgeschichte (Kurz)

Die Suite wuchs über die Versionen: v0.1–0.2 Grundgerüst, v0.3 (Review `review-2026-06-07.md`: Jahresübergang, Skonto/§17, Audit, Reverse Charge, Anzahlung, Wertabgabe …), v0.4 (Buchhalter-/StB-Review: Partner/ZM, Lohn, Bewirtung, Gutschrift, Ergebnisverwendung, Geldtransit, Leistungsdatum, Monats-GuV …), v0.5 (PHP-Findings F-001–007: neue Fehlercodes, Bilanz-`side`, Asset-Konten, Export-Manifest). Detaillierte Befund→Fixture-Zuordnung in den jeweiligen Review-Dateien und im Entscheidungslog.

## Lücken-Befund Gesamt-Review 2026-06-09 (Eigenprüfung: 2 von 4 echt)

Von vier gemeldeten Lücken hielten zwei der Prüfung nicht stand: **§ 17-Korrektur-VA** ist abgedeckt (settlement-discount und settlement-bad-debt projizieren die VA-Korrektur), **Generalumkehr-Vorzeichen** ebenfalls (finalize-reverse-period prüft die negierten Zeilenbeträge). Die zwei echten Lücken sind als Fixtures gebaut (2026-06-09):

1. **`tax/vat-return-reversal`** — Storno → USt-VA: `baseMoney` unverändert kopiert, VA-Projektion negiert per Vorzeichen der Steuerposition (datenformat.md v0.5/F-008); Original Q2, Storno Q3 → ±1000/±190.
2. **`tax/vat-return-cash-basis-rounding`** — Ist-Versteuerung mit krummen Teilzahlungen (400/400/390 auf 1190): anteilige Rundung half-up, Schlusszahlung erhält den Rest (determinismus.md v0.3); schärft `vat-return-cash-basis` (dort glatte 50/50-Teilung).

**Verifikation 2026-06-14 (beide gegen die PHP-Referenz gelaufen):** beide ursprünglich rot — und beide aus unterschiedlichem Grund:
1. `vat-return-cash-basis-rounding` — die erwartete Basis war centgenau (336.13 …), die VA floort aber auf volle Euro (`determinismus.md` Z. 40). **Fixture-Erwartung korrigiert** (F-010): Basis 336/336/327, Steuer unverändert centgenau. Folge sichtbar gemacht: Σ gefloorter Basen = 999 ≠ 1000 (RQ-2).
2. `vat-return-reversal` — die VA-Projektion ordnete das Storno über den geerbten Beleg ins Original-Quartal ein → saldierte sich weg. **PHP gefixt** (F-011): reversierende Buchungen zählen nach eigenem Buchungsdatum (§ 17). Spec-Semantik vorläufig bis RQ-1.

## Fazit

**Vertrag erfüllt für die PHP-Referenz.** Eine Implementierung, die die **45** Fixtures (plus Determinismus-Doppellauf) besteht, ist konform. Zwei fachliche Rückfragen offen (RQ-1/RQ-2, `40-domaenenmodell/offene-fragen.md`) — beide betreffen nur die VA-Darstellungs-/Zuordnungssemantik, nicht den Buchungskern. SF-15 wird mit der zweiten Runtime (Node) scharf geschaltet.
