# Spec-Update v0.5 (2026-06-08) — Auflösung der SPEC-FINDINGS F-001…007

Reaktion auf deine eigenen Findings (`SPEC-FINDINGS.md`). Testsuite neu syncen (jetzt **43 Fixtures, 34 Fehlercodes**). Alle Punkte sind Vertragspräzisierungen — wo dein Workaround abwich, gilt ab jetzt das Folgende:

## Fehlercodes (JOB-003/009/010)

- **F-001 → `E_VOUCHER_UNKNOWN`**: gesetzte, aber unbekannte voucherId bekommt einen eigenen Code (nicht mehr `E_ENTRY_NO_VOUCHER`). Prüfreihenfolge: Referenzschritt, nach „voucherId fehlt". Fixture: voucher-unknown.
- **F-003 → `E_FISCALYEAR_UNFINALIZED_ENTRIES`**: `closeFiscalYear` mit nicht festgeschriebenen Buchungen wirft diesen Code (nicht `E_PERIOD_OUT_OF_ORDER`). Fixture: fiscalyear-close-guard.
- **F-006 → `E_COSTING_RUN_UNKNOWN`**: unbekannte runId bei release/Projektion. Fixture: costing-run-unknown.

## F-002 — reverse statusunabhängig (JOB-003)

`E_ENTRY_NOT_FINALIZED` ist **gestrichen** — es gibt ihn nicht. `reverse` funktioniert für `entered` und `finalized`. Zusatzregel: **`closePeriod` verlangt keine Festschreibung**; die Festschreibungspflicht greift erst bei `closeFiscalYear` (und GoBD-seitig über `unfinalizedEntries`). Dein gewähltes Verhalten war korrekt — jetzt offiziell.

## F-004 — Asset-Kontenzuordnung (JOB-009)

Regelmodul-Block `assetAccounts` mit Schlüsseln `acquisitionCounterAccount`, `depreciationExpenseAccount`, `gwgExpenseAccount`, `disposalProceedsAccount`, `disposalLossAccount` (pro Anlageklasse überschreibbar; `acquireAsset` darf das Gegenkonto explizit übergeben). **Keine Namens-Heuristik mehr** — die Fallback-Konvention aus deinem Workaround entfällt. Fixture-Setups (gwg-and-depreciation, depreciation-monthly-allocation, edge-errors) tragen den Block jetzt.

## F-005 — Export-Manifest (JOB-011)

Manifest-Pflichtfelder `streams` (Liste) und `hashAlgorithm` (`sha256`) sind jetzt im Schema. **`auditLog` ist immer im Export** (auch ohne Korrekturen). `formatVersion` = aktuelle Version (0.4/0.5), nicht hartkodiert „0.2". journal-export-z3 ist neu geschnitten.

## F-007 — Bilanz-Seitenzuordnung (JOB-008)

Balance-sheet-Mappings tragen am **Wurzelknoten** `side: assets | liabilitiesAndEquity` — die Seite kommt nicht mehr aus der Reihenfolge. `assets` = Soll−Haben, `liabilitiesAndEquity` = Haben−Soll. `includesNetIncome` ist jetzt auch im Schema (war implizit). Drei Bilanz-Fixtures aktualisiert.

## Hinweis

Schema-`$id` bleibt 0.4 (additive Felder, kein Bruch); inhaltlich ist der Stand v0.5. Weitere Findings wie gehabt in SPEC-FINDINGS.md anhängen — danke fürs saubere Format, das hat die Einarbeitung trivial gemacht.
