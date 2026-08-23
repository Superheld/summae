# Spec-Update v0.3 (2026-06-07) — Pflichtlektüre vor Weiterarbeit

Nach einem Quer-Review (`00-projekt/review-2026-06-07.md`) wurden Verträge geändert. Testsuite neu synchronisieren (jetzt **31 Fixtures**). Was sich für die Implementierung ändert, nach Jobs sortiert:

## Betrifft JOB-003 (Ledger-Kern)

- **Zeitraum-Semantik verbindlich** (`api.md`, neuer Abschnitt): Bestandskonten kumulativ (impliziter Saldovortrag, KEINE SBK/EBK-Buchungen), Erfolgskonten je Geschäftsjahr. `closeFiscalYear` = reiner Statuswechsel mit Voraussetzungen.
- **Storno-Zeilen** (`datenformat.md`): Generalumkehr = gleiche Konten/Seiten, **negative Beträge — nur zulässig bei `reverses ≠ null`**. Storno eines Stornos ist erlaubt. Fixture `finalize-reverse-period` prüft jetzt die Zeileninhalte.
- **`fiscalYear.year` = Kalenderjahr des Endes** (abweichende GJ). Neue Fixtures: `two-year-carryover`, `deviating-fiscal-year`.
- **Audit-Trail ist Formatbestandteil**: Strom `auditLog.jsonl` (Schema `$defs/auditRecord`); `post`/`correct`/`lockAccount` etc. erzeugen Einträge; Schreiboperationen nehmen `actor`. Fixture `audit-trail`.
- **Saldenübernahme** (SF-17): Eröffnungsbuchung gegen Saldovortragskonto, Beleg Pflicht, AR/AP-Zeilen erzeugen OPs. Fixture `opening-balance-takeover`.

## Betrifft JOB-004 (Offene Posten)

- `settle`-Zuordnungen: optional `difference {money, kind: discount|bad_debt|minor}` — OP gilt als ausgeglichen; Differenz MUSS als Buchungszeile in der ausgleichenden Buchung stehen. Neuer Fehlercode `E_SETTLEMENT_DIFFERENCE_INVALID`. Neue Komposition **`settleVoucher`** (Pendant zu `postVoucher`). Fixtures: `settlement-discount`, `settlement-bad-debt`.

## Betrifft JOB-006 (Tax)

- `expandTax`: Steuercode **je Position** (`netLines[].taxCode`); Rundung pro Beleg *je Steuersatz*. Fixture `mixed-tax-rates`.
- Reverse Charge: eine Regelversion erzeugt zwei Steuerpositionen mit je eigener Kennzahl. Fixture `reverse-charge`.
- Anzahlungs-Muster (Mindest-Ist) und unentgeltliche Wertabgaben: Fixtures `advance-payment`, `non-cash-benefit`.

## Betrifft JOB-007 (EÜR + USt-VA)

- **Neue EÜR-Regel R7**: cash-basis-Mapping-Positionen mit `includeNonCash: true` zählen ohne Zahlungsfluss (AfA, Wertabgaben). Abweichendes GJ → `E_CASHBASIS_DEVIATING_FISCAL_YEAR`.
- **`vatReturn`**: Bemessungsgrundlagen auf volle Euro abgerundet (Kennzahlen-Summe), Steuer centgenau. Ist-Versteuerung folgt OP-Ausgleichen, Teilzahlung anteilig, Schlusszahlung erhält Rundungsrest. Fixture `vat-return-cash-basis` (ersetzt das falsche Versprechen in der alten `vat-return`-Beschreibung).

## Betrifft JOB-009 (Assets)

- Neue Operation `writeUp` (Zuschreibung, max. fortgeführte AHK).

## Format-/Katalogstand

`datenformat.md` v0.3, Schema-`$id` 0.3 (+ `auditRecord`), Fehlerkatalog 29 Codes (alle mit Fixture), neue Anforderungen F-CORE-018–021, F-TAX-008–010, Standardfälle SF-17–20. Drei neue Leitsätze in `00-projekt/vision-und-ziele.md` — der wichtigste: **GoBD hat Vorrang.**
