# Konformitäts-Testsuite

**Der Kompatibilitätsvertrag.** Eine Implementierung (laufzeitübergreifend; PHP/Laravel ✅, Node/TypeScript ✅, weitere Sprachen folgen) gilt als konform, wenn alle Fixtures grün sind. Sprachneutral: reine JSON-Daten, kein Code.

## Fixture-Format

Eine Fixture = eine JSON-Datei:

```json
{
  "fixture": "eindeutiger-name",
  "description": "Was wird bewiesen",
  "covers": ["F-CORE-001", "SF-02"],
  "setup": { "tenant": {…}, "accounts": […], "fiscalYears": […], "vouchers": […] },
  "steps": [
    { "op": "post", "input": {…}, "expect": { "result": {…} } },
    { "op": "post", "input": {…}, "expect": { "error": "E_ENTRY_UNBALANCED" } }
  ],
  "projections": [
    { "name": "cashBasisReport", "params": { "year": 2025, "asOf": "2026-06-07" },
      "expect": {…} }
  ]
}
```

## Runner-Kontrakt (implementiert jede Sprache einmal)

1. Frischen In-Memory-Mandanten aus `setup` aufbauen.
2. `steps` in Reihenfolge ausführen; `expect.result` per Teilmengen-Vergleich prüfen (nur angegebene Felder; IDs via Platzhalter `"$1"`, `"$2"` … referenzierbar), `expect.error` exakt.
3. `projections` ausführen und gegen `expect` vergleichen — Beträge exakt, Reihenfolge normiert.
4. Determinismus: kompletter Suite-Doppellauf muss identisch sein.

## Konventionen

- Jeder Fehlercode aus `50-spezifikation/api.md` bekommt ≥ 1 Fixture.
- Jeder Standardfall SF-01–26 bekommt ≥ 1 Fixture (SF-15 = Cross-Kompatibilität, erfüllt seit der Node-Runtime + bidirektionalem Cross-Test).
- Rundungs- und Sortierfälle sind eigene Fixtures (häufigste Cross-Impl-Abweichung).
- Fixtures sind append-only: Verhaltensänderung = neue Fixture + Entscheidungslog, nie stilles Editieren.
- **Die Pack-`version` wird nur dort erwartet, wo `resolvePack` der Gegenstand ist** (die
  `*-pack-resolves`-Fixtures + `xx-1`). Überall sonst steht im `createTenant`-`expect` nur
  `"pack": { "id": "de" }`. Grund: die Version lief vorher in 32 Fixtures mit, ohne dort geprüft
  zu werden — eine Pack-Version zu erhöhen färbte 17 Fixtures rot, die von Bilanz, Skonto oder
  EÜR handeln. Seit 2026-08-16 ist es genau eine pro Pack, und die prüft die Version wirklich.

## Stand

**Keine eingefrorenen Zahlen hier** — sie driften schneller, als sie jemand nachzieht (diese Zeile
stand bis 2026-08-16 auf „58 Fixtures, 38 Fehlercodes", während es längst dreistellig war). Die
aktuellen Werte liefern die Werkzeuge: Fixture-Zahl + grün/rot aus dem Runner (`make fixtures`
bzw. `pnpm fixtures --strict`), Fehlercode- und Standardfall-Abdeckung aus `validate.py`,
Abdeckungsmatrix in `abdeckung.md`. SF-15 (Cross-Kompatibilität) ist nicht per Fixture belegt,
sondern durch den bidirektionalen Cross-Test PHP ↔ Node.
