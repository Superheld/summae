# Konformitäts-Testsuite

**Der Kompatibilitätsvertrag.** Eine Implementierung (laufzeitübergreifend; erste: PHP/Laravel ✅, dann Node, Python) gilt als konform, wenn alle Fixtures grün sind. Sprachneutral: reine JSON-Daten, kein Code.

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
- Jeder Standardfall SF-01–26 bekommt ≥ 1 Fixture (SF-15 erst mit zweiter Runtime).
- Rundungs- und Sortierfälle sind eigene Fixtures (häufigste Cross-Impl-Abweichung).
- Fixtures sind append-only: Verhaltensänderung = neue Fixture + Entscheidungslog, nie stilles Editieren.

## Stand (2026-06-08)

**43 Fixtures, 34/34 Fehlercodes, 25/26 Standardfälle** (SF-15 wartet auf die zweite Runtime). Aktueller Stand und Abdeckungsmatrix: `abdeckung.md`, Validierung: `validate.py`. Die PHP-Referenz besteht die Suite vollständig (`../80-implementierung/ABSCHLUSSBERICHT.md`).
