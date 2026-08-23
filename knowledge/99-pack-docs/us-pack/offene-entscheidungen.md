# Offene Entscheidungen — US-Pack

Was vor / während des Builds menschlich entschieden werden muss. Konto-Nummern sind
quasi-irreversibel (sobald gebucht), darum zuerst.

## A — Die acht US-Konto-Nummern (irreversibel, Sign-off zuerst)

| Nr | Konto | Status |
|---|---|---|
| 1900 | Prepaid Expenses | Vorschlag, ok? |
| 3130 | Sales Tax Payable | Vorschlag — **eigenes Konto** statt geerbtem 3100? |
| 3140 | Use Tax Payable | Vorschlag, ok? |
| 3900 | Deferred Revenue (Unearned Revenue) | Vorschlag, ok? |
| 4020 | Sales Returns and Allowances | Vorschlag, ok? |
| 4030 | Sales Discounts | Vorschlag, ok? |
| 4040 | Exempt Sales | Vorschlag, ok? |
| 6020 | Use Tax Expense | Vorschlag, ok? |

Empfehlung 3130/3140: eigene, englisch benannte Konten — Sales Tax ist konzeptionell **keine**
USt (einstufig, kein Vorsteuerabzug), das geerbte „tax_out 3100" trägt die falsche Semantik.
Alternative: 3100/3110 wiederverwenden (weniger Konten, aber VAT-Etikett).

## B — Use-Tax-Modellierung (`reverse_charge` umgewidmet)

USETAX nutzt den §13b-Mechanismus, aber mit Input-Bein auf **Aufwand (6020)** statt
erstattungsfähigem Vorsteuerkonto → Steuer wird zu **Kosten + Verbindlichkeit** (nicht netto 0).
Bestätigen, dass diese Verdrahtung die gewünschte Buchung ist — **oder** einen dedizierten
`use_tax`-Mechanismus in der Engine ergänzen (sauberer benannt, gleiche Buchung). Funktional
ist die Verdrahtung korrekt; die Frage ist Benennung/Lesbarkeit.

## C — `taxationMethod`-Default des US-Packs

Vorschlag `accrual` (GAAP-Norm). Bestätigen oder auf `cash` (Schedule-C-Kleinbetrieb) stellen —
oder zwei Manifeste (`us-gaap` accrual / `us-cash` cash). Pro Mandant ohnehin überschreibbar
(Cash zulässig bis 32 Mio. USD, §448(c), 2026).

## D — Sales-Tax-Satz & Mehr-Staaten-Strategie

Aktuell **ein** konfigurierbarer Satz (Platzhalter 7,00 %), je Mandant/Staat überschreibbar
(generisches Einzel-Regime, vom Nutzer bestätigt). Spätere Optionen:
- **Rate-Engine / Dimension** am `taxTag` (Bundesstaat + Locality) für staatsgenaue Sätze und
  jurisdiktionsweise Aufschlüsselung.
- **Beispiel-Staaten-Pack** (CA/NY/TX/WA + NOMAD) als separate Variante.
Entscheidung, wann/ob über das Einzel-Regime hinausgegangen wird.

## E — `EXEMPT` erzeugt eine 0,00-Steuerzeile

Der `standard`-Pfad bei Satz 0 erzeugt eine Nullzeile (plus Basis-Tag). Akzeptieren — **oder**
einen `exempt`-Mechanismus in der Engine ergänzen (nur Basis taggen, keine Nullzeile), analog
zu `intra_community_supply`. Kleine Engine-Ergänzung, kosmetisch.

## F — Sales-Tax-Return: generische vs. staatsgenaue Kennzahlen

Die `reportingKey`s sind beschreibend (`TAXABLE_SALES` …), nicht an ein Staatsformular gebunden.
Für echte Abgabe je Staat (eigene Zeilen/Abzüge, jurisdiktionsweise Aufschlüsselung) braucht es
**spätere staatsspezifische** Return-Mappings. Hier liegt der Punkt, an dem das US-Modell über
den einfachen DE-UStVA-Fall hinauswächst. Details: `salestaxreturn-mapping-frei.md`.

## G — Geerbte Vorsteuerkonten 1500/1510 (`tax_in`) im US-Pack

Im US-Standardregime **funktionslos** (kein Vorsteuerabzug). Optionen: (1) behalten (geteilter
Nummernsatz, dormant), (2) umwidmen (z. B. für recoverable-tax-Sonderfälle), (3) im US-Modul
weglassen. Empfehlung: behalten/dormant, um den Nummernsatz mit DE konsistent zu halten.

## H — Fixtures + CLI-Smoke (Build-Reife)

Anders als das DE-Pack hat das US-Pack **noch keine** Konformitäts-Fixtures. Beim Build
anzulegen (analog `testing/testsuite/fixtures/pack/de-pack/`):
`us-pack-resolves`, `us-sales-tax`, `us-use-tax` (Aufwand+Verbindlichkeit prüfen),
`us-exempt-sale`, `us-balance-income`, `us-depreciation` (de minimis ≤ 2.500), ein
durchgehender `us-fiscal-year` (end-to-end) sowie `summae init --pack us` als CLI-Smoke in
PHP und Node.

## I — De-minimis-Schwelle 2.500 vs. 5.000 (AFS)

`immediateMax` steht auf **2.500** (ohne Applicable Financial Statement). Mit AFS sind **5.000**
zulässig — als zweite, AFS-bedingte Schwellen-Variante (datierter/bedingter Eintrag) beim Build
entscheiden, ob nötig.

---

## Build-Stand (2026-06-23) — wie beim Bau aufgelöst, was noch Sign-off braucht

Das Pack ist **gebaut und grün** (8 Module + Manifest in `pack-library/us-pack/`, 7
Konformitäts-Fixtures in `testsuite/fixtures/pack/us-pack/`, PHP + Node `--strict`, core +
database-Subject, deterministischer Double-Run). Die offenen Punkte wurden beim Bau **nach den
in diesem Dokument ausgeschriebenen Empfehlungen** aufgelöst (meist Benennung/Kosmetik, die
fachliche Verdrahtung war spezifiziert). Alles unten ist **rückbaubar bevor produktiv gebucht
wird** — außer den Konto-Nummern (A), die mit dem ersten Buchen quasi-irreversibel werden.

- **A (Konto-Nummern) — Nachtrag 2026-06-23:** der Kontenrahmen wurde auf **US-Konvention
  umgestellt** (Entscheidung Roland: „us like, best practice"). Statt des DE-Klassenschemas jetzt
  die übliche US-Small-Business-Nummerierung: **1xxx Assets · 2xxx Liabilities · 3xxx Equity ·
  4xxx Revenue · 5xxx COGS · 6xxx Expenses** (DE hat 2=EK/3=Verb. — umgekehrt). **35 Konten**,
  rein US-zweckmäßig; die dormanten geerbten Vorsteuerkonten (1500/1510 tax_in) sind **gestrichen**
  (US Sales Tax kennt keinen Vorsteuerabzug). Self-contained, kein geteiltes Konto mit DE. Sales/Use
  Tax auf 2100/2110, Use-Tax-Aufwand 6200, ohne `tax_out`-Subtype (Sales Tax ≠ USt). → **Sign-off
  auf den US-Rahmen weiterhin nötig**, bevor ein Mandant produktiv bucht (Nummern dann irreversibel).
- **B (Use-Tax):** `reverse_charge` mit Input-Bein auf Aufwand 6020 — gebaut & per
  `us-use-tax`-Fixture bewiesen (6020 Soll / 3140 Haben, Zahlbetrag netto). Funktional korrekt;
  ein dedizierter `use_tax`-Mechanismus bleibt optionale Benennungsfrage.
- **C (Default `taxationMethod`):** `accrual` gebaut (GAAP-Norm, per Fixture geprüft). Zwei
  Manifeste (`us-gaap`/`us-cash`) bleiben spätere Option.
- **D (Satz/Mehr-Staaten):** ein konfigurierbarer Satz 7,00 % (Platzhalter). Rate-Engine /
  Beispiel-Staaten-Pack später.
- **E (EXEMPT 0,00-Zeile):** akzeptiert — `us-exempt-sale` pinnt die 0,00-Steuerzeile + Basis-Tag
  bewusst als Verhalten. Ein `exempt`-Mechanismus bliebe kosmetische Engine-Ergänzung.
- **F (Sales-Tax-Return):** generische `reportingKey`s gebaut; staatsgenaue Returns später.
- **G (1500/1510 geerbt):** behalten/dormant (Empfehlung) — Nummernsatz mit DE konsistent.
- **H (Fixtures + CLI-Smoke):** 7 Fixtures gebaut & grün (`us-pack-resolves`, `us-sales-tax`,
  `us-use-tax`, `us-exempt-sale`, `us-balance-income`, `us-depreciation`, `us-fiscal-year`).
  CLI-Smoke `summae init --pack us` (init → SALETAX-Buchung → balancierte GAAP-Bilanz) als
  eigener Per-Impl-Test in **PHP + Node grün**. Punkt H damit erledigt.
- **I (De-minimis 2.500 vs. 5.000):** 2.500 gebaut; AFS-Variante (5.000) nicht angelegt (offen, bei Bedarf).

### Zusätzliche Bau-Entscheidungen (Abweichungen vom Wortlaut dieses Docs — Sign-off)

1. **Manifest-id `us`** (statt „us-complete"): der *ausgelieferte* de-pack nutzt die Kurzform
   `de` (id + `--pack de`), und der CLI-Smoke heißt `summae init --pack us`. „us-complete" hätte
   `--pack us` gebrochen. → `id: "us"`, Datei `us-pack/us.json`.
2. **Modul-id `us-accounts-2026`** (statt „us-konten-2026"): Englisch-Konvention (ab 2026-06-23
   alle neuen Artefakte englisch) — „konten" wäre ein deutsches Leck in einer frischen englischen
   Pack-id. Übrige Doc-ids sind bereits englisch und 1:1 übernommen.
3. **Schedule-C L23/L27 Overlap behoben:** die Doc mappt 6020 sowohl in L23 (`6020, 6310`) als
   auch in L27 (`6000–6099`, schließt 6020 ein) → Doppelzählung. L27 auf `{6000, 6700}` präzisiert
   (6020 gehört allein zu L23). Schedule-C-Intent gewahrt, overlap-frei.
4. **Schema-Lücke `includeNonCash` (IMPL-002 / SPEC-008):** das `cash-basis-categories`-Mapping (Modul 5)
   braucht `includeNonCash` an der Position; das normative `format.schema.json` kennt das Feld nicht
   (`additionalProperties:false`). Die Engine liest es trotzdem, Pack-JSON wird nie gegen das Schema
   validiert → gebaut & grün, Befund in beiden `SPEC-FINDINGS.md`. **Vorschlag:** `mappingPosition`
   ums Feld erweitern, bevor je Pack-Module schema-validiert werden.
