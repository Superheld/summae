# Subdomains und Bounded Contexts — vorläufige Skizze

> **Überholt (2026-06-07):** Abgelöst durch `context-map.md` (Phase 2). Bleibt als Historie der Hypothesenbildung erhalten.

**Status: vorläufig, aber präzise.** Erste Hypothese nach dem Fachwissens-Durchgang. Wird in Phase 2 gegen konkrete Anwendungsfälle geprüft und sicher umgebaut. Taktische Muster (Aggregate, Entities) kommen bewusst **noch nicht** — erst wenn diese Grenzen halten.

## Subdomain-Hypothese

| Subdomain | Einstufung | Begründung |
|---|---|---|
| Buchungskern (Journal, Konten, Perioden, Festschreibung) | **Core** | das Differenzierende: korrekt, GoBD-fest, implementierungsübergreifend identisch. Hier liegt der Hauptaufwand |
| Datenformat & Konformität (Schema, Versionierung, Testsuite) | **Core** | Kompatibilität ist das Produktversprechen |
| Umsatzsteuer | Supporting | regelintensiv, aber bekanntes Terrain; hängt am Kern |
| Auswertungen (Bilanz, GuV, EÜR, SuSa, BWA) | Supporting | Projektionen über Kern + Mapping-Daten |
| Kontenrahmen-/Stammdatenpflege | Generic | Datenpflege; ggf. aus DATEV-/amtlichen Quellen übernehmbar |
| Haushalt/Budget (kommunal) | Supporting, **später** | Modell hält die Tür offen; Ausarbeitung verschoben (2026-06-07) |
| KLR | Supporting, **früh benötigt** | erste Package-Generation: Dimensionen im Kern, Abgrenzungsrechnung, BAB (2026-06-07) |

*Achtung Premature-Generic-Falle:* „Kontenrahmenpflege = generic" ist Hypothese. Falls sich zeigt, dass Kontenrahmen-Versionierung mit Gültigkeitszeiträumen ein Differenzierungsmerkmal wird, wandert sie hoch.

## Bounded-Context-Kandidaten

Begründung jeweils über Sprachwechsel (siehe Glossar, „Bekannte Begriffskonflikte"):

1. **Buchführung (Ledger)** — Journal, Konto, Buchung, Periode, Storno, Festschreibung. Sprache: Soll/Haben. Sowohl HGB- als auch kommunale Buchführung sprechen diese Sprache → Hypothese: *ein* Kontext mit Konfiguration, **kein** Kontext-Split zwischen HGB und kommunal auf Buchungsebene.
2. **Haushaltswirtschaft (Budgeting)** — Haushaltsplan, Ansatz, Obligo, Deckungsfähigkeit, Bewirtschaftung. Sprache deutlich anders („Budget" als Rechtsnorm, Verfügbarkeitsprüfung *vor* Buchung). Konsumiert Buchführungs-Ereignisse, prüft gegen Ansätze.
3. **Steuern (Tax)** — Steuerschlüssel, Soll/Ist-Versteuerung, Voranmeldungs-Kennzahlen. Erzeugt/ergänzt Buchungspositionen nach Regeln.
4. **KLR (Costing)** — Kosten ≠ Aufwand ist der klarste Sprachbruch im ganzen Projekt → sicherster BC-Kandidat. Konsumiert Fibu-Daten (Abgrenzungsrechnung als ACL-artige Übersetzung!), führt eigene Verrechnungen.
5. **Auswertung (Reporting)** — Bilanz-/GuV-Gliederung, Anlage-EÜR-Kategorien, SuSa. Reine Projektionen + Mappings; ob das ein eigener Kontext oder Teil von Ledger ist: offen.
6. **Anlagen (Assets)** — Anlageverzeichnis, AfA-Läufe. Klassisches Nebenbuch; Kandidat für eigenen Kontext (eigene Sprache: Nutzungsdauer, Restbuchwert, AfA-Methode).

## Erste Context-Map-Hypothesen (zu prüfen)

- Ledger ist **Upstream** für fast alles (Tax teils vorgelagert: Steuerschlüssel-Regeln erzeugen Positionen *beim* Buchen — Beziehung genau klären!).
- KLR ← Ledger: **Anticorruption Layer** = Abgrenzungsrechnung (fachlich existiert sie schon — schöner Fall, in dem die Domäne das Muster selbst mitbringt).
- Budgeting ← Ledger: konsumiert Buchungs-Ereignisse; Verfügbarkeitsprüfung ist aber *synchron vor* der Buchung → Spannung, die die Kontextgrenze testen wird.
- EÜR: kein eigener Kontext laut Hypothese, sondern Konfigurationsprofil von Ledger + Reporting (Zufluss/Abfluss-Projektion). **Gegenhypothese ernst nehmen:** falls EÜR-Regeln (10-Tage, USt-erfolgswirksam) den Kern verbiegen, wird es doch ein Kontext.

## Was diese Skizze NICHT ist

Keine Festlegung auf Module, Services oder Deployment. Bounded Context = Bedeutungsgrenze. Ob daraus je Kontext ein Package, ein Modul oder nur ein Namespace wird, ist eine spätere, unabhängige Entscheidung.
