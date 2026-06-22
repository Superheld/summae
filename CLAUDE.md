# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

> **Aufbau dieser Doku.** Der Root hält, was für *alle* Implementierungen gilt. Je
> tiefer man geht, desto sprachspezifischer: Befehle und Konventionen je Sprache in
> `implementations/<sprache>/CLAUDE.md`, Detail-Doku in deren `docs/`. Verweise auf
> tiefere Doku immer **annotiert** — kurz dazuschreiben, was dort steht.

## Was das ist

**summae** ist eine einbettbare Rechnungswesen-Bibliothek (GoBD-Doppik, EÜR,
Umsatzsteuer, Anlagen, KLR) — **keine Anwendung**. Mehrere Sprach-Implementierungen
sollen *identische API und identisches Datenformat* haben; geprüft wird das über
eine sprachneutrale Konformitäts-Suite (`testsuite/`).

Repo-Layout:
- `testsuite/` — der Kompatibilitätsvertrag: `fixtures/**.json` + `schema/`. Geteilt von allen Implementierungen.
- `implementations/php/` — PHP-Referenz (Packages `core`, `laravel`, `cli` + `runner/`). Befehle/Konventionen: `implementations/php/CLAUDE.md`, Tiefe in `docs/`.
- `implementations/node/` — Node/TypeScript (Packages `core`, `knex`, `cli` + `runner/`). Befehle/Konventionen: `implementations/node/CLAUDE.md`.
- `pack-library/` — ausgelieferte **Pack-Bibliothek** (Produkt-Daten, *keine* Tests): **self-contained** Packs (`pack-library/<pack>/` mit Manifest + eigenen Modulen). Quelle Wissensbasis, via `make sync` gespiegelt (`rsync --delete`); **getrennt von `testsuite/`**. Pack bauen: `pack-library/CLAUDE.md`.
- `Makefile`, `compose.yaml`, `docker/` — Docker-Toolchain (treibt aktuell die PHP-Seite).

## Scope: Fähigkeiten, nicht Workflows

summae liefert **Fähigkeiten** (GoBD-konformes Buchen, Auswertungen, Exporte); rechtliche
**Workflows** baut die einbettende App. Faustregel: „*die Daten müssen…*" = Package · „*der
Anwender muss bis X…*" = App. Bibliothek, kein App: **kein UI, kein Server, keine erzwungene DB**
(Persistenz hinter einer Schnittstelle), mandantenfähig auf Datenebene. Bewusst **außerhalb**
(nicht „noch nicht gebaut" — nicht versehentlich anfangen): UI/Frontend · ELSTER-/Behörden-Übermittlung ·
E-Rechnung erzeugen/parsen (XRechnung/ZUGFeRD) · Banking (FinTS/PSD2/CAMT — `postVoucher`/`settle`
sind die Andockpunkte für *geparste* Umsätze) · Kassensysteme/TSE · Lohn*abrechnung* (nur die
*Verbuchung* des Lohnbelegs ist drin) · Steuerermittlung über USt hinaus (ESt/KSt/GewSt).
Master der Abgrenzung: `30-anforderungen/out-of-scope.md`.

## Architektur (das große Bild)

Sprachneutral — die Begriffe gelten für jede Implementierung. Pfade und Details je
Sprache in deren `docs/` (PHP: `implementations/php/docs/architektur.md` — Packages,
Hexagonal, Schichten, Datenfluss einer Buchung).

**Hexagonal.** Ein framework-freier Fachkern (`core`) trägt die gesamte
Buchführungslogik. Persistenz und Terminal-Werkzeug sind dünne Adapter *außen* —
**keine Fachlogik in Adaptern, kein Framework-Import im Kern.**

**Ports & Adapter.** Der Core definiert Interfaces (`AccountRepository`,
`JournalRepository`, …). Adapter-Sätze: In-Memory (Tests/Konformität) und echte
Persistenz (z. B. der PHP-`laravel`-Adapter via `illuminate/database`, persistiert Aggregate als JSON in `summae_*`-Tabellen —
das geteilte Datenformat, siehe Qualitätsrichtlinie). Ein Mandant (`Tenant`) wird
mit dem einen oder anderen Port-Satz gebaut.

**Ein Einstiegspunkt für alle Operationen.** Ein Dispatcher (`TenantOperations`)
führt *alle* Ops (`post`, `postVoucher`, `settle`, …) und Projektionen
(`trialBalance`, `vatReturn`, `journalExport`, …) aus — Namen exakt nach API-Spec.
CLI und Konformitäts-Runner nutzen denselben Dispatcher. Neue Operation → dort
verdrahten.

**Lesen läuft nie über gespeicherte Salden.** Jede SuSa/Bilanz/EÜR/USt-Voranmeldung
wird aus dem Journal neu berechnet.

**Jurisdiktionsfrei: Substrat → Politiksorten → Pack.** Das ist *wie summae gedacht
ist*, sprachübergreifend — jeder Agent, der etwas baut, muss es kennen, nicht nur
PHP. Der Kern ist ein **jurisdiktionsfreies Substrat** (Buchung, Konto, Journal,
Saldo, Periode) — er kennt kein Gesetz und **wächst nicht pro Jurisdiktion**
(abgeschlossen unter Komposition, abelsche Gruppe der Doppik). Alles darüber ist *genau eine* von drei **Politiksorten**: **Constraint**
(muss gelten), **Projektion** (Journal → Sicht), **Expansion** (Absicht → ausbalancierte
Buchungen). Jede Sorte ist **Sockel** (gesetzesfreier Mechanismus = ein Port *im* Kern)
+ **Stecker** (Daten/Regeln aus dem **Pack**). Kern definiert den Sockel, Pack liefert den
Stecker, die Komposition injiziert ihn (Dependency Inversion) — **der Kern importiert nie ein
Pack** (Abhängigkeit nur Pack→Kern, mechanisch erzwungen, nicht per Review). Das Pack ist das
versionierte Bündel einer Jurisdiktion
(„tzdata fürs Rechnungswesen"; „Deutschland" ist das *erste* Pack, nicht die eingebaute
Annahme). Ein Pack ist komponierbar (kuratiert nehmen / anpassen / selbst à la carte).
**Lackmustest beim Bauen:** zitiert dein Code einen Paragraphen → falsche Schicht, das
gehört als Daten ins Pack. Vollständiges Bild + ehrlicher Baustatus: `docs/architektur.md`.

**Pack & Module (kurz).** Drei Schichten: **Substrat** → **Politiksorten** (Sockel im Kern) → **Pack** (oben).
Ein **Modul** = ein Stecker für *genau eine* Politiksorte (meist eine Daten-Datei `kind`+`data`); ein **Pack**
= self-contained Manifest, das Module bündelt (`pack-library/<pack>/`, bauen nicht aufeinander auf). Pack-Wahl
einmalig beim Anlegen, gepinnt. Altwort „Regelmodul" = Pack (vermeiden); **base** = der Kern, kontenlos.

*Gebaut:* `PackResolver` (byte-gleich PHP↔Node), Loader, `createTenant(pack:"…")`, CLI `summae init --pack …`,
Packs `default` + `de`.

> **Tiefer (annotiert):** `kind`→Politiksorte + Modul-Regeln → `pack-library/CLAUDE.md` · Engine-Bündel
> (`ruleModules`/`packPolicy`), Ziel-vs-Ist + offene *closed/open*-Frage → `core/src/CLAUDE.md` · volles Modell
> → `docs/architektur.md`.

## Bau-Konventionen (Prinzipien — Patterns & Rezepte in den `docs/`)

Bewährte Patterns verwenden, **keine neuen Strukturen erfinden**:

- **Test-driven & Walking Skeleton (inside-out):** erst der Test, dann der Code; im **Kern** mit **Fakes**
  (In-Memory-Ports) beginnen, dann nach außen. Roter Test gegen den In-Memory-Kern = Fachfehler, kein Persistenzfehler.
- **Neue Pack-Fähigkeit = primär Daten (Stecker), nie Substrat-Code:** ein Modul/Manifest; ein neues *Paradigma*
  (anderer Algorithmus) = komponierbares Modul **hinter dem Sockel**, nie ins Substrat. Per Name **referenzieren** statt inline kopieren.
- **PHP und Node spiegeln sich 1:1.** Jede Kern-Änderung in *beiden* identisch — Byte-Parität (SF-15) ist Vertrag.
- **Framework-frei im Kern** (Node: eslint `no-restricted-imports`; PHP: nur `brick/math`). Persistenz/CLI sind Adapter außen.

Patterns-Liste (Factory/Registry/Strategy/Dispatcher) → `docs/architektur.md`; „neue Operation = Service + `case` +
Fixture in beiden Sprachen" + Spec-Retrofit → `implementations/<sprache>/docs/entwicklung.md`.

## Eiserne Invarianten (nicht verletzen)

- **Journal append-only; Salden sind Projektionen.** Nie einen Saldo speichern.
- **Geld nie als Float.** `Money` auf einer Dezimal-/BigDecimal-Bibliothek (PHP
  `brick/math`, Node `big.js`), kaufmännisch half-up (von Null weg, *kein* banker's
  rounding), `allocate` mit Largest-Remainder.
- **Determinismus.** Gleiche Eingabe → byte-identisches Ergebnis (Rundung, Sortierung
  nach Unicode-Codepoints, kanonisches JSON RFC 8785). `Clock`/`IdGenerator` sind
  injizierbar — Tests **nie** gegen `now()`/Zufall; der Runner nutzt `FixedClock` +
  `DeterministicIdGenerator`.
- **Buchungsdatum zonenlos** (`CalendarDate`, keine Zeit/UTC-Shift).

## testsuite/ ist read-only

Fixtures sind die normative Quelle und leben in der **Wissensbasis** (Schwester-Repo
„Rechnungswesen"). Sie werden per `make sync` hierher gespiegelt (`rsync --delete` —
was hier liegt und nicht in der Quelle ist, wird gelöscht; **keine eigenen Dateien
in `testsuite/` ablegen**) und **hier nie editiert**. Fixtures sind append-only:
Verhaltensänderung = neue Fixture, nie stilles Editieren. Widerspruch zwischen
Spec/Fixture/Modell → **nicht raten, nicht die Fixture biegen**, sondern im
`SPEC-FINDINGS.md` der jeweiligen Implementierung dokumentieren und mit dem
nächstplausiblen Verhalten weiterbauen.

## Konventionen (sprachneutral)

- **Alles auf Englisch** — das Projekt wird mit dem us-pack international (OSS): Code-Kommentare,
  Doku, CHANGELOG/Release-Notes, Paket-Beschreibungen (`package.json`/`composer.json`),
  CLAUDE-Dateien. Nur die **Arbeitssprache im Chat** (Mensch↔KI) bleibt Deutsch. *Bestehendes
  Deutsch (viele Code-Kommentare, diese CLAUDE-Prosa) wird beim Anfassen / nachfragegetrieben
  übersetzt — nicht in einem Rutsch; spätestens mit/vor dem us-pack.*
- Doku-Verweise immer **annotiert**: kurz dazuschreiben, was dort zu finden ist.
- Git: **nie direkt auf geteilte Branches** (`main`, `develop`) — pro Aufgabe ein
  Branch (`job/…`, `chore/…`, `fix/…`); Merge per `--no-ff`, wenn grün.

Sprachspezifische Konventionen, Build- und Testbefehle: in
`implementations/<sprache>/CLAUDE.md`.

## Oberste Qualitätsrichtlinie: sprachübergreifende Äquivalenz

**Gleiche Eingabe → gleiches Ergebnis, egal mit welchem Package oder welcher
Sprache.** Das ist die oberste Regel — über Fachkern, Persistenz, Export und
jede künftige Jurisdiktion hinweg. Ein Test, der nur eine Implementierung prüft,
verfehlt den Zweck von summae.

Zwei Mechanismen, **ein** Prinzip:

- Tests sind sprachneutral und laufen gegen **alle Implementierungen, die die
  getestete Fähigkeit besitzen** (ein Persistenz-Cross-Test kann nicht gegen eine
  Runtime ohne Persistenz laufen — „alle *anwendbaren* Packages").
- **(a) Geteiltes Orakel** — die Fixtures pinnen *eine* kanonische Erwartung;
  jede Implementierung wird dagegen geprüft. A == Erwartung und B == Erwartung ⇒
  A == B: N-Sprachen-Äquivalenz ohne N²-Vergleiche. (Deckt die Rechen-Achse ab.)
- **(b) Geteilte Daten** — wo eine Fähigkeit in ≥ 2 Implementierungen existiert,
  wird derselbe Datenbestand von mehreren Packages getrieben und muss identisch
  rauskommen (Cross-Test, SF-15). Beweist Format-Parität, die (a) allein nicht
  zeigt. Ziel: *eine DB, mehrere Engines, eine Wahrheit.*

## Definition of Green

Jede Implementierung ist grün nach **ihren** Regeln (Linter/Typecheck/Tests inkl.
**Coverage-Floor** (Kern-Zeilen ≥ 88 %, fest im Testlauf — darf nur steigen) +
Konformitätssuite `--strict` inkl. byte-identischem Doppellauf — Details in der
jeweiligen `implementations/<sprache>/CLAUDE.md`). Sprachübergreifend zusätzlich:
jede Fähigkeit, die in ≥ 2 Implementierungen existiert, besteht den Cross-Test —
gleiches Ergebnis über alle anwendbaren Packages (siehe Qualitätsrichtlinie).

**Quality-Gate: jede Anforderung ist getestet.** `30-anforderungen/` (funktionale **F-…** und
nicht-funktionale **NF-…**) ist die Soll-Liste. Jede Anforderung wird durch einen Test *bewiesen* —
funktional über eine Fixture (verlinkt im `covers`-Feld), und wo Fixtures nicht reichen
(Nebenläufigkeit NF-6, Performance NF-7) über einen **dedizierten** Test je Implementierung. Eine
Anforderung **ohne** Test ist selbst ein Befund (gehört auf die Gate-Lücken-Liste), kein „erledigt".
