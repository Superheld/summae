# Resolver-Fehler-Fixtures (ENTWURF, Gate 1)

> **Nicht normativ.** Entwurfs-Skizzen aus Bau-Flow-Lauf #1, Gate 1, erstellt
> 2026-06-20. Sie liegen bewusst **außerhalb** von `70-testsuite/` und brauchen
> menschliche Freigabe, bevor sie (nach `conformance-pack-resolver/` o. Ä.) in die
> normative Testsuite wandern. Format gespiegelt an echten Fixtures
> (`70-testsuite/fixtures/**`): `fixture` / `description` / `covers` / `setup` /
> `steps` / `projections`; Fehler als `"expect": { "error": "E_…" }` an einem Step.
> Quellen: `_bauflow-pack-gate01/design/module-manifest-resolver.md` (§3.2 I1–I8,
> §3.5 Entscheidungstabelle), `spec/api.additions.md` (Operation `resolvePack`,
> Auflösungsreihenfolge, Vorrangregel), `spec/fehlerkatalog.additions.md`
> (Code-Definitionen).

## Setup-Konvention dieser Gruppe

Anders als die laufzeitlichen Fixtures (die unter `setup.ruleModules` ein fertiges,
hand-gereichtes Regelmodul-Bündel bekommen) liefern diese Fixtures die **Roh-Bausteine
der Komposition**:

- `setup.modules[]` — der **Modulbestand** (`moduleSource`), aus dem der Resolver wählt;
  jedes Element ist ein Modul im Format aus `datenformat.additions.md` §`module`
  (`formatVersion` `kind` `id` `version` `contributes` `dependsOn` `data`).
- `setup.manifests[]` — ein oder mehrere **Manifeste** (`packs/`-Format): kuratierte
  Modulliste + `overrides` + `packPolicy`-Kopie.
- Der einzige Step ist `resolvePack` mit `input.manifest = { id, version }` (Operation
  laut `api.additions.md`); `expect.error` pinnt den erwarteten `E_PACK_*`-Code. Nur der
  `code` ist vertraglich (Fehlerkatalog-Konvention), der `comment` erklärt den Auslöser.

Alle sechs Manifeste sind **bis auf genau einen Defekt valide** — so prüft jede Fixture
isoliert eine Resolver-Regel, nicht ein Gemenge.

## Abdeckung (je Zeile aus Design §3.5 / api.additions §I1–I8)

| Fixture | Defekt | Prüfung | Erwarteter Code |
|---|---|---|---|
| `missing-account-ref.json` | `assetAccounts.*Account` (gwg/disposal) ohne Konto im Kontenrahmen | I3 | `E_PACK_UNRESOLVED_REF` |
| `taxcode-missing-account.json` | `taxCode.taxAccount` 1776 fehlt im gewählten Kontenrahmen | I1 | `E_PACK_UNRESOLVED_REF` |
| `mapping-missing-accounts.json` | Bilanz-Mapping-Selektor (Bereich 0001–0999) trifft kein Konto | I2 | `E_PACK_UNRESOLVED_REF` |
| `projection-missing-taxtag.json` | Profil/Manifest referenziert taxCode `VSt19`, kein aufgelöstes `tax`-Modul stellt ihn bereit (mapping-frei) | I4 | `E_PACK_UNRESOLVED_REF` |
| `dependency-cycle.json` | `dependsOn`-Zyklus coa-a ↔ coa-b (DAG ist keiner) | Schritt 3 | `E_PACK_INCOHERENT` |
| `colliding-override.json` | `replace`-Override greift ins Leere (ref nicht in `modules`) | Schritt 1 / Override-Semantik | `E_PACK_INCOHERENT` |

## Die Trennlinie, die diese sechs Fixtures beweisen

- **`E_PACK_UNRESOLVED_REF`** (4 Fixtures) = *eine Referenz zeigt ins Nichts*: ein Konto,
  ein Selektor-Ziel oder ein referenzierter taxCode existiert nicht (I1–I4). „Ich suche X und finde X nicht."
- **`E_PACK_INCOHERENT`** (2 Fixtures) = *die Referenzen existieren, aber das Bündel ist
  in sich widersprüchlich*: Zyklus, ins-Leere-greifender Override. „Alles da, passt nicht
  zusammen." Die Vorrangregel (`UNRESOLVED_REF` vor `INCOHERENT` vor `POLICY_INVALID`,
  api.additions §Vorrangregel) ist hier nicht reizbar, weil jede Fixture isoliert genau
  einen Defekt trägt — gewollt, damit der erwartete Code eindeutig bleibt.

## Offen / bewusst nicht hier

- `E_POLICY_INVALID` (Manifest-`packPolicy`-Kopie ≠ aufgelöstes `policy`-Modul, Enum/Skala
  außerhalb Bereich) und `E_AMOUNT_SCALE_MISMATCH` (Reader/Writer, Betrag-Stellenzahl)
  sind **eigene** Codes — gehören zur packPolicy-/Skala-Fixturegruppe (Test-Pack XX,
  `currencyScale 3`, `perLine`), nicht zu „Resolver-Fehler". Aufgabe: separate Gruppe.
- I6 (Konto-`number`-Kollision aus zwei Kontenrahmen), I7 (doppelter `taxCode.code` /
  zwei `policy`-Module), I8 (`E_MAPPING_OVERLAP`, bestehend) sind weitere Resolver-Fälle,
  als Erweiterung der Gruppe vorgemerkt, hier nicht erzwungen.
- **I5 (`dimensionRules[].accounts`) ist zurückgestellt** (kommunal, nicht v1) — `kind:
  dimensionRules` ist nicht im aktiven Enum, die Feldform nicht verankert; daher in Gate 0
  bewusst **keine** I5-Fixture (siehe GATE1-LUECKEN.md / design § 1.2–1.3).
- Positiv-Lauf („DE komponiert == DE heute", XX komponiert) liegt in den jeweils eigenen
  Gruppen (Regressions-Orakel bzw. `conformance-xx`), nicht in `resolver-errors/`.
