# Spec-Update v0.4 (2026-06-08) — Pflichtlektüre, Testsuite neu syncen (jetzt 40 Fixtures)

Nach Buchhalter- und Steuerberater-Review (`00-projekt/review-buchhalter-2026-06-07.md`, `review-steuerberater-2026-06-07.md`). Nach Jobs sortiert:

## JOB-003 (Ledger-Kern)

- **Kanonische subtype-Liste** (`datenformat.md`): u. a. neu `cash`, `transit`, `opening_balance`, `private`, `result_allocation`. Geldkonto := {bank, cash}; transit ist EÜR-neutral.
- **`voucher`**: neue Felder `serviceDate`/`servicePeriod`, `partnerId`, `kind` (Belegart).
- **`createFiscalYear`** neu (+ `E_FISCALYEAR_OVERLAP`). Fixture: fiscal-year-management.
- **`trialBalance`-Zeilen**: verbindlich `openingBalance`, `debitTotal`, `creditTotal`, `balance`.
- **OP-Entstehungsregel** verbindlich: ar+Soll → receivable, ap+Haben → payable; Gegenseite (Gutschrift) erzeugt KEINEN OP, gleicht per settle aus. Fixture: customer-credit-note.
- **`unfinalizedEntries {olderThanDays}`** als Projektion (Festschreibe-Frist).

## JOB-003/004 (Partner — neues Stammdatenobjekt)

- `partner` (id, name, kind, vatId, paymentTermsDays, accountIds) + `partners.jsonl` + Schema-`$def`. `createPartner`/`updatePartner` (Audit!), `E_PARTNER_UNKNOWN`. `openItems` filterbar nach partnerId. Fixture: partner-and-ec-sales.

## JOB-006/007 (Tax)

- **Regelversionswahl nach Leistungsdatum** (Fallback voucherDate) — § 27 UStG; VA-Zuordnung Soll-Versteuerung ebenso. Fixture: service-date-rate-change.
- **`ecSalesList`** (ZM-Grundlage je USt-IdNr.); igL-Mechanismus (rate 0, Kz 41, kein Steuerbetrag).
- **Ergebnisverwendung:** includesNetIncome = kumulierte Ergebnisse + Saldo result_allocation-Konten. Fixture: profit-appropriation.
- **`incomeStatement {fromPeriod?, throughPeriod?}`** (Monats-GuV/BWA). Fixture: monthly-income-statement.

## JOB-009 (Assets)

- **AfA-Methoden `linear` + `declining` inkl. automatischem Methodenwechsel** (degressive AfA ist aktiv: Anschaffung 01.07.2025–31.12.2027, 2,5× linear, max. 30 %); Sonder-AfA/AK-Minderung (§ 7g) als Plan-Mechanik. Regelmodul-Daten mit Anschaffungszeitraum-Gültigkeit.

## JOB-011 (Export) + neu

- `datevExport kind: entries|accounts|partners` (Stammdaten!), `importDatevBatch` (Rückweg, Formatdetail dort verifizieren), `systemDocumentation` (Verfahrensdoku-Baustein, GoBD Rz. 151 ff.).

## Buchungsmuster-Fixtures (keine API-Änderung, aber Suite-Pflicht)

payroll-entry (Lohnbuchungsbeleg), entertainment-split (Bewirtung 70/30, VSt 100 %), money-transit (Geldtransit neutral).

## Stand

Datenformat v0.4, Schema-$id 0.4 (+ partner), 31 Fehlercodes, SF-21–26, F-CORE-022–027, F-TAX-011/012, F-AST-007, F-IO-006–008. Lieferumfang hat neuen Abschnitt „Wofür das Package NICHT reicht" — bitte in Package-README übernehmen.
