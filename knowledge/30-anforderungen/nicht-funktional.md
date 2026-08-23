# Nicht-funktionale Anforderungen (Gerüst)

## NF-1 Kompatibilität (Projektkern!)

- NF-1.1: Jede Implementierung (Laravel ✅, Node ✅, dann Python/weitere — das Format ist runtime-offen, kein geschlossenes Set) implementiert dieselbe spezifizierte API (Namen, Operationen, Fehlerfälle).
- NF-1.2: Das persistierte Datenformat ist implementierungsübergreifend identisch und versioniert; jede Implementierung kann Daten jeder anderen lesen und fortschreiben.
- NF-1.3: Eine gemeinsame Konformitäts-Testsuite (sprachneutrale Fixtures + erwartete Ergebnisse) ist der Kompatibilitätsvertrag. Eine Implementierung gilt als konform, wenn die Suite grün ist.

## NF-2 Korrektheit

- NF-2.1: Beträge als exakte Dezimalwerte; Rundungsregeln pro Operation spezifiziert und in der Testsuite verankert.
- NF-2.2: Buchungsinvarianten werden beim Schreiben erzwungen, nicht beim Lesen geprüft.
- NF-2.3: Deterministische Berechnungen: gleiche Eingabe → gleiches Ergebnis in allen Implementierungen (inkl. Sortierung, Rundung).

## NF-3 GoBD-Konformität (siehe `10-fachwissen/17-gobd-compliance.md`)

- Unveränderbarkeit nach Festschreibung, Audit-Trail für Stammdaten, lückenlose Journalfunktion, maschinelle Auswertbarkeit, Exportfähigkeit.

## NF-4 Einbettbarkeit

- NF-4.1: Die Packages sind Bibliotheken, keine Anwendungen: kein eigenes UI, kein eigener Server, keine erzwungene Datenbank — Persistenz hinter einer definierten Schnittstelle.
- NF-4.2: Mandantenfähigkeit auf Datenebene (eine Einbettung kann mehrere Mandanten führen).
- NF-4.3: Keine Annahmen über das Host-Framework außerhalb des Package-Adapters (Laravel-Adapter darf Laravel kennen, der Kern nicht).

## NF-5 Entwicklung & Pflege

- NF-5.1: Gesetzesabhängige **Werte und Regeln** (Steuersätze, Schwellen, Kontenrahmen, Buchungs- und Zuordnungsregeln) leben in Regelmodulen mit Gültigkeitszeitraum — aktualisierbar ohne Kern-Release. Jede Berechnung trägt ein Bezugsdatum und wendet die zu diesem Datum gültigen Regeln an; Neuberechnung alter Perioden liefert dauerhaft identische Ergebnisse (siehe SF-16 in `lieferumfang.md`).
- NF-5.2: Datenformat-Migrationen sind spezifiziert (Version n → n+1).

### NF-5.3 Architekturprinzip: Substrat und Politiksorten (benennt, was schon implizit gilt)

Dieses Prinzip konsolidiert, was über die Docs verstreut bereits gelebt wird (context-map.md: Auswertungen = Projektionen; tax-modell.md: `expand` = Expansion; assets-modell.md: „Mechanik ist Kern, Werte sind Daten"; NF-5.1 = tzdata-Prinzip). Es ist eine **Benennung, keine neue Architektur** — und die präzise Fassung der Drei-Schichten-Entscheidung (2026-06-07).

- **Substrat (jurisdiktionsfrei, winzig):** Eine Buchung verschiebt Wert zwischen Konten so, dass die Summe null ist; ein Konto akkumuliert Wert über Zeit; das Journal ist die append-only-Folge; ein Saldo ist eine Faltung über die Buchungen bis zu einem Zeitpunkt. Mehr nicht — kein Soll/Haben (nur Vorzeichen), keine Steuer, kein Kontenrahmen, keine Rundung. (Salden bilden eine abelsche Gruppe; bekannter Grund: REA-Modell, Event Sourcing.)
- **Jedes variable Feature ist genau eine von drei Politiksorten:**
  - **Constraint** — ein Prädikat, das gelten muss (Periode offen, Beleg vollständig, Festschreibung unverletzt). Das Substrat hat nur das universelle „Summe = 0".
  - **Projektion** — reine Funktion vom Journal auf eine Sicht. Bilanz, GuV, EÜR, USt-VA, SAF-T, FEC, DATEV sind alle nur Projektionen; sie unterscheiden sich im *Mapping*, nicht im Mechanismus.
  - **Expansion** — Funktion von Absicht zu Buchungen. `expandTax`, AfA-Lauf, Skonto-`settle` sind Expansionen; sie unterscheiden sich in der *Regel*, nicht im Mechanismus. Eine Expansion ist **Sockel** (gesetzesfreier Mechanismus „Absicht → ausbalancierte Buchungen") + **Stecker** (Regel/Parameter, die den Split entscheiden). Steuer ist der prominenteste Stecker, nicht der einzige (Abgrenzungs-Split, Belegkomposition, Rundungs-Allokation sind Expansionen ohne Paragraph).
- **Komposition statt Geo-Vererbung — die treibende Idee, aus der das Übrige folgt:** Eine Jurisdiktion wird *zusammengesetzt*, nicht *abgeleitet*. Eine starre Hierarchie Kern → Region → Land ist die falsche Achse (Bewertungsregeln folgen nicht der Geografie: ein EU-Land kann IFRS *oder* nationalen Abschluss fahren). Stattdessen: Kern (Mechanik) / komponierbare Module (Constraints + Projektionen + Expansionen, manche regional gebündelt) / lokales **Jurisdiction Profile (Pack)**. „Region" ist ein Default-Bündel, kein Strukturzwang. Substrat + Politiksorten + Pack sind die *Bauteile*, die genau diese Komponierbarkeit liefern; dass sie sicher ist, garantiert die Abgeschlossenheit des Substrats unter Komposition (abelsche Gruppe). Siehe `40-domaenenmodell/jurisdiction-profil.md`.
- **Abbruchkriterium (gegen unendliches Zerlegen):** Das Substrat ist tief genug, wenn das DE-Pack **null** Kernänderung braucht *und* ein bewusst schräges fiktives Pack (3-Nachkomma-Währung, Rundung je Position, kein Vorsteuerabzug) ohne Kernänderung liefe. Muss der Kern für eines angefasst werden → nicht tief genug. Braucht man ein Primitiv, das keine reale Jurisdiktion nutzt → zu tief (YAGNI).
- **Zwei Achsen, ein Zensus:** Die Architektur wird über zwei Ansichten in `40-domaenenmodell/jurisdiction-profil.md` prüfbar gemacht — die **Schichtenzuordnung** (Rosetta: Kern/Pack/App) und der **Politiksorten-Zensus** (Substrat / Constraint / Projektion / Expansion). Erst beide zusammen belegen die Symmetrie der drei Sorten durch Aufzählung, nicht nur durch Definition.

## NF-6 Nebenläufigkeit (Stand Phase 2)

- NF-6.1: Paralleles Buchen MUSS möglich sein, ohne die Journalnummern-Lückenlosigkeit zu verletzen. Die Nummernvergabe ist der Serialisierungspunkt — sie erfolgt atomar beim `post` (Persistenz-Adapter-Kontrakt; Implementierung adapterabhängig: Sequenz, Lock o. ä.).
- NF-6.2: `closePeriod` und Festschreibung MÜSSEN gegen laufende `post`-Operationen konsistent sein: Nach Abschluss einer Periode existiert keine Buchung mit späterer Journalnummer und früherem Buchungsdatum in dieser Periode.
- NF-6.3: Konflikte werden erkannt und abgewiesen (fehlschlagen ist erlaubt, stilles Überschreiben nie); Wiederholung ist Sache des Aufrufers. AfA-Lauf und andere generierende Läufe sind idempotent.
- NF-6.4: Das Datenformat selbst ist nebenläufigkeitsneutral (append-only); Sperrverhalten ist Adapter-, nie Formatsache.

## NF-7 Performance (erste Richtwerte, mit echten Projekten zu validieren)

- NF-7.1: Einzelbuchung inkl. Invariantenprüfung und Tax-Expansion: interaktiv nutzbar (Richtwert < 50 ms ohne I/O-Anteil des Adapters).
- NF-7.2: Projektionen (SuSa, Bilanz, EÜR) über ein Geschäftsjahr mit 100.000 Buchungen: Sekundenbereich, nicht Minuten. Inkrementelle/Snapshot-Strategien sind Adapter-Optimierung und DÜRFEN das deterministische Ergebnis nicht verändern (Projektion bleibt jederzeit voll neu berechenbar).
- NF-7.3: Konformitäts-Testsuite komplett: Minutenbereich (sie läuft in der CI je Implementierung — derzeit PHP und Node).

## Offen

- Konkrete Performance-Ziele verfeinern, sobald erste Konsumenten-Projekte (Frage 19) feststehen.
