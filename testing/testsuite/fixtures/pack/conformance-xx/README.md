# conformance-xx — Test-Pack „XX" als komponiertes Pack (Gate-1-Entwurf)

> **ENTWURF, nicht normativ.** Bau-Flow-Lauf #1, Gate 1 (Orakel/Fixtures), erstellt
> 2026-06-20. Wird erst mit menschlicher Freigabe nach `70-testsuite/fixtures/conformance-xx/`
> überführt. `70-testsuite/` bleibt unberührt. Quellen: `40-domaenenmodell/jurisdiction-profil.md`
> (Modul/Manifest/Resolver, Konformitätsanspruch Punkt 2), `00-projekt/entwurf-pack-policy-testpack-2026-06-09.md`
> (packPolicy-Felder, Test-Pack XX, durchgerechnete Fixtures § 4), `80-implementierung/PACK-KOMPOSITION.md`
> (Gate-Inhalte), `_bauflow-pack-gate01/design/module-manifest-resolver.md` + `_bauflow-pack-gate01/spec/*.additions.md`
> (komponiertes Format).

## Was das ist

Das in `jurisdiction-profil.md` geforderte **bewusst schräge fiktive Pack** — der
Lackmustest, der Jurisdiktionsfreiheit von *behauptet* zu *erzwungen* hebt: Skala 3,
Rundung je Position (`perLine`), kein Vorsteuerabzug, fiktive Währung `XXD`. Hier
**komponiert** ausgedrückt (nicht monolithisch): die vier XX-Fixtures laufen gegen
einen aus Modulen aufgelösten `ResolvedPack`, gegen den **unveränderten** Kern — nur
Pack-Daten + `packPolicy`.

## Aufbau

- `modules/` — die drei adressierbaren Module (je kohärenter Regelsatz, `formatVersion 0.6`):
  - `accounts.xx-minimal` — 7-Konten-Kontenrahmen, **bewusst ohne `tax_in`-Konto**.
  - `tax.xx-salestax` — `ST75` (7,5 %, `mechanism: standard` = reine Ausgangssteuer, bucht auf `210`, `reportingKey S1`
    an der Version — die Kennzahl lebt am Stammdatum, nicht in einem Mapping), `dependsOn` accounts/`xx-minimal`.
  - `policy.xx-policy` — `packPolicy {halfUpAwayFromZero, perLine, 3}`.

  Die USt-VA ist **mapping-frei**: Kennzahl = `taxCodeVersion.reportingKey`, die Projektion gruppiert
  über `taxTag.reportingKey` — kein `vat-report`-Mapping (existiert in der Engine nicht).
- `packs/xx-test-conformance.json` — das **Pack-Manifest**: aufgelöste Modulliste +
  `packPolicy`-Kopie (E-A) + `defaults.taxationMethod = accrual`.
- `xx-1` … `xx-4.json` — die vier Fixtures (Format `fixture/description/covers/setup/steps/projections`
  wie die echten testsuite-Fixtures). Jede Fixture trägt das Pack **inline** in
  `setup.pack` (Module + Manifest), damit sie self-contained ist; `createTenant` (bzw.
  `resolvePack` in XX-1) referenziert das Manifest per `id`+`version`.

## Abdeckung der vier Fixtures (Erwartungswerte 1:1 aus dem packPolicy-Entwurf § 4)

- **xx-1 — `perLine` ≠ `perVoucher`:** `expandTax` von 3×Netto (33.340/33.340/33.320,
  Σ 100.000) rundet je Position auf Skala 3 → Steuer **7.501** (`perVoucher` ergäbe
  exakt 7.500). Beweist: Granularität kommt aus `packPolicy`, nicht aus dem Kern.
- **xx-2 — `allocate` Skala 3 (largest remainder, Gleichstand → erster):** (a) 100.000/3
  → **33.334/33.333/33.333**; (b) Kleinstbetrag 0.010/3 → **0.004/0.003/0.003**. Beweist:
  `allocate` bezieht Zielskala aus `currencyScale`, keine Float-/Prozent-Ausweichlogik.
- **xx-3 — Einkauf ohne Vorsteuerabzug:** gezahlte Sales Tax ist Aufwand (Bruttozeile
  43.000, kein `taxTag`); ein undefinierter Eingangssteuer-Code (`VST75`) scheitert laut
  (`E_TAXCODE_UNKNOWN`); der Report zeigt nur die Ausgangsseite (S1: Basis 100.000, Steuer
  7.501). Beweist: kein Vorsteuerabzug ist eine **Daten**-Aussage, der Kern erzwingt kein
  `tax_in`. Die USt-VA gruppiert mapping-frei über `taxTag.reportingKey` (= `S1` aus der ST75-Version).
- **xx-4 — OP-Lebenszyklus Skala 3:** Forderung 107.501 → Teilzahlung 50.000 (Rest
  **57.501**, `partially_settled`) → Schlusszahlung 57.501 (Rest **0.000**, `settled`),
  explizites `settle`, kanonische 3-Stellen-Form durchgängig. Beweist: OP-Mechanik läuft
  auf Skala 3 byte-identisch.

## Grün-Kriterium (Konformitätsanspruch Punkt 2)

Alle vier Fixtures laufen gegen die **unveränderte** Kern-Implementierung — kein Kern-Diff,
nur Pack-Daten + `packPolicy`, gespeist aus dem aufgelösten Pack-Manifest. Erst dann ist
die Jurisdiktionsfreiheit *erzwungen*, nicht behauptet.

## Offene Nähte / Finding-Kandidaten (für SPEC-FINDINGS bei Review)

- **`setup.pack` vs. `setup.ruleModules`:** Bestehende Fixtures reichen Regelmodul-Daten
  über `setup.ruleModules` (`profiles`/`chartsOfAccounts`/`taxCodes`). Diese Fixtures
  führen `setup.pack {modules, manifest}` als komponiertes Pendant ein — der Runner muss
  das Manifest auflösen (`resolvePack`) und das Ergebnis wie das hand-gereichte
  Regelmodul-Bündel in `createTenant` reichen. Benennung des Setup-Schlüssels bei Freigabe
  bestätigen.
- **`createTenant`-Input `pack` vs. `profile`:** analog zum bestehenden `profile`-Feld;
  hier `pack: { id, version }`. Resultfeld entsprechend `pack` statt `profile`.
- **`allocate` als eigenständige Operation:** in den bestehenden Fixtures wird Largest-
  Remainder über `costAllocationSheet` sichtbar; xx-2 nutzt eine direkte `allocate`-Op
  (deterministische Funktion, `weights`/`total`/`parts`). Ob der Runner `allocate` direkt
  exponiert oder der Fall über Costing geführt werden soll, bei Freigabe klären.
- **`vatReturn`-Kennzahl-Schlüssel `S1`:** nicht-DE `reportingKey` (an der ST75-Version);
  der Report gruppiert mapping-frei über `taxTag.reportingKey`, ohne DE-VA-Konventionen
  (keine Volle-XXD-Abrundung).
- **Währung `XXD`:** fiktiv, nicht ISO; `currencyScale: 3` ist hier die einzige Skalen-Quelle.
