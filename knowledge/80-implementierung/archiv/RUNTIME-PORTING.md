# Runtime-Porting — Briefing für die nächste Implementierung (Node, später Python)

Für einen Coding-Agenten mit frischem Kontext, der eine **weitere Runtime** von Summae baut. Die PHP/Laravel-Referenz ist fertig und konform (`ABSCHLUSSBERICHT.md`) — sie ist der Goldstandard. Deine Implementierung ist fertig, wenn sie denselben Vertrag erfüllt.

## Auftrag in einem Satz

Baue eine Runtime-Implementierung (zuerst **Node/TypeScript**), die **dieselbe Konformitäts-Testsuite** (`testing/testsuite/`, 43 Fixtures) besteht — gegen einen In-Memory-Port und gegen mindestens einen Persistenz-Adapter — und denselben Datenbestand lesen/fortschreiben kann wie PHP (Cross-Kompatibilität).

## Der Vertrag (identisch für jede Runtime)

1. **`testing/testsuite/`** ist der Kompatibilitätsvertrag (Runner-Kontrakt in `README.md`, Format-Demos in den Fixtures). Fixtures werden **nie** editiert, um sie passend zu machen — Abweichung = Finding (siehe unten).
2. **`50-spezifikation/`** ist normativ: `datenformat.md` (v0.5), `api.md`, `determinismus.md`, `fehlerkatalog.md`, `schema/format.schema.json`.
3. **`20-glossar/`** liefert alle Namen.

## Was diese Portierung leichter macht als die erste

- **Der „Bauplan" aus PHP liegt vor:** `ABSCHLUSSBERICHT.md`, Abschnitt **„Adapter-Annahmen (für die Node-Portierung)"** — 7 Punkte, die genau die Stellen benennen, an denen die Referenz konkrete Entscheidungen getroffen hat (Determinismus-Hooks: injizierbare Clock + IdGenerator; Geld brick/math HALF_UP away-from-zero → Node-Pendant muss exakt spiegeln; Persistenz = Published Language als JSON-Spalten; Unique-Constraints Kontonummer/sequenceNumber; Regelmodul-Daten als App-Schicht-Daten; released Costing-Läufe in eigenen Strom; etc.). **Diese 7 Punkte zuerst lesen.**
- **Die Schicht-Trennung ist jetzt explizit** (`40-domaenenmodell/jurisdiction-profil.md`, Schichtenzuordnung): Bau von Anfang an **Kern (Substrat + Mechanik, gesetzesfrei)** getrennt von **`pack-de` (Daten/Regeln: Steuerschlüssel, SKR, Mappings, AfA-Tabellen, Rundungs-Policy-Werte)**. PHP hat DE-first gebaut und die Naht nachträglich benannt — du kannst sie von Tag 1 sauber ziehen.

## Architektur-Vorgaben (wie PHP)

- **Kern framework-frei** (`@summae/core` o. ä.), Persistenz hinter einem Port; gegen In-Memory-Port entwickeln und testen, Framework-Adapter (z. B. NestJS/Express, Prisma/Knex) separat.
- **Geld dezimal-exakt** (z. B. big.js/decimal.js), **half-up away-from-zero**, nie JS-`number`. Determinismus-Anhang ist Pflichtlektüre — die Rundungs-/Sortier-Fallen sind die häufigste Cross-Runtime-Abweichung.
- **Determinismus-Hooks injizierbar** (Clock, IdGenerator), damit der Doppellauf inkl. SHA-256-Strom-Hashes byte-identisch ist.

## Cross-Kompatibilität (SF-15 — der eigentliche Mehrwert der zweiten Runtime)

SF-15 ist bisher der einzige offene Standardfall, weil er zwei Runtimes braucht. Akzeptanz: **Ein mit PHP erzeugter Datenbestand (Published-Language-Form, `datenformat.md`) wird von Node gelesen, fortgeschrieben und ausgewertet — identische Projektionsergebnisse.** Umgekehrt ebenso. Das ist der Beweis, dass „ein Datenformat für alle Runtimes" hält.

## Eskalation (Findings)

Spec-Lücke/Widerspruch beim Bauen → nicht raten, nicht Fixture biegen. In `SPEC-FINDINGS.md` im Format F-0xx anhängen (Was/Wo/Gewähltes Verhalten/Vorschlag), mit nächstplausiblem Verhalten weiterbauen. Die Wissensbasis arbeitet sie als nächste Spec-Version ein (so geschehen mit F-001…009 aus PHP). Node-spezifische Findings sind erwartbar v. a. bei Rundung/Zahlentypen.

## Reihenfolge (analog `JOBS.md`, runtime-agnostisch)

Shared Kernel (Money/IDs/Kanonisierung) → Fixture-Runner → Ledger-Kern → OP → Projektionen → Tax → EÜR/VA → Mappings → Assets → Costing → Export → Framework-Adapter → CLI → Cross-Test gegen PHP. `JOBS.md` gilt sinngemäß; M3 = alle Fixtures grün, M-Cross = SF-15 grün gegen PHP-Daten.

## Python (später)

Gleiches Briefing, gleiche Suite, gleicher Vertrag. `decimal.Decimal` + ROUND_HALF_UP. Erst nach Node, damit SF-15 schon etabliert ist.
