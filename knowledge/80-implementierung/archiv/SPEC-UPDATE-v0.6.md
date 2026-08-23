# Spec-Update v0.6 (2026-06-08) — für die PHP-Referenz: (fast) nichts zu tun

Diese Runde war **redaktionell + Nordstern-Verschiebung**, kein Vertragsänderung. Für die Implementierung heißt das:

## Kein funktionaler Umbau nötig

- **Keine Fixture hat sich geändert.** Die Suite ist unverändert **43 Fixtures / 34 Fehlercodes** (`validate.py` grün). Eine Implementierung, die v0.5 bestanden hat, ist weiterhin konform.
- **F-008 und F-009 beschreiben euer bestehendes Verhalten**, sie ändern es nicht:
  - F-008: Generalumkehr kopiert `taxTag.baseMoney`, die VA-Projektion negiert die Basis bei negativen Steuerzeilen — laut Abschlussbericht genau so umgesetzt (VatReturnProjection). Nur in der Spec explizit gemacht.
  - F-009: Maschinell erzeugte Buchungen (Asset/AfA) werden sofort festgeschrieben — laut Abschlussbericht Adapter-Annahme #1. Jetzt Spec-Regel.
  - **Wenn eure Implementierung sich hier anders verhält als beschrieben → das ist ein Finding, bitte melden.** Sonst nichts zu tun.

## Was sich konzeptionell geändert hat (zur Kenntnis, nicht zum Umbau)

- **Nordstern: OSS-Produkt namens „Summae"** (jurisdiktionsfreier Kern + Länder-Packs). DE ist das erste Pack, nicht der Scope.
- **Architektur benannt:** Substrat (jurisdiktionsfreie Buchungs-Algebra) / Politiksorten (Constraint·Projektion·Expansion) / Pack. Schichtenzuordnung: `40-domaenenmodell/jurisdiction-profil.md`. Eure Drei-Schichten-Struktur (Kern framework-frei + Adapter) entspricht dem bereits.

## Optional, vorausschauend (kein Muss)

- **DE-Werte als expliziten Pack kapseln:** Steuerschlüssel-Inhalte, SKR-Daten, Mappings, AfA-Tabellen, GWG-Grenzen sind schon „App-Schicht-Daten" (Adapter-Annahme #2). Sie als benanntes `pack-de`-Bündel zusammenzufassen macht den Pack-Begriff im Code sichtbar und erleichtert das zweite Pack.
- **Reserviert für später (nachfragegetrieben, noch NICHT im Format):** Pack-Policy-Felder `roundingMode`, `taxRoundingGranularity`, Währungs-`scale`. Solange sie fehlen, sind half-up / pro Beleg / EUR-2 die fest verdrahteten DE-Werte — korrekt. Kommt erst mit dem zweiten Pack.

## Fazit

PHP-Referenz bleibt der Goldstandard. Kein Rebuild. Diese Notiz ist Information, kein Auftrag.
