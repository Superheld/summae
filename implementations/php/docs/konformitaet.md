# Konformität — der Kompatibilitätsvertrag

## Die Idee

Es soll mehrere Implementierungen geben (PHP, Node, Python) mit **identischer
API und identischem Datenformat**. „Identisch" ist keine Absichtserklärung,
sondern prüfbar: eine sprachneutrale **Fixture-Suite** (reine JSON-Daten). Eine
Implementierung gilt als konform, wenn **alle Fixtures grün** sind und ein
**kompletter Doppellauf byte-identische** Ergebnisse liefert.

Die Fixtures sind die normative Quelle. Sie leben in der Wissensbasis und werden
per `make sync` nach `testsuite/` gespiegelt (Einbahnstraße). **Hier nie
editieren** — siehe „SPEC-FINDINGS" unten.

## Wie der Runner arbeitet

`runner/` implementiert den Vertrag (`testsuite/README.md`):

1. Frischen In-Memory-Mandanten aus `setup` bauen.
2. `steps` in Reihenfolge ausführen; `expect.result` per **Teilmengen-Vergleich**
   (nur angegebene Felder), `expect.error` exakt gegen den Fehlercode.
3. `projections` ausführen und vergleichen (Beträge exakt, Reihenfolge normiert).
4. **Doppellauf**: die ganze Suite zweimal, Spuren müssen nach
   UUID-Normalisierung identisch sein.

Platzhalter (`$V1`, `$E1`, `$OP1`, …) referenzieren von der Implementierung
erzeugte IDs über Steps hinweg.

Zwei Subjects, derselbe Runner:

```bash
php runner/bin/run-fixtures.php --strict                    # In-Memory-Kern
php runner/bin/run-fixtures.php --strict --subject=database # Database-Adapter
```

Ein neues Subject (z. B. später ein HTTP-Client gegen Node) implementiert nur
`runner/src/Subject/Subject.php`.

## Determinismus-Regeln (die häufigsten Cross-Impl-Fallen)

Aus `determinismus.md` der Wissensbasis — jede hat eigene Fixtures:

- **Rundung kaufmännisch half-up** (von Null weg bei .5): `2.225 → 2.23`.
  *Nicht* banker's rounding (das ergäbe 2.22).
- **USt pro Beleg je Steuersatz** — Netto-Summe bilden, Steuer berechnen,
  **einmal** runden. Nicht positionsweise.
- **`allocate` Largest-Remainder**, Gleichstand → erster Teil in stabiler
  Reihenfolge: `100,00 / 3 = 33,34 / 33,33 / 33,33`. Σ Teile = Ausgangsbetrag,
  immer.
- **Sortierung nach Unicode-Codepoints** (keine Locale-Collation): führende
  Nullen sind signifikant, `"0420" < "1200" < "8400"`, `"10" < "9"`.
- **Zonenlose Kalenderdaten** fürs Belegdatum (kein UTC-Shift).
- **Kanonisches JSON (RFC 8785)** für Hashes/Vergleiche.

## Status

- **45 Fixtures, 34 Fehlercodes**, alle grün strict gegen In-Memory **und**
  Datenbank (SQLite + Postgres), Doppellauf deterministisch.
- Exporte validieren zusätzlich gegen `testsuite/schema/format.schema.json`
  (JSON Schema draft 2020-12).
- Spec-Stand: v0.5 (Datenformat). Die Schleife Implementierung → Findings →
  Spec → Retrofit wurde einmal vollständig durchlaufen (F-001…F-007).

## SPEC-FINDINGS — der Eskalationsweg

Wenn Spec, Fixture und Modell sich widersprechen oder etwas fehlt: **nicht
raten, nicht die Fixture ändern.** Stattdessen:

1. In [`SPEC-FINDINGS.md`](../SPEC-FINDINGS.md) dokumentieren (Was / Wo /
   gewähltes Verhalten / Vorschlag).
2. Mit dem nächstplausiblen Verhalten weiterbauen.
3. Das Finding fließt über die Wissensbasis zurück (Entscheidungslog) und kommt
   als präzisierte Spec + neue/geänderte Fixtures zurück.

Das ist kein Notnagel, sondern der vorgesehene Rückkanal — er hat F-001…F-007
sauber aufgelöst (Historie in [`SPEC-FINDINGS.md`](../SPEC-FINDINGS.md)).
