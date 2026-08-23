# API-Spezifikation v0.5 (Entwurf)

Sprachneutral: Operationen, Eingaben, Invarianten, Fehlercodes. Jede Runtime-Implementierung (erste: PHP ✅, dann Node, Python) setzt sie idiomatisch um (Methodennamen-Casing etc. nach Sprachkonvention), Semantik und Namen sind bindend. Schreiboperationen sind die einzigen Wege, Daten zu ändern, und erzwingen alle Invarianten.

## Zwei Lesarten dieser API

Die Abschnitte unten sind nach **Bounded Context** gegliedert (Ledger, Tax, Assets, Costing) — die Struktur für Implementierer. Quer dazu ist jede Operation **eine Politiksorte** (NF-5.3): **Substrat** (`post`, `correct`, Journal/Saldo), **Expansion** (`expandTax`, `postVoucher`, `settle`/`settleVoucher`, `runDepreciation`, `acquireAsset`-GWG, `disposeAsset`, `reverse`), **Projektion** (eigener Abschnitt unten) oder **Constraint** (siehe „Constraints/Invarianten" bei der Prüfreihenfolge). Der vollständige Politiksorten-Zensus steht in `40-domaenenmodell/jurisdiction-profil.md` — diese Spec wiederholt ihn nicht, sondern verweist, damit es eine einzige Quelle gibt. Der **Pack-Resolver** (unten) liegt **vor** dieser Achse: Er ist keine Politiksorte, sondern die Auflösungsschicht, die das **Pack** (Träger der Constraint-/Projektions-/Expansions-*Daten*) aus Modulen zusammensetzt — die Politiksorten-Mechanik selbst bleibt Kern und unverändert (`jurisdiction-profil.md` § Komponierbare Packs).

## Konventionen

- Jede Operation läuft im Kontext eines Mandanten (`tenant`).
- Jede lesende Operation, die rechnet, nimmt ein **Bezugsdatum** (`asOf`, Default: heute) — NF-5.1/F-CORE-016.
- Fehler sind Teil des Vertrags: gleicher Verstoß → gleicher Fehlercode in allen Implementierungen. Format: `E_<BEREICH>_<GRUND>`.

## Ledger

| Operation | Eingabe → Ergebnis | Wichtigste Fehler |
|---|---|---|
| `post` | Buchungsentwurf (Positionen, voucherId, Daten, Dimensionen) → JournalEntry (mit sequenceNumber) | `E_ENTRY_UNBALANCED`, `E_ENTRY_NO_VOUCHER`, `E_PERIOD_CLOSED`, `E_ACCOUNT_LOCKED`, `E_ACCOUNT_UNKNOWN`, `E_DIMENSION_INVALID` |
| `correct` | entryId + geänderter Entwurf (nur Status `entered`) → JournalEntry; Audit-Eintrag | `E_ENTRY_FINALIZED`, `E_ENTRY_UNKNOWN` |
| `finalize` | entryId \| `finalizeUntil(date)` → Anzahl festgeschrieben | `E_ENTRY_UNKNOWN` |
| `reverse` | entryId (+ Datum, Text) → Storno-JournalEntry mit Rückverweis; gleicht die offenen Posten der stornierten Buchung aus | `E_ENTRY_ALREADY_REVERSED`, `E_PERIOD_CLOSED`, `E_ENTRY_UNKNOWN`, `E_ENTRY_HAS_SETTLED_ITEMS` |
| `settle` | Zahlungs-entryId + Zuordnungen [(openItemId, money)] → OpenItem-Stände | `E_SETTLEMENT_EXCEEDS_ITEM`, `E_SETTLEMENT_DIFFERENCE_INVALID`, `E_OPENITEM_UNKNOWN` |
| `closePeriod` / `reopenPeriod` | periodRef → Periodenstatus | `E_PERIOD_OUT_OF_ORDER`, `E_FISCALYEAR_CLOSED` |
| `closeFiscalYear` | fiscalYear → Status (Voraussetzung: alle Perioden geschlossen UND alle Buchungen festgeschrieben) | `E_PERIOD_OUT_OF_ORDER`, `E_FISCALYEAR_UNFINALIZED_ENTRIES` |
| `createAccount` / `lockAccount` | Kontodaten → Account | `E_ACCOUNT_NUMBER_TAKEN` |
| `importChartOfAccounts` | Kontenrahmen-Daten (DATEV-kompatibel) → Anzahl Konten | `E_COA_FORMAT_INVALID` |
| `post` (Beleg) | … | zusätzlich `E_VOUCHER_UNKNOWN` (voucherId gesetzt, Beleg fehlt) |
| `createTenant` | Name, Währung, Profil → Tenant (sofort buchbar, SF-01) | `E_PROFILE_UNKNOWN` |

**Maschinell erzeugte Buchungen werden sofort festgeschrieben** (v0.5/SPEC-009): Buchungen aus generierenden Operationen (`acquireAsset`, `disposeAsset`, `runDepreciation` und künftige Läufe) entstehen direkt im Status `finalized`, nicht `entered`. Andernfalls scheiterte `closeFiscalYear` an scheinbar offenen Buchungen, die kein Anwender je manuell festschreibt. GoBD-konform (Maschinenbuchungen sind unmittelbar verbindlich) und Voraussetzung dafür, dass der Jahresabschluss-Guard (`E_FISCALYEAR_UNFINALIZED_ENTRIES`) nur echte Entwurfsbuchungen meint.

**`reverse` räumt die offenen Posten mit ab** (v0.6/IMPL-008): Ist aus der stornierten Buchung ein offener Posten entstanden, erhält dieser einen Ausgleich über den Restbetrag, der auf die Storno-Buchung zeigt und `cause: "cancellation"` trägt — abgeleiteter Status `cancelled`, weg aus der OP-Liste, Historie bleibt (append-only, es wird nichts gelöscht). Trägt einer der Posten bereits einen Ausgleich, wird das Storno mit `E_ENTRY_HAS_SETTLED_ITEMS` **verweigert**; die Korrektur läuft dann über Rückzahlung/Gutschrift als eigene Buchung. Begründung und der §17-Bezug stehen im Fehlerkatalog (Abschnitt `E_ENTRY`).

**`acquireAsset` kennt Nutzungsdauer und Methode je Zugang** (2026-08-23): `usefulLifeMonths` (ganze Monate ≥ 1) schlägt die Klassen-Tabelle des Packs — der Fall „im Einzelfall nachgewiesene abweichende Nutzungsdauer", den eine Tabelle von Klassendurchschnitten prinzipiell nicht abbilden kann; er macht zugleich eine dem Pack unbekannte Klasse nutzbar. `depreciationMethod` wählt `straight_line` (Default) oder `declining_balance`. Beide gelten **nur** für die kapitalisierte Route: beim Pool kommt die Dauer aus `poolYears`, bei Sofortabschreibung gibt es keinen Plan — deshalb `E_INPUT_INVALID` statt stillem Verwerfen, und deshalb leitet keiner der beiden Parameter eine Route ab. Die degressiven Zahlen (Faktor, Deckel, Gültigkeitsfenster) sind Pack-Daten (`decliningBalance`); fehlt eine Regel zum Anschaffungsdatum, ist die Methodenwahl `E_PACK_INCOHERENT`. Der Wechsel auf linear ist Mechanik und geschieht automatisch am optimalen Punkt.

**`reverse` nimmt einen eigenen Stornobeleg** (2026-08-23): Ein mitgegebenes `voucherId` wird verwendet (Prüfung wie überall, `E_VOUCHER_UNKNOWN`); ohne Angabe erbt die Stornobuchung den Beleg der stornierten. Vorher wurde ein mitgegebener Beleg kommentarlos verworfen.

**`reverse` ist statusunabhängig** (v0.5/SPEC-002): auch eine Buchung im Status `entered` darf storniert werden; `E_ENTRY_NOT_FINALIZED` existiert nicht (frühere offene Frage 5 damit geschlossen). **`closePeriod` ist unabhängig von der Festschreibung** — eine Periode kann geschlossen werden, während Buchungen noch `entered` sind; die Festschreibungspflicht wird erst beim `closeFiscalYear` erzwungen (sowie GoBD-seitig spätestens mit der USt-VA, App-getrieben über `unfinalizedEntries`).

## Projektionen (lesend, deterministisch, `asOf`-fähig)

`trialBalance` (SuSa) · `accountSheet` (Kontoblatt) · `balanceSheet` · `incomeStatement` · `cashBasisReport` (EÜR, Regeln R1–R7) · `vatReturn` (USt-VA-Kennzahlen) · `openItems` (OP-Liste) · `assetRegister` (Anlageverzeichnis) · `auditLog` (Änderungshistorie) · `journalExport` (GoBD Z3) · `datevExport` (Buchungsstapel)

Alle: Zeitraum/Stichtag + Optionen rein → strukturierte Daten raus. Kein Zustand, keine Seiteneffekte; gleiche Eingabe → byte-identisches Ergebnis (nach Kanonisierung) in allen Implementierungen.

### Zeitraum-Semantik (verbindlich, v0.3 — schließt Review-Befund G1)

- **Bestandskonten (asset/liability/equity): kumulativ ab Datenbestand-Beginn.** Der Saldovortrag ist implizit — es gibt keine Saldenabschluss-/Eröffnungsbuchungen (SBK/EBK). `trialBalance {fiscalYear, throughPeriod}` zeigt für Bestandskonten den kumulierten Saldo *bis einschließlich* dieser Periode (inkl. aller Vorjahre), für Erfolgskonten nur die Verkehrszahlen *des angegebenen Geschäftsjahres*.
- **Erfolgskonten (expense/revenue): je Geschäftsjahr.** Im neuen Jahr starten sie bei null — per Definition der Projektion, nicht per Buchung.
- **`balanceSheet {asOf}`:** kumulativ zum Stichtag; Position mit `includesNetIncome: true` enthält die **kumulierten** Jahresergebnisse aller Jahre bis zum Stichtag (Bilanzidentität by construction).
- **`incomeStatement {fiscalYear}`:** genau ein Geschäftsjahr.
- **`cashBasisReport {year}`:** Kalenderjahr; bei abweichendem Geschäftsjahr → `E_CASHBASIS_DEVIATING_FISCAL_YEAR` (EÜR ist kalenderjahrgebunden). Nicht zahlungswirksame Kategorien nach R7 (`includeNonCash` im cash-basis-Mapping: AfA, unentgeltliche Wertabgaben). **Geldkonto (R1) := Konto mit subtype ∈ {`bank`, `cash`}** — `cash` ergänzt die Subtype-Liste (GoBD: Trennung barer/unbarer Vorgänge; Kassen-*Führung* bleibt App-Sache).
- **`vatReturn`:** Bemessungsgrundlagen je Kennzahl auf **volle Euro abgerundet**, Steuerbeträge centgenau (amtliche VA-Konvention; determinismuskritisch).
- **`closeFiscalYear`:** Statuswechsel mit Voraussetzungen (alle Perioden geschlossen, alle Buchungen festgeschrieben) — **keine** fachliche Buchungswirkung. Wertändernde Abschlussbuchungen (AfA-Lauf etc.) sind davor als normale Buchungen zu erfassen.

### Ausgleich mit Differenz (v0.3 — schließt Review-Befund G2)

`settle`-Zuordnungen: `{ openItemId, money, difference?: { money, kind: "discount" | "bad_debt" | "minor" } }`. `money` = ausgeglichener OP-Betrag *einschließlich* Differenz; die Differenz selbst MUSS als Buchungszeile(n) in der ausgleichenden Buchung enthalten sein (Erlösschmälerung/Aufwand + USt-Korrektur per Steuerschlüssel, § 17 UStG) — sichtbar, belegt, GoBD-konform. Ungültige Differenz (`kind` unbekannt, Betrag ≤ 0 oder > Restbetrag) → `E_SETTLEMENT_DIFFERENCE_INVALID`.

**Komposition `settleVoucher`** (Pendant zu `postVoucher`, Teil der Spec): Zahlungsdaten + OP-Zuordnungen + optionale Differenzart rein → Zahlungsbuchung inkl. Differenz- und Steuerkorrektur-Zeilen + `settle` in einem Aufruf.

Mischbelege: `expandTax` akzeptiert den Steuercode **je Position** (`netLines[].taxCode`, globaler `taxCode` als Default); Rundung weiterhin pro Beleg *je Steuersatz*.

### v0.4-Ergänzungen (Buchhalter- + StB-Review)

- **`expandTax` wählt die Regelversion nach dem Leistungsdatum** (`serviceDate`, Fallback voucherDate) — § 27 UStG. `vatReturn` ordnet bei Soll-Versteuerung nach Leistungsdatum zu.
- **Partner-Operationen:** `createPartner` / `updatePartner` (Audit!); unbekannte `partnerId` am Beleg → `E_PARTNER_UNKNOWN`. Projektionen: `openItems` filtert nach `partnerId`; **`ecSalesList`** (ZM-Grundlage: ig. Umsätze je USt-IdNr. und Zeitraum).
- **`createFiscalYear {year, start, end, periods?}`** (Buchhalter-M: fehlte als Operation); Überschneidung mit bestehendem Jahr → `E_FISCALYEAR_OVERLAP`.
- **`trialBalance`-Zeilen verbindlich:** `openingBalance` (kumulierter Saldo vor dem GJ; 0 bei Erfolgskonten), `debitTotal`/`creditTotal` (Verkehrszahlen des Zeitraums), `balance` — die SuSa-Spalten der Praxis.
- **`incomeStatement {fiscalYear, fromPeriod?, throughPeriod?}`** — Monats-/Quartalsauswertung (BWA-Grundlage, Buchhalter-G5); DATEV-BWA Form 01 ist ein Mapping-Regelmodul (Lieferaufgabe, kein Modellthema).
- **`unfinalizedEntries {olderThanDays}`** — Projektion für die Festschreibe-Frist (GoBD: spätestens mit VA); Erinnern ist App-Sache, die Abfrage liefern wir.
- **`systemDocumentation`** — generiert die technische Systembeschreibung (Baustein der Verfahrensdokumentation, GoBD Rz. 151 ff.): Formatversion, aktive Regelmodule + Versionen, Mandanten-Betriebsparameter, Unveränderbarkeits-/Exportmechanik (StB-3).
- **`runDepreciation`** unterstützt AfA-Methode `declining` inkl. automatischem Wechsel zu linear (StB-4); Sätze/Zeiträume aus dem Regelmodul.

## Tax

| Operation | Eingabe → Ergebnis | Fehler |
|---|---|---|
| `expandTax` | Belegdaten + taxCode + Datum + TaxProfile → vollständige Positionserweiterung | `E_TAXCODE_UNKNOWN`, `E_TAXCODE_NO_VALID_VERSION` |
| `setTaxProfile` | Versteuerungsart/KU-Status mit `validFrom` → TaxProfile | `E_PROFILE_RETROACTIVE_CONFLICT` |

## Assets

`acquireAsset` (optional `dimensions[]` — Kostenstelle o. Ä. am Anlagegut; **jede** maschinelle Buchung dazu erbt sie, sonst wäre bei Pflichtdimension auf dem AfA-Konto gar keine Abschreibung buchbar) · `disposeAsset` (bucht die bis zum Abgangsdatum fällige AfA nach, schreibt dann den **Restbuchwert** vom Anlagekonto ab und stellt die Differenz zum Erlös als Gewinn (`disposalProceedsAccount`) oder Verlust (`disposalLossAccount`) ein; ein Gut, dessen Pack den Pool beim Abgang nicht vermindert, wird **nicht** ausgebucht — dort läuft die Pool-Rate weiter) · `runDepreciation({fiscalYear} | {fiscalYear, period})` — Jahres- oder Monatslauf; Monatsraten per `allocate` über die Gesamtlaufzeit (determinismus.md §2), idempotent je Lauf-Ziel (Wiederholung: No-op mit `alreadyRun: true`) · `writeDown` · `writeUp` (Zuschreibung, max. bis fortgeführte AHK)

## Regelmodul-Verwaltung

`importMapping` — validiert beim Import: Überlappung (ein Konto in zwei Positionen) → `E_MAPPING_OVERLAP`; Lücken (Konto ohne Position) → kein Fehler, Rückgabe `gapWarnings[]` mit Auffangposition.

`resolvePack` — löst ein Manifest (kuratierte Modulliste) gegen den Modulbestand zu
einem `ResolvedPack` auf und prüft referentielle Integrität; **scheitert laut**
(`E_PACK_UNRESOLVED_REF`/`E_PACK_INCOHERENT`/`E_POLICY_INVALID`). Vollständige Semantik
im eigenen Abschnitt **Pack-Resolver** unten.

## Pack-Resolver

Ein **Pack** ist kein Monolith, sondern eine **aufgelöste Komposition von Modulen**
(`jurisdiction-profil.md` § Komponierbare Packs; Daten- und Schema-Form in
`datenformat.md` § Modul-/Manifest-Format). Diese API spezifiziert die *Auflösung*:
ein **Manifest** (kuratierte Modulliste + Overrides + `packPolicy`-Kopie) wird gegen
einen Modulbestand zu einem `ResolvedPack` aufgelöst — genau die Struktur, die der
Mandanten-Aufbau (`createTenant`) sonst als hand-gereichtes Regelmodul-Bündel bekommt.

Der Resolver fügt **keine Engine-Fähigkeit** hinzu — er ist eine Auflösungsschicht
*vor* dem, was die Engine schon kennt (Kontenrahmen, `taxCodes`, Mappings,
`assetAccounts`, `packPolicy`). Er erfindet nichts; er wählt, prüft und faltet
vorhandene Daten zu genau der bestehenden Mandanten-Eingabe.

| Operation | Eingabe → Ergebnis | Wichtigste Fehler |
|---|---|---|
| `resolvePack` | Manifest (`id`+`version`) + Modulbestand → `ResolvedPack` | `E_PACK_UNRESOLVED_REF`, `E_PACK_INCOHERENT`, `E_POLICY_INVALID` |

`resolvePack` ist **rein und seiteneffektfrei** und **deterministisch**: gleiches
Manifest + gleicher Modulbestand → byte-identischer `ResolvedPack` (eiserne Invariante
Determinismus, NF-2.3). `createTenant` referenziert ein Manifest per `id`+`version`,
löst es **einmal beim Aufbau** auf und pinnt das Ergebnis — Modul-/Pack-Updates wirken
nicht rückwirkend, das Upgrade ist explizit (dieselbe Pinning-Semantik wie bisher beim
Profil, `datenformat.md`).

**fail-loud.** Der Resolver gibt **entweder** einen vollständig integren `ResolvedPack`
zurück **oder** genau **einen** `E_PACK_*`/`E_POLICY_*`-Fehler — **kein** partieller
Pack, **keine** Warnung als Schummel-Erfolg (`jurisdiction-profil.md` § Resolver:
„scheitert laut statt still falsch zu rechnen"). Einzige Ausnahme sind die bestehenden
Mapping-`gapWarnings` (Regelmodul-Verwaltung oben) — eine Mapping-Lücke ist eine
Auffangposition, kein Bruch. Wie überall gilt die Fehlerkatalog-Konvention: in Fixtures
wird nur der `code` geprüft, der Wortlaut ist frei; `details` (beteiligte `{kind, id}`,
Konto-`number`, `code`) sind diagnostisch, nicht vertraglich.

### Auflösungsreihenfolge (deterministisch)

Der Resolver arbeitet in fester Reihenfolge — die Reihenfolge ist normativ, weil sie
festlegt, **welcher** Fehler bei mehreren Verstößen gewinnt:

1. **Effektive Modulliste bilden** — `modules` nehmen, `overrides` anwenden (`remove`
   vor `replace`, in Array-Reihenfolge). Jeder Override MUSS greifen.
2. **Modul-Referenzen auflösen** — jede `{kind, id, version?}` gegen den Modulbestand.
   Fehlt `version`, gilt die höchste verfügbare Version je `(kind, id)` nach
   **String-Codepoint-Vergleich** (Determinismus-Sortierregel — explizit und
   reproduzierbar, kein semver-„neuer als"-Raten). Modul/Version nicht gefunden →
   `E_PACK_UNRESOLVED_REF`.
3. **Abhängigkeits-DAG bilden** aus `dependsOn`, topologisch sortiert über `(kind, id)`
   mit stabiler Tie-Break-Ordnung (Codepoint). Eine `dependsOn`-Referenz, die in der
   effektiven Liste fehlt → `E_PACK_UNRESOLVED_REF`. **Zyklus** → `E_PACK_INCOHERENT`.
4. **In topologischer Reihenfolge falten** — Beiträge je `contributes`-Sorte in die
   `ResolvedPack`-Felder einspeisen (`accounts` zuerst, weil `tax`/`mapping`/
   `assetAccounts` sie referenzieren). Kollidierende Beiträge → `E_PACK_INCOHERENT`
   (siehe „Override- und Kollisions-Semantik").
5. **Referentielle Integrität prüfen** (I1–I8 unten) auf dem gefalteten Stand.
6. **`packPolicy` abgleichen** — die denormalisierte Manifest-Kopie MUSS mit dem
   aufgelösten `policy`-Modul übereinstimmen, sonst `E_POLICY_INVALID`. Policy-Werte
   gegen ihren Wertebereich prüfen (Enums; `currencyScale` 0–4; für ISO-Währungen
   Übereinstimmung mit dem ISO-Exponenten) — sonst `E_POLICY_INVALID`.

**Vorrangregel (eindeutige Fehlerklasse je Manifest):** Referenz-Existenz (Schritte
2/3) **vor** Kohärenz/Integrität (4/5) **vor** Policy (6). Träfen mehrere Codes zugleich
zu, gewinnt der **früheste** Schritt — `E_PACK_UNRESOLVED_REF` hat Vorrang vor
`E_PACK_INCOHERENT`, dieses vor `E_POLICY_INVALID`. So liefert jedes Manifest genau
einen, reproduzierbaren Fehlercode (Parität mit der Buchungs-Prüfreihenfolge unten).

### Referentielle-Integritäts-Prüfungen

Auf dem gefalteten Stand (Schritt 5), jede als eigene Prüfung mit eigenem Code:

| # | Prüfung | Verletzung → Code |
|---|---|---|
| I1 | Jeder `taxCode.versions[].taxAccount` (und bei `mechanism: reverse_charge` auch `inputTaxAccount`) existiert im gefalteten Kontenrahmen. | `E_PACK_UNRESOLVED_REF` |
| I2 | Jeder `mapping.positions[].accounts`-Selektor (Einzelkonto/Bereich) trifft ≥ 1 existierendes Konto. I2 feuert **nur**, wenn ein Selektor **vollständig** ins Leere zeigt (0 Treffer); Teiltreffer / unabgedeckte Konten bleiben `gapWarnings` (bestehende Regel, unberührt). | `E_PACK_UNRESOLVED_REF` |
| I3 | Jedes der **fünf** `assetAccounts.*Account` (`acquisitionCounterAccount`, `depreciationExpenseAccount`, `gwgExpenseAccount`, `disposalProceedsAccount`, `disposalLossAccount`) + alle `perClass`-Overrides existiert im Kontenrahmen. | `E_PACK_UNRESOLVED_REF` |
| I4 | Jeder `taxCode`, den ein **Profil/Manifest** referenziert (`packManifest.taxCodes` → synthetisiertes Profil, reale `profile.taxCodes`-Form), wird von einem aufgelösten `tax`-Modul **bereitgestellt**. Mapping-frei: kein `vat-report`-Mapping, keine `reportingKey`→`taxCode`-Relation — die Kennzahl lebt an der `taxCodeVersion`. | `E_PACK_UNRESOLVED_REF` |
| ~~I5~~ | **Zurückgestellt** (kommunal, nicht v1): `dimensionRules[].accounts` existieren im Kontenrahmen. Setzt die nicht-normierte `dimensionRules`-Feldform voraus — in Gate 0 nicht aktiv. | — |
| I6 | Konto-`number` ist **eindeutig** über alle `accounts`-Module hinweg. | `E_PACK_INCOHERENT` |
| I7 | `taxCode.code` eindeutig, `mapping.id` eindeutig, **höchstens ein** `policy`-Modul. | `E_PACK_INCOHERENT` |
| I8 | Mapping-intern: ein Konto fällt in **genau eine** Position. | `E_MAPPING_OVERLAP` (bestehend) |

**Die Trennlinie der zwei Resolver-Codes** (der Knackpunkt der Komposition):

- **`E_PACK_UNRESOLVED_REF`** = eine Referenz **zeigt ins Nichts**: Modul/Version nicht
  gefunden (Schritt 2/3), Konto / Selektor-Ziel / referenzierter `taxCode` existiert nicht (I1–I4).
  *„Ich suche X und finde X nicht."*
- **`E_PACK_INCOHERENT`** = die Referenzen existieren, aber das Bündel ist **in sich
  widersprüchlich**: Abhängigkeits-Zyklus (Schritt 3), Identitäts-Kollision (I6/I7),
  kollidierender oder ins Leere greifender Override, unbekanntes `kind`.
  *„Alles da, aber es passt nicht zusammen."*

`E_POLICY_INVALID` (Policy-Wert oder -Kopie falsch) und `E_MAPPING_OVERLAP`
(mapping-intern) sind **eigene, bestehende** Codes — der Resolver erfindet sie nicht
neu, er löst sie an der Kompositions-Naht aus.

### Override- und Kollisions-Semantik

Ein Manifest darf Module **weglassen** oder **ersetzen** (`overrides`, Nutzungsweg 2 in
`jurisdiction-profil.md`) — bewusst nur ganze Module (`remove`/`replace`), **kein**
feldgranularer Patch (das unterliefe Modul-Versionierung und Determinismus). Einzelne
eigene Konten/Schlüssel legt die App **über** den aufgelösten Pack (F-CORE-007), nicht
im Manifest.

- **Kein stilles Überschreiben.** Zwei Module, die dieselbe Identität beitragen (gleiche
  Konto-`number`, gleicher `taxCode.code`, zwei `policy`-Module), sind **kein** „letzter
  gewinnt", sondern `E_PACK_INCOHERENT` (I6/I7). Die Absicht zu ersetzen wird **explizit**
  über `overrides` ausgedrückt, nie implizit über die Listenreihenfolge — das macht
  Komposition reviewbar und deterministisch.
- **Overrides greifen vor der Faltung** (Schritt 1). Nach Override-Anwendung ist die
  effektive Liste kollisionsfrei *gemeint*; bleibt eine Kollision, ist sie ein echter
  Fehler (`E_PACK_INCOHERENT`), kein „vergessenes Override". Ein `replace` auf ein nicht
  gelistetes Modul oder ein Doppel-Override auf dieselbe `ref` → `E_PACK_INCOHERENT`.

## Costing

`setReconciliationRules` · `setAllocationScheme` · `runCosting(period)` → Version (draft) · `releaseCosting(runId)` · Projektionen: `costAllocationSheet` (BAB), `reconciliationBridge` (Abstimmbrücke), `costObjectReport`

## Anwendungsschicht-Komposition (Teil der Spec!)

Der Standard-Buchungsfluss SF-02/03 („ein Aufruf") ist eine spezifizierte Komposition: `postVoucher(belegdaten, taxCode, …)` = `expandTax` → ggf. Pre-Posting-Hooks (später Budgeting) → `post` → ggf. OpenItem-Anlage. Jedes Package liefert diese Komposition mit — sie ist der Haupteinstiegspunkt für Apps und die CLI.

## Constraints / Invarianten (Politiksorte „Constraint", gesammelt)

Die Prädikate, die beim Schreiben gelten MÜSSEN — eine benannte Sammlung der Politiksorte *Constraint* (NF-5.3), damit sie nicht nur in Prüfreihenfolge und Fehlerkatalog verstreut sind:

- **Σ Soll = Σ Haben** (`E_ENTRY_UNBALANCED`) · **Belegpflicht** `voucherId` (`E_ENTRY_NO_VOUCHER`/`E_VOUCHER_UNKNOWN`) · **≥ 2 Positionen, Betrag > 0** (`E_ENTRY_TOO_FEW_LINES`/`E_ENTRY_INVALID_AMOUNT`)
- **Periode offen** (`E_PERIOD_CLOSED`/`E_PERIOD_UNKNOWN`); **Festschreibung unveränderbar** (`E_ENTRY_FINALIZED`); **Jahresabschluss-Guard** (`E_FISCALYEAR_UNFINALIZED_ENTRIES`)
- **Dimensions-Validierung** (Mechanik Kern + Pack-`dimensionRules`, `E_DIMENSION_INVALID`) · **Belegpflichtfelder** je Pack (z. B. USt-IdNr. bei igL)
- **Journalnummer-Lückenlosigkeit** je Geschäftsjahr (NF-6) · **EÜR nur bei Kalenderjahr** (`E_CASHBASIS_DEVIATING_FISCAL_YEAR`)

Constraints werden beim *Schreiben* erzwungen (NF-2.2), nicht beim Lesen geprüft. Zustandsverwaltende Operationen (`closePeriod`/`reopenPeriod`/`closeFiscalYear`) ändern, *ob* ein Constraint („Periode offen") gilt. Voller Politiksorten-Zensus: `40-domaenenmodell/jurisdiction-profil.md`.

## Prüfreihenfolge (deterministische Fehlercodes)

Verletzt eine Eingabe mehrere Invarianten, gewinnt der **erste** Fehler dieser Reihenfolge — alle Implementierungen MÜSSEN identisch melden:

1. **Struktur:** Positionszahl (`E_ENTRY_TOO_FEW_LINES`), Betragsformat/-vorzeichen (`E_ENTRY_INVALID_AMOUNT`)
2. **Referenzen:** Beleg (`E_ENTRY_NO_VOUCHER`), Konto unbekannt (`E_ACCOUNT_UNKNOWN`), Konto gesperrt (`E_ACCOUNT_LOCKED`), Dimension (`E_DIMENSION_INVALID`)
3. **Bilanzgleichung:** `E_ENTRY_UNBALANCED`
4. **Zeitlicher Kontext:** Periode unbekannt (`E_PERIOD_UNKNOWN`), geschlossen (`E_PERIOD_CLOSED`)

Es wird nur der erste Fehler gemeldet (kein Fehler-Sammeln im Kern — Mehrfachvalidierung für UI-Zwecke ist App-Sache).

Ergänzte Operation: `closeFiscalYear` (alle Perioden geschlossen → Jahr abgeschlossen; sonst `E_PERIOD_OUT_OF_ORDER`).

Dieselbe „erster Fehler gewinnt"-Disziplin gilt **innerhalb der Pack-Auflösung**, auf
einer eigenen Achse (siehe Abschnitt **Pack-Resolver**): Referenz-Existenz
(`E_PACK_UNRESOLVED_REF`) vor Kohärenz (`E_PACK_INCOHERENT`) vor Policy
(`E_POLICY_INVALID`). Diese Reihenfolge betrifft die **Komposition** eines Packs
(Manifest+Module), nicht die Buchungsvalidierung (1.–4. oben) — die beiden Achsen sind
unabhängig: Pack-Auflösung geschieht beim Mandanten-Aufbau, Buchungsvalidierung beim
`post`.

## Fehlerkatalog

Vollständige Liste mit Bedeutung, auslösender Invariante und Fixture-Referenz: `fehlerkatalog.md` (alle Codes abgedeckt). Neu mit der Pack-Komposition: `E_PACK_UNRESOLVED_REF`, `E_PACK_INCOHERENT`, `E_POLICY_INVALID` (Resolver, siehe **Pack-Resolver**) sowie `E_AMOUNT_SCALE_MISMATCH` (Betrag ≠ deklarierte `currencyScale` — Reader/Writer-Prüfung, kein Resolver-Fehler). Weitere Festlegungen: Storno eines Stornos ist zulässig (normale Buchung mit `reverses` auf die Stornobuchung); Überzahlung ist App-Muster (Verrechnungskonto/neuer OP) — der Kern weist nur Über-Zuordnung ab (`E_SETTLEMENT_EXCEEDS_ITEM`).

## Offene Punkte

- Paginierung/Streaming-Konvention für große Projektionsergebnisse.
- Berechtigungsmodell: bewusst NICHT in der API (App-Sache) — nur Audit-Identität (`actor`) wird bei Schreiboperationen durchgereicht.
- Idempotenz-Schlüssel für `post` (Netzwerk-Wiederholungen in verteilten Apps)?
