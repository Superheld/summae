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

## Stand (2026-06-20)

**45 Fixtures, 34/34 Fehlercodes, 26/26 Standardfälle** (SF-15 erfüllt: PHP ↔ Node bidirektional grün). Aktueller Stand und Abdeckungsmatrix: `abdeckung.md`, Validierung: `validate.py`. PHP- und Node-Referenz bestehen die Suite vollständig (`../80-implementierung/ABSCHLUSSBERICHT.md`, `RUNTIME-LEITFADEN.md`).
