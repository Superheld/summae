# Konformität — der Kompatibilitätsvertrag (Node)

## Die Idee

Mehrere Implementierungen (PHP-Referenz, Node, später Python) mit **identischer API und
identischem Datenformat**. „Identisch" ist prüfbar: eine sprachneutrale **Fixture-Suite**
(reine JSON-Daten). Node gilt als konform, wenn **alle Fixtures grün** sind und ein
**kompletter Doppellauf byte-identische** Ergebnisse liefert — und wenn der Cross-Test
gegen PHP byte-gleich ausfällt.

Die Fixtures sind die normative Quelle. Sie leben in der Wissensbasis und werden per
`make sync` nach `testsuite/` gespiegelt (Einbahnstraße). **Hier nie editieren** — siehe
„SPEC-FINDINGS" unten.

## Wie der Runner arbeitet

`runner/` implementiert den Vertrag (`testsuite/README.md`):

1. Frischen In-Memory-Mandanten aus `setup` bauen.
2. `steps` in Reihenfolge ausführen; `expect.result` per **Teilmengen-Vergleich** (nur
   angegebene Felder), `expect.error` exakt gegen den Fehlercode.
3. `projections` ausführen und vergleichen (Beträge exakt, Reihenfolge normiert).
4. **Doppellauf**: die ganze Suite zweimal, Spuren müssen nach UUID-Normalisierung identisch sein.

Platzhalter (`$T1`, `$V1`, `$E1`, …) referenzieren von der Implementierung erzeugte IDs über
Steps hinweg. Ein neues Subject (z. B. ein HTTP-Client) implementiert nur `runner/src/subject.ts`.

Zwei Subjects, derselbe Runner:

```bash
pnpm fixtures --strict                     # In-Memory-Kern
pnpm fixtures --strict --subject=database  # Knex-Adapter (better-sqlite3)
```

**Cross-Test (SF-15)** — der Beweis der Format-Parität über die Sprachgrenze:

```bash
make cross   # PHP↔Node: derselbe Datenbestand, journalExport byte-gleich, beide Richtungen
```

## Determinismus-Regeln (die häufigsten Cross-Impl-Fallen)

Aus `determinismus.md` der Wissensbasis — jede hat eigene Fixtures:

- **Rundung kaufmännisch half-up** (von Null weg bei .5): `1.225 → 1.23`. *Nicht* banker's
  (das ergäbe 1.22). In Node: `big.js` mit `Big.roundHalfUp`.
- **USt je `taxRoundingGranularity`** — `perVoucher`: Netto-Summe je Satz bilden, Steuer berechnen,
  **einmal** runden; `perLine`: je Position. Kommt aus der `packPolicy`, nicht hartverdrahtet.
- **`allocate` Largest-Remainder**, Gleichstand → erster Teil in stabiler Reihenfolge:
  `100,00 / 3 = 33,34 / 33,33 / 33,33`. Σ Teile = Ausgangsbetrag, immer.
- **Sortierung nach Unicode-Codepoints** (keine Locale-Collation): führende Nullen signifikant,
  `"0420" < "1200" < "8400"`, `"10" < "9"`.
- **Zonenlose Kalenderdaten** fürs Belegdatum (kein UTC-Shift) — nie `new Date()` mit Zeitanteil.
- **Kanonisches JSON (RFC 8785)** für Hashes/Vergleiche; Float ist verboten (Beträge als String-Dezimal).

## Status

Kern-Fixtures grün strict gegen In-Memory **und** Datenbank (better-sqlite3), Doppellauf
deterministisch. Die Pack-Fixtures (`testsuite/fixtures/pack/`) prüfen die v0.6-Pack-Komposition
(Resolver + Loader). Cross-Test `make cross` bestätigt `journalExport` byte-gleich PHP↔Node in
beide Richtungen. (Konkrete Stände driften — sie kommen aus `pnpm fixtures` / `make cross`, nicht
aus diesem Dokument.)

## SPEC-FINDINGS — der Eskalationsweg

Wenn Spec, Fixture und Modell sich widersprechen oder etwas fehlt: **nicht raten, nicht die
Fixture ändern.** Stattdessen:

1. In [`../SPEC-FINDINGS.md`](../SPEC-FINDINGS.md) dokumentieren (Was / Wo / gewähltes Verhalten /
   Vorschlag), als `NF-…`.
2. Mit dem nächstplausiblen Verhalten weiterbauen.
3. Das Finding fließt über die Wissensbasis zurück (Entscheidungslog) und kommt als präzisierte
   Spec + neue/geänderte Fixtures zurück.

Das ist kein Notnagel, sondern der vorgesehene Rückkanal.
