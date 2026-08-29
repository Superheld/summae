# Datenformat-Spezifikation v0.9 (Entwurf)

> Schema-Datei `$id` → **0.9** (geschlossenes `subtype`-Repertoire, bedingte Constraints).
> Anders als die vier Schritte davor ist dies **kein rein additiver**: `account.subtype` war ein
> freier String und ist jetzt eine Aufzählung, das Schema akzeptiert also **weniger** als vorher.
> Bestehende Bestände, die ausschließlich kanonische Subtypen tragen — was auf jedes ausgelieferte
> Pack zutrifft —, bleiben byte-identisch gültig; ein selbst erfundener Subtyp wird abgewiesen, und
> genau dafür ist der Schritt da (§ v0.9 unten).
>
> Zur Historie der additiven Schritte: 0.7 gab `partner` ein optionales `status`
> (`active`/`inactive`), 0.8 gibt jedem Audit-Satz `previousRecordHash` und `recordHash`; bestehende
> Bestände bleiben dabei gültig und werden von `auditTrailIntegrity` als *unchained* gemeldet,
> ausdrücklich nicht als gebrochen. **0.6** (E-B, Pack-Komposition) war ebenfalls kein rein
> additiver Schritt: `packPolicy` an Objekten mit `additionalProperties: false` (Profil,
> Pack-Manifest) und das gelockerte amount-Pattern änderten die vom Schema **akzeptierte Sprache**
> — ein 0.4-Validator lehnt neue Bestände ab. Bestehende Bestände blieben byte-identisch gültig
> (additives Feld mit DE-Default, amount-Pattern als echte Obermenge — siehe § packPolicy /
> Migration). Frühere additive v0.5-Felder (`streams`/`hashAlgorithm` im Export-Manifest,
> `side`/`includesNetIncome` am Mapping, `assetAccounts`) sind in 0.6 enthalten.
>
> **Jede Version hat unten ihren eigenen `## v0.x`-Abschnitt, und das ist seit 2026-08-28 geprüft**
> statt gepflegt — siehe § *Wer dieses Dokument gegen das Produkt hält*. Vorher stand 0.7 wochenlang
> nirgends hier, obwohl das Schema die Version trug und ausrollte: die normative Quelle beschrieb
> ein Format, das das Produkt hinter sich gelassen hatte (IMPL-037).

**Die Published Language des Ledger-Kontexts** — zugleich Kompatibilitätsvertrag aller Implementierungen (laufzeitübergreifend; erste: PHP ✅, dann Node, Python) und Grundlage des GoBD-Exports. Feldnamen kommen aus dem Glossar (EN-Spalte). Änderungen an diesem Dokument sind Context-Map-Änderungen → Entscheidungslog.

## Grundsätze

1. **Serialisierung: JSON** (UTF-8). Kanonische Form für Hashes/Signaturen: RFC 8785 (JCS).
2. **Beträge:** String-Dezimal mit Punkt, ohne Tausendertrennung (`"1234.56"`); Währung als ISO-4217-Code. Die **Nachkommastellen (Scale) sind eine Eigenschaft der Währung**, kein globaler Festwert (EUR 2, JPY 0, BHD 3 — DE-Pack arbeitet in EUR; siehe `determinismus.md` und Schichtenzuordnung in `jurisdiction-profil.md`). Nie JSON-Number (Float-Verbot). **Das Schema-Pattern ist reine Syntaxprüfung** (`^-?\d+(\.\d{1,4})?$` — 0 bis 4 Nachkommastellen, Scale-0-Währungen ohne Dezimalpunkt: `"1234"`, nie `"1234."`/`"1234.0"`); die **exakte** Stellenzahl ist semantisch und MUSS gleich `packPolicy.currencyScale` sein (inkl. Pflicht-Nullen: `"1.500"` bei Scale 3, nie `"1.5"`). Verstoß → `E_AMOUNT_SCALE_MISMATCH`.
3. **IDs: UUIDv7** (zeitlich sortierbar, implementierungsunabhängig erzeugbar).
4. **Daten/Zeiten:** ISO 8601; Datum `"2026-06-07"`, Zeitstempel mit Zone `"2026-06-07T14:30:00+02:00"`.
5. **Versionierung:** jede Datei/jeder Datensatzstrom trägt `formatVersion` (semver). Migrationspfade n→n+1 sind Teil künftiger Spec-Versionen.
6. **Append-only-freundlich:** Journal als fortlaufende, unveränderliche Einträge; alles Abgeleitete ist nicht Teil des Formats (Projektionen werden berechnet, nie ausgetauscht).
7. **Selbstbeschreibend für GoBD Z3:** Feldkatalog (Name, Typ, Bedeutung) ist Bestandteil dieser Spec → Export nach Beschreibungsstandard ist eine Abbildung, keine Erfindung. **Der Katalog ist VOLLSTÄNDIG und beschreibt genau die Ströme, die der Export trägt** — seit 2026-08-28, vorher beschrieb er 4 der 8 Kontofelder, 2 der 12 Belegfelder, 4 der 9 Audit-Felder und den `partners`-Strom gar nicht (IMPL-038). Ein Datensatz, der sich selbst unvollständig beschreibt, ist genau der Fall, gegen den dieser Punkt geschrieben ist; geprüft von `FieldCatalogCompletenessTest`/`field-catalog-completeness.test.ts` in beiden Sprachen, in beide Richtungen. **Seit 2026-08-28 macht summae diese Abbildung selbst** (`gdpduExport`, F-IO-012): Flachdateien + `index.xml` nach Beschreibungsstandard **1.6 (01.03.2019)**, DTD `gdpdu-01-03-2019.dtd`. Der Satz stimmte immer — er wurde nur jahrelang als Begründung gelesen, die Abbildung *nicht* zu liefern.

## Kernobjekte

### `journalEntry` (Buchung)

```json
{
  "id": "0190a1b2-…",
  "sequenceNumber": 142,
  "status": "finalized",
  "entryDate": "2026-01-15",
  "voucherDate": "2025-12-11",
  "recordedAt": "2026-01-15T09:12:00+01:00",
  "periodRef": { "fiscalYear": 2026, "period": 1 },
  "voucherId": "0190a0ff-…",
  "text": "Zahlungseingang AR 2025-042",
  "lines": [
    { "accountId": "…", "side": "debit",  "money": { "amount": "1190.00", "currency": "EUR" },
      "dimensions": [ { "type": "costCenter", "code": "100" } ], "taxTag": null },
    { "accountId": "…", "side": "credit", "money": { "amount": "1190.00", "currency": "EUR" },
      "dimensions": [], "taxTag": null }
  ],
  "reverses": null,
  "reversedBy": null,
  "settles": [ { "openItemId": "…", "money": { "amount": "1190.00", "currency": "EUR" } } ]
}
```

Invarianten im Format (von jedem Reader prüfbar): Σ debit = Σ credit (gilt auch für negative Beträge); ≥ 2 lines; `voucherId` gesetzt; `sequenceNumber` lückenlos **je Geschäftsjahr** (Entscheidung 2026-06-07, DATEV-Praxis); `status` ∈ {`entered`, `finalized`}; **Beträge > 0 — Ausnahme: bei `reverses` ≠ null sind negative Beträge zulässig und gefordert** (Generalumkehr: gleiche Konten, gleiche Seiten, negierte Beträge des Originals — Verkehrszahlen bleiben sauber, v0.3/M4).

### `account` (Konto)

`id`, `number` (String — führende Nullen!), `name`, `type` (`asset|liability|equity|expense|revenue` + `subtype`: `bank|ar|ap|tax_in|tax_out|fixed_asset|…`), `status` (`active|locked`), `validFrom`/`validTo?`, `mappings` (Verweise in Regelmodul-Mappings, z. B. Bilanzposition, EÜR-Zeile — optional, Modulinhalt).

### `fiscalYear` / `period`

`fiscalYear`: `id`, `year`, `start`, `end`, `status`. Enthält `periods[]`: `{ "period": 1..n, "start", "end", "status": "open|closed" }` — lückenlos, überlappungsfrei. **`year` = Kalenderjahr des Endes** — dies ist eine **DE-Pack-Konvention** (steuerliche Zuordnung § 4a EStG), kein Naturgesetz des Kerns; andere Packs können eine andere Kalenderkonvention tragen (Schichtenzuordnung: `jurisdiction-profil.md`). Relevant bei abweichendem GJ (v0.3/M5).

### `voucher` (Beleg)

`id`, `voucherNumber`, `voucherDate`, `issuer?`, `totalMoney?`, `documents[]` (`{ "uri", "sha256", "mimeType" }` — Datei liegt bei der App), `due?`, `recurring?`, `economicYear?`, `supplierTaxationMethod?` (`accrual|cash` — F-TAX-007, 2028).

### `openItem` (Offener Posten)

`id`, `kind` (`receivable|payable`), `originEntryId` + `originLineIndex`, `money`, `partnerId?`,
`settlements[]` (`{ "entryId", "money", "settledAt", "cause", "difference" }`). Formatinvariante:
Σ settlements ≤ money.

`remaining` und `status` stehen zwar in der abgelegten Form, sind aber **abgeleitet** (aus `money`
minus Ausgleichen) und nie Wahrheit — dieselbe Regel wie bei Salden. `status`:
`open | partially_settled | settled | cancelled`.

`settlements[].cause` (v0.6/IMPL-008): `payment` (Voreinstellung, wenn das Feld fehlt) oder
`cancellation`. `cancellation` entsteht **nur** durch `reverse` und heißt: der Posten ist
erledigt, weil die Ursprungsbuchung storniert wurde — es ist **kein Geldfluss**. Ohne diese
Unterscheidung wäre ein Storno von einer Zahlung nicht zu trennen, und bei Ist-Versteuerung würde
die USt-VA Steuer erklären, für die nie Geld geflossen ist. `settlements[].difference` bleibt wie
gehabt (`null` oder `{ "money", "kind" }`, Skonto/Forderungsverlust/Kleindifferenz) — es beschreibt
den *Betragsunterschied* eines Ausgleichs, `cause` dagegen seinen *Anlass*.

### `dimensionType` / Stammdaten

`dimensionType`: `id`, `code` (`costCenter`, `costObject`, `product`, frei erweiterbar), `name`. Dimensionswerte: `id`, `typeCode`, `code`, `name`, `validFrom`/`validTo?`.

### Mandant & Export-Manifest

Austauscheinheit ist der **Mandanten-Datenbestand**: ein **Export-Manifest** (`formatVersion`, `tenantId`, `tenantName`, `baseCurrency`, `exportedAt`, `contentHashes`) + Objektströme (JSON Lines je Objekttyp: `journal.jsonl`, `accounts.jsonl`, …). JSON Lines, weil Journale groß werden und Streaming-Verarbeitung (auch fürs Z3-Export-Tooling) trivial sein muss.

## Was bewusst NICHT im Format ist

Salden, Hauptbuch, Bilanzen, EÜR (Projektionen — berechenbar); Steuerschlüssel-*Regeln* und Mappings (Regelmodul-Daten mit eigener Versionierung, referenziert über `taxTag`/`mappings`); App-Daten (Nutzer, Workflows, Belegdateien). Der **`ResolvedPack`** (Auflösungs-*Ergebnis* eines Pack-Manifests) ist ebenfalls **nicht** Teil des Austauschformats — er ist exakt die Struktur, die heute hand-gereicht in den Mandanten geht (Konten + `taxCodes` + Mappings + `assetAccounts` + `packPolicy`), und wird beim Mandanten-Aufbau **einmal** berechnet, nicht ausgetauscht (wie Salden eine Projektion sind, ist der `ResolvedPack` eine Auflösung). Ausgetauscht/abgelegt werden nur **Module** und **Pack-Manifeste** als Quelle.

## v0.2 — Ergänzungen

### `taxTag` (final)

Buchungspositionen mit Steuerbezug tragen die **angewandte** Regel, nicht nur den Schlüssel — das Journal bleibt selbsterklärend, auch wenn das Regelmodul später neue Versionen bekommt (Neuberechnungs-Garantie F-CORE-016):

```json
"taxTag": {
  "code": "USt19",
  "appliedVersion": "2024-01-01",
  "reportingKey": "81",
  "baseMoney": { "amount": "1000.00", "currency": "EUR" }
}
```

`appliedVersion` = `validFrom` der beim Buchen gültigen Regelversion. `baseMoney` = Bemessungsgrundlage (für VA-Kennzahlen ohne Rückrechnung). Steuer- und Basispositionen desselben Sachverhalts tragen denselben Tag.

**Generalumkehr und `baseMoney` (v0.5/SPEC-008):** Eine Stornobuchung (`reverses ≠ null`) trägt negierte Positionsbeträge (siehe Buchungs-Invarianten); der `taxTag.baseMoney` wird dabei **unverändert kopiert**, nicht negiert. Die VA-Projektion negiert die Bemessungsgrundlage dann anhand des Vorzeichens der Steuerposition. Ebenso tragen § 17-Korrekturzeilen (Skonto, Ausfall) und Gutschriften ein **negatives `baseMoney`** (Minderung). So bleibt die Kennzahlen-Summe korrekt, ohne dass die Projektion den Storno-Bezug auflösen muss.

### `profile` (Regelmodul-Bündel, schaltet SF-01 frei)

```json
{
  "id": "de-freiberufler-euer", "name": "Freiberufler EÜR (Deutschland)",
  "version": "2026.1",
  "chartOfAccounts": "<coa-ref>", "taxCodes": ["USt19", "USt7", "VSt19", "VSt7"],
  "mappings": ["de-anlage-euer-2026"],
  "defaults": { "taxationMethod": "cash", "smallBusiness": false, "vatPeriod": "quarterly" }
}
```

Profile sind versionierte Regelmodul-Daten; `createTenant(profile)` kopiert nichts, sondern referenziert (Updates des Profils wirken nicht rückwirkend — der Mandant pinnt die Version, Upgrade ist explizit).

### `packPolicy` (Pack-Policy-Parameter, v0.6)

Optionales Objekt **am `profile`** (Strom `profiles.jsonl`). Hebt die bisher nur in
`determinismus.md` als DE-Default dokumentierten Politik*werte* (Rundung, Steuer-
Granularität, Währungsskala) ins Format — adressierbar je Pack, **ohne** Mechanik-Änderung
(„Mechanik global, Politik im Pack", `jurisdiction-profil.md`). Erbt das Profil-Pinning:
eine Policy-Änderung ist eine neue Profilversion + expliziter Mandanten-Upgrade, nie ein
stiller Effekt.

```json
"packPolicy": {
  "roundingMode": "halfUpAwayFromZero",
  "taxRoundingGranularity": "perVoucher",
  "currencyScale": 2
}
```

| Feld | Typ | Default (= DE) | Bedeutung |
|---|---|---|---|
| `roundingMode` | Enum `halfUpAwayFromZero` \| `halfEven` | `halfUpAwayFromZero` | Modus **jeder** Rundung auf Währungsskala (`expandTax`, `allocate`-Teilrundung *vor* Largest-Remainder, anteilige Steuer, AfA-Raten, Projektions-Endwerte). Wirkt **nicht** auf ungerundete Zwischenwerte (≥ 6 Stellen) noch auf die DE-VA-Volle-Euro-Abrundung (Mapping-Konvention). |
| `taxRoundingGranularity` | Enum `perVoucher` \| `perLine` | `perVoucher` | Nur `expandTax`: `perVoucher` = eine Steuerzeile je Steuersatz, `baseMoney` = Satz-Summe; `perLine` = eine Steuerzeile je Basisposition. Projektionen lesen `taxTag`, rechnen nicht neu — Granularität für sie irrelevant. |
| `currencyScale` | Integer 0–4 | `2` (EUR) | Exakte Stellenzahl aller `money.amount` + Zielskala jeder Endwert-Rundung. Für ISO-Währungen MUSS der Wert dem ISO-4217-Exponenten entsprechen; für nicht-ISO-Codes (Test-Pack) ist die Deklaration die einzige Quelle. |

Alle drei Felder optional; **jeder Default = heutiges DE-Verhalten** → ein fehlendes
`packPolicy` ist semantisch identisch zu `{halfUpAwayFromZero, perVoucher, 2}`. Das ist
der Rückwärtskompatibilitäts-Hebel: jeder Altbestand wurde mit genau diesen Werten erzeugt
(der Default ist bewusst DE-gefärbt — dokumentierte Abwärtskompatibilität, kein Leak: DE
ist das erste Pack, alle Altbestände sind DE-Bestände).

**Fehlerfälle:** unbekannter Enum-Wert, `currencyScale` nicht ganzzahlig / außerhalb 0–4,
ISO-Exponent-Widerspruch, **oder Skalenänderung auf bestehendem Mandanten** (Re-Skalierung
historischer Beträge bräche Byte-Identität + Append-only) → `E_POLICY_INVALID`. Betrag im
Bestand mit abweichender Stellenzahl → `E_AMOUNT_SCALE_MISMATCH`.

### `mapping` (Gliederungen, schaltet SF-09/10 frei)

Ein Mapping ordnet Konten Positionen zu — gleiche Struktur für Bilanz, GuV, EÜR-Zeilen und VA-Kennzahlen:

```json
{
  "id": "de-hgb-bilanz-266", "kind": "balance-sheet", "version": "2026.1",
  "positions": [
    { "key": "A", "label": "Aktiva", "children": [
      { "key": "A.II", "label": "Umlaufvermögen", "accounts": [ { "from": "1000", "to": "1499" } ] }
    ] }
  ]
}
```

Selektoren: Nummernbereiche (`from`/`to`, String-Codepoint-Vergleich) und Einzelkonten (`numbers`). Jedes Konto MUSS in genau eine Position fallen (Validierungsregel beim Mapping-Import: `E_MAPPING_OVERLAP`, `E_MAPPING_GAP` als Warnung mit Auffangposition).

Bei `kind: balance-sheet` trägt **jeder Wurzelknoten ein `side`** (`assets` | `liabilitiesAndEquity`) — die Seitenzuordnung ist explizit, nicht aus der Reihenfolge abgeleitet (v0.5/SPEC-007). `assets`-Positionen zeigen Soll−Haben, `liabilitiesAndEquity`-Positionen Haben−Soll. `includesNetIncome: true` an einer Passivposition addiert das noch nicht verwendete Ergebnis.

### Assets-/Costing-Ströme

`assets.jsonl` (Anlagegut inkl. AfA-Plan und Lebenslauf mit Journal-Refs), `assetPools.jsonl`, `allocationSchemes.jsonl`, `reconciliationRules.jsonl`, `costingRuns.jsonl` (inkl. Status/Version; nur released Läufe sind austauschpflichtig), `dimensionTypes.jsonl`, `dimensionValues.jsonl`, `taxProfiles.jsonl`, `profiles.jsonl`, `mappings.jsonl`.

### `auditLog.jsonl` (v0.3 — schließt Review-Befund G3)

GoBD: Der ursprüngliche Inhalt muss über die gesamte Aufbewahrungsdauer feststellbar bleiben — auch nach Systemwechsel. Daher ist der Audit-Trail Formatbestandteil:

```json
{ "id": "0190…", "at": "2026-06-07T14:31:00+02:00", "actor": "bruce",
  "objectType": "journalEntry", "objectId": "0190…", "action": "corrected",
  "changes": { "text": { "from": "Bürobedarf", "to": "Bürobedarf Januar" } } }
```

`action` ∈ {`corrected`, `created`, `locked`, `profileChanged`, …} je Objekttyp; `changes` = flacher Vorher/Nachher-Diff nur der geänderten Felder. Audit-Einträge sind append-only und werden bei Migration vollständig übernommen (SF-15). Domain Events bleiben Benachrichtigungen — sie sind *nicht* Teil des Formats.

### EÜR-Mapping: `includeNonCash` (v0.3, Regel R7)

`cash-basis-categories`-Mappings können Positionen mit `"includeNonCash": true` markieren: Buchungen auf diesen Konten zählen in der EÜR **ohne** Zahlungsfluss im Buchungsjahr (AfA-Aufwand, unentgeltliche Wertabgaben als Einnahme). Formalisiert die bisherige AfA-Sonderbehandlung aus R4.

### Hash-Entscheidung (Tamper-Evidence)

**Manifest-Ebene, nicht Buchungs-Kette:** SHA-256 je Strom (zeilenweise, RFC-8785-kanonisiert) im Manifest. Eine kryptografische Hash-*Kette* je Buchung ist bewusst NICHT Teil von v1 — GoBD verlangt Unveränderbarkeit + Protokollierung, keine Blockchain; eine Kette würde jeden Persistenz-Adapter komplizieren und bei legitimen Migrationen brechen. Tür offen: optionales Feld `previousEntryHash` ist reserviert, nicht belegt.

### Export-Manifest (präzisiert v0.5/SPEC-005)

Das Manifest führt `streams` (Liste der enthaltenen Objektströme) und `hashAlgorithm` (`sha256`) als Pflichtfelder. **Der `auditLog`-Strom ist im Export immer enthalten** — auch wenn nur `created`/`finalized`-Einträge existieren (GoBD: lückenlose Historie über den Systemwechsel). `formatVersion` trägt stets die aktuelle Spec-Version.

### Maschinenlesbares Schema

`schema/format.schema.json` (JSON Schema draft 2020-12, `$defs` je Objekttyp) — normativ ist dieses Dokument, das Schema ist die prüfbare Ableitung; CI der Implementierungen validiert Exporte dagegen.

### Reservierte Felder (definiert, in v1 nicht belegt)

`previousEntryHash` (Buchung — Hash-Kette), `valuationArea` (Position — parallele Bewertungsbereiche HB/StB/IFRS, v1 = ein Bereich), `exchangeRate`/`baseMoney` an der Position (Fremdwährung, v2). Reader MÜSSEN diese Felder ignorieren, Writer DÜRFEN sie in v0.x nicht belegen.

### DATEV-Buchungsstapel-Export (F-IO-005)

Exportprofil (kein Mapping-Sonderfall, eigene Projektion `datevExport`): Felder Umsatz (Betrag, Soll/Haben-Kennzeichen), Konto, Gegenkonto, BU-Schlüssel (aus der Alias-Spalte des Steuerschlüssel-Regelmoduls — eigene Codes bleiben führend, DATEV-BU ist Mapping), Belegdatum, Belegfeld 1 (voucherNumber), Buchungstext, Festschreibungskennzeichen. Nur einfache Soll/Haben-Paare sind direkt abbildbar; zusammengesetzte Buchungen werden in DATEV-konforme Teilzeilen aufgelöst (Aufteilungsregel deterministisch: Reihenfolge der Positionen). Exakte EXTF-Header-Version: Implementierungsdetail, bei JOB-011/Phase 4 gegen aktuelle DATEV-Doku verifizieren.

## v0.3 — Audit-Trail, EÜR-Schalter, Generalumkehr

Der Schritt ist **additiv** und an drei Stellen oben ausformuliert, statt hier wiederholt zu werden:
`auditLog.jsonl` als eigener Strom (§ oben, Review-Befund G3), `includeNonCash` am EÜR-Mapping
(§ oben, Regel R7) und die **Generalumkehr** mit negierten Positionsbeträgen (§ `journalEntry`,
Invarianten). Dazu das abweichende Geschäftsjahr (§ `fiscalYear`).

## v0.4 — Ergänzungen (Buchhalter- + StB-Review)

### Leistungsdatum (Buchhalter-G1 — steuerlich entscheidend)

`voucher` erhält `serviceDate` *oder* `servicePeriod {from, to}` (Leistungszeitraum). **Die Steuerregelversion wird nach dem Leistungsdatum gewählt** (Fallback: voucherDate), nicht nach dem Belegdatum — § 27 Abs. 1 UStG, relevant bei jedem Steuersatzwechsel. VA-Zuordnung bei Soll-Versteuerung: Periode des Leistungsdatums (Fallback voucherDate); bei Ist-Versteuerung unverändert die Zahlung.

> **Ausnahme Generalumkehr/Storno (vorläufig, SPEC-011, 2026-06-14).** Eine *reversierende* Buchung (`reverses ≠ null`) erbt die `voucherId` — und damit das Leistungsdatum — des Originals. Für die VA-Zuordnung zählt bei ihr dennoch das **eigene Buchungsdatum**, nicht das geerbte Leistungsdatum: die Korrektur gehört in den VA-Zeitraum, in dem sie eintritt (§ 17 Abs. 1 S. 7 UStG), nicht rückwirkend in die Original-Periode (sonst saldiert sich das Storno dort weg und wird unsichtbar — Fixture `vat-return-reversal`). **Status vorläufig:** Ob diese pauschale Regel auch die Berichtigung reiner Fehlbuchungen / § 14c-Fälle korrekt trifft, ist fachlich offen (RQ-1, `40-domaenenmodell/offene-fragen.md`).

### `partner` (Geschäftspartner — Buchhalter-G2 + StB-1/2; bewusst schlank, kein CRM)

```json
{ "id": "0190…", "name": "Muster GmbH", "kind": "customer|supplier|both",
  "vatId": "DE123456789", "address": { "…": "optional" },
  "paymentTermsDays": 14, "accountIds": ["<ar-Konto>"] }
```

Strom `partners.jsonl`; optionale `partnerId` an `voucher` und `openItem` (vom Beleg geerbt). Deckt: OP-Liste je Partner, igL-Nachweis (USt-IdNr. GoBD-fest am Vorgang), ZM-Grundlage, DATEV-Stammdaten-Export.

### Kanonische `subtype`-Liste (Buchhalter-M: Fixtures nutzten undefinierte Werte)

`bank`, `cash` (Kasse), `transit` (Geldtransit), `ar`, `ap`, `tax_in`, `tax_out`, `fixed_asset`, `opening_balance` (Saldovorträge), `private` (Privatkonten), `result_allocation` (Ergebnisverwendung) — erweiterbar, aber Reader MÜSSEN diese kennen. **Geldkonto := {bank, cash}; transit ist EÜR-neutral** (Geldtransit/PSP-Umbuchungen).

### Belegart

`voucher.kind` (optional): `invoice_out`, `invoice_in`, `credit_note_out`, `credit_note_in`, `payment`, `payroll`, `internal`, `opening` — Auswertungs-/Exporthilfe, keine Kernlogik.

### Offene-Posten-Entstehung und Gutschriften (verbindlich)

Ein OP entsteht aus jeder Buchungszeile auf einem ar/ap-Konto, deren Seite der Natur des Kontos entspricht (ar + debit → `receivable`; ap + credit → `payable`). Zeilen auf der *Gegenseite* (z. B. Kundengutschrift: credit auf ar) erzeugen **keinen** neuen OP — sie sind Ausgleichskandidaten und werden per `settle` bestehenden OPs zugeordnet.

### Ergebnisverwendung (Buchhalter-G6)

Der Gewinnverwendungsbeschluss ist ein Geschäftsvorfall → normale Buchung: `result_allocation`-Konto (Soll) an Gewinnvortrag/Ausschüttungs-Verbindlichkeit (Haben). `balanceSheet`-Position mit `includesNetIncome: true` zeigt **kumulierte Jahresergebnisse + Saldo der result_allocation-Konten** = das noch nicht verwendete Ergebnis; Gewinnvortrag ist ein normales EK-Konto. Kein Abschlusszyklus nötig — konsistent mit v0.3.

### AfA-Methoden (StB-4, Rechtsstand 06/2026 verifiziert)

Degressive AfA ist **aktiv** (Investitionsbooster: Anschaffung 01.07.2025–31.12.2027, max. 2,5× linear, Deckel 30 %/Jahr) → AfA-Plan unterstützt in v1: `linear`, `declining` (Satz aus Regelmodul mit Anschaffungszeitraum-Gültigkeit) und den **Methodenwechsel declining → linear** (Umschaltpunkt: sobald linearer Restwert-Satz höher; Kern-Mechanik). § 7g: Sonder-AfA (bis 40 %) und AK-Minderung bei IAB-Inanspruchnahme sind Plan-Mechanik; der IAB selbst ist außerbilanziell → Steuerermittlung, App/StB-Sache (dokumentierte Abgrenzung).

### DATEV beide Richtungen (StB-1 + Buchhalter-M)

`datevExport` zusätzlich mit `kind: entries | accounts | partners` (Buchungsstapel, Kontenbeschriftungen, Geschäftspartner-Stammdaten) — **gebaut**.

Der Rückweg `importDatevBatch` (Stapel vom Steuerberater → Buchungen) ist als Fähigkeit spezifiziert und **nicht gebaut**; seit 2026-08-28 ausdrücklich zurückgestellt statt offen geführt (F-IO-008, IMPL-042). Der Blocker sitzt nicht im Format, sondern in den Daten: die Rückrichtung braucht BU-Schlüssel → `taxCode`, und `datevBu` bildet nur vorwärts ab. Im `de`-Pack tragen fünf von zehn Steuercodes gar keinen `datevBu`, und `USt19`/`USt19WA` tragen beide die `3` — die Umkehrung ist weder total noch eindeutig. Ein Import müsste raten, und geraten würde die Steuer. Ein injektiver Rückabbildungs-Block im Pack und ein echter Buchungsstapel zur Formatverifikation sind die Voraussetzungen; bis dahin ist der Rückweg App-Sache, wie bei CAMT und XRechnung.

## v0.5 — Export-Manifest, Bilanzseiten, Bemessungsgrundlage beim Storno

Additiv, aus den Befunden SPEC-005/007/008: `streams` und `hashAlgorithm` im Export-Manifest
(§ oben), `side` an jedem Wurzelknoten eines `balance-sheet`-Mappings und `includesNetIncome`
(§ `mapping`), `assetAccounts` im Regelmodul, sowie die Festlegung, dass `taxTag.baseMoney` bei
einer Stornobuchung **unverändert kopiert** und nicht negiert wird (§ `taxTag`).

## v0.6 — Komponierbare Packs (Modul / Pack-Manifest)

Ein Pack ist kein Monolith, sondern eine Komposition adressierbarer **Module**, gebündelt
in einem **Pack-Manifest** (`jurisdiction-profil.md`). Das ist eine **Verpackungs- und
Auflösungsschicht** vor den heute schon bekannten Regelmodul-Daten (`profile`, `mapping`,
`account`, `assetAccounts`, `packPolicy`) — **keine neue Engine-Fähigkeit**. Die
Auflösungs-Semantik (Resolver, referentielle Integrität, fail-loud) steht in `api.md`;
die Fehlercodes in `fehlerkatalog.md`.

> **Begriff:** „Pack-Manifest" (`packs/<id>.json`) ist **nicht** das **Export-Manifest**
> (Mandanten-Datenbestand, § „Mandant & Export-Manifest"). Zwei verschiedene Objekte.

### `module` (kleinste adressierbare Einheit, Heimat `modules/<kind>/<id>.json`)

Granularität: **ein kohärenter Regelsatz** (ein Kontenrahmen, ein Steuerschlüssel-Satz,
ein Mapping, ein AfA-Satz, eine Policy) — nicht atomar.

```json
{
  "formatVersion": "0.6",
  "id": "de-ust-2026",
  "kind": "tax",
  "version": "2026.1",
  "name": "USt Deutschland 2026",
  "contributes": ["taxCodes"],
  "dependsOn": [ { "kind": "accounts", "id": "de-konten" } ],
  "data": { "...": "kind-spezifisch" }
}
```

| Feld | Typ | Bedeutung |
|---|---|---|
| `formatVersion` | semver | `0.6`. |
| `id` | String, eindeutig **je `kind`** | Adresse für `dependsOn` / Pack-Manifest-Referenzen. |
| `kind` | Enum `accounts` \| `tax` \| `mapping` \| `depreciation` \| `policy` \| `assetAccounts` \| `productionCost` \| `constraint` \| `resultAppropriation` \| `legalForms` \| `inventory` \| `provisions` \| `deferrals` \| `inputTaxAdjustment` | Bestandteils-**Typ**; bestimmt Struktur von `data` und zulässige `contributes`. Deckt sich mit `modules/<kind>/`. Export-Adapter (DATEV/SAF-T) sind **kein** `kind` (dünner Code je Format). **Maßgeblich ist `testing/testsuite/schema/format.schema.json`** — diese Zeile ist die Erzählung dazu und war bis 2026-08-27 vier Sorten im Rückstand. **Am 2026-08-29 stand sie erneut vier Sorten im Rückstand** (`inventory`, `provisions`, `deferrals`, `inputTaxAdjustment`), dieselbe Zeile, dieselbe Zahl — der Gate-Test aus IMPL-037 prüfte Version, Abschnitte und `$defs`, aber nicht dieses Enum. Seither tut er es (Punkt 4 in § *Wer dieses Dokument gegen das Produkt hält*). |
| `version` | String | Modul-Version, pinbar; Update = neue Version, nie still (erbt Profil-Pinning, v0.2). |
| `name` | String | Menschenlesbar, nicht referenzwirksam. |
| `contributes` | Array (Werte: `accounts` \| `taxCodes` \| `mappings` \| `assetAccounts` \| `policy` \| `depreciation` \| `productionCost` \| `constraint` \| `resultAppropriation` \| `legalForms` \| `inventory` \| `provisions` \| `deferrals` \| `inputTaxAdjustment`) | Welche `ResolvedPack`-Felder das Modul füllt — Basis der Integritätsprüfung. I. d. R. eine Sorte, passend zum `kind`. |
| `dependsOn` | Array `{kind, id, version?}` | Module, deren Beiträge dieses referenziert (Steuer→Konten). Auflösungsreihenfolge + Integritätsbasis. Fehlt `version`, gilt die im Pack-Manifest gewählte. |
| `data` | Objekt, je `kind` | Die Regelmodul-Daten — **wortgleich** zu den heutigen Strukturen dieser Spec. |

`additionalProperties: false` auf Modul-Ebene. Unbekanntes `kind` → Reader scheitert laut
(`E_PACK_INCOHERENT`), nicht still ignorieren.

**`kind` → Politiksorte (eindeutig).** Jedes Modul bedient *genau eine* Politiksorte, bestimmt durch `kind`:
`tax`/`depreciation`/`assetAccounts`/`resultAppropriation`/`productionCost`/`inventory`/`provisions`/`deferrals`/`inputTaxAdjustment` → **Expansion** ·
`mapping`/`legalForms` → **Projektion** · `accounts` → **Substrat** · `constraint` → **Constraint**
(seit 2026-08-23, bislang genau ein Prädikat: `dimensionRules`) · `policy` → **Parameter (querliegend)**.

`legalForms` ist der erste Modultyp **ohne** `dependsOn`: er nennt keine Konten. Was er trägt, ist
reines Rechtsformwissen — welche Formen die Jurisdiktion kennt und was jede an Beschluss und Frist
schuldet (F-CORE-039) — und der Kern rechnet daraus nur Geschäftsjahresende + n Monate auf den
Monatsletzten. Jede Zahl und jede Fundstelle bleibt im Modul.

**Heimat — self-contained.** Module liegen im Ordner *ihres* Packs (`pack-library/<pack>/<kind>/<id>.json`,
z. B. `de-pack/tax/de-ust.json`), **nicht** in einem über Packs geteilten `modules/`. **Packs bauen nicht
aufeinander auf**; Modul-IDs sind je Pack eindeutig (Entscheidung 2026-06-21). Freie À-la-carte-Komposition
bleibt möglich, die ausgelieferten Packs sind aber abgeschlossene Bündel.

**`data` je `kind` (jede Form ist eine bereits in dieser Spec / den Modellen existierende
Struktur, nur in den Modul-Umschlag gelegt — keine erfundenen Felder):**

- `accounts` → `data.accounts[]` = `account`-Objekte (§ `account`).
- `tax` → `data.taxCodes[]` = `TaxCode` mit versionierten Regeln (`tax-modell.md` § 1).
  `TaxCode` = `{ code, versions[], datevBu? }` (`datevBu` = realer DATEV-BU-Alias,
  optional). Eine Version trägt **genau die Engine-Felder** (`TaxCodeVersion`):
  `validFrom`, `validTo` (nullbar), `rate`, `taxAccount`, `reportingKey` (nullbar),
  `mechanism` (Default `standard`), `inputTaxAccount`, `inputReportingKey`,
  `baseReportingKey` (alle nullbar). **`mechanism` ist ein OFFENER String, kein
  geschlossenes Enum** — real belegt: `standard`, `reverse_charge`,
  `intra_community_supply` (`TaxCodeRegistry.php` validiert nicht gegen ein Enum);
  bekannte Werte dokumentieren, weitere zulassen. `reverse_charge` nutzt zusätzlich
  `inputTaxAccount`/`inputReportingKey`/`baseReportingKey`; `intra_community_supply`
  nutzt `taxAccount`. **Kein** `side`-Feld — die Seite folgt aus `mechanism`.
  `taxAccount` (und `inputTaxAccount`) referenzieren ein Konto **per `number`** im via
  `dependsOn` gewählten Kontenrahmen.
  Seit v0.15.1 zusätzlich **`taxBase`** (F-TAX-010), die *zweite Naht* der Steuer-Expansion:
  `net` (Default — der übergebene Betrag IST die Bemessungsgrundlage, `Steuer = Betrag × Satz / 100`)
  oder `inclusive` (der übergebene Betrag ist der **Brutto**, Steuer bereits enthalten:
  `Steuer = Betrag × Satz / (100 + Satz)`, Basis ist der Rest — **und die Nettozeile wandert mit**,
  denn genau diese Aufteilung kann der Aufrufer nicht selbst leisten). Gerundet wird **einmal**, auf
  die Steuer; die Basis entsteht durch Subtraktion, weil in einem Brutto-Regime der Brutto die
  Tatsache ist und die Aufteilung die Rechnung. Bei mehreren Zeilen eines Codes wird die
  Gruppenbasis per **Largest Remainder** auf die Zeilen verteilt statt je Zeile neu gerechnet.
  Anders als `mechanism` ist `taxBase` ein **geschlossenes Enum**: ein unbekannter Wert wird mit
  `E_TAXCODE_INVALID` abgelehnt, nicht auf `net` zurückgefallen — eine verschriebene Basis wäre eine
  falsche Zahl in den Büchern, kein fehlendes Feature.
  **Was diese Naht bewusst nicht erreicht:** zusammengesetzte Basen (PST auf GST-inklusiver Basis)
  brauchen das Ergebnis eines anderen Codes und damit eine Reihenfolge, die diese Funktion nicht
  sieht; Besteuerung bei Zahlung ist eine Zeit-, keine Basisfrage; Margenbesteuerung braucht den
  Einkaufspreis, der nicht in der Buchung steht.
- `mapping` → `data.mapping` = ein `mapping`-Objekt (§ `mapping`; fachliches `kind` ∈
  `balance-sheet|cash-basis-categories|income-statement`, in `data.mapping.kind`).
  Die USt-VA ist **mapping-frei** (Kennzahl = `taxCodeVersion.reportingKey`); der im
  Schema (`format.schema.json`) vorhandene `vat-report`-Kind ist für die VA **unbenutzt**
  (reserviert für die spätere USt-**Jahres**erklärung, siehe „Offene Punkte"). `side` nur am
  `balance-sheet`-Wurzelknoten, Selektoren `{from,to}`|`{numbers}`.
- `depreciation` → AfA-Tabellen/-Sätze/GWG-Grenze (`assets-modell.md` v0.4); reine Daten,
  Mechanik (linear/declining/Methodenwechsel) ist Kern.
- `assetAccounts` → `data.default` = die **fünf** Bewegungskonten
  (`acquisitionCounterAccount`, `depreciationExpenseAccount`, `gwgExpenseAccount`,
  `disposalProceedsAccount`, `disposalLossAccount`) per `number`, optional `perClass`.
- `policy` → `data.packPolicy` = die drei Policy-Felder (§ `packPolicy`).
- `inventory` → `data.categories[]` = `{account, changeAccount, label}` je Vorratsart. Der Kern
  rechnet und bucht die **Differenz** zum Buchwert; dass Deutschland dabei zwei verschiedene
  GuV-Zeilen trifft (Bestandsveränderung bei Erzeugnissen § 275 Abs. 2 Nr. 2, Korrektur des
  Materialaufwands bei Roh-, Hilfs- und Betriebsstoffen und Waren, Nr. 5), steht genau hier — das
  `us`-Pack schreibt in allen vier Zeilen dasselbe Gegenkonto (COGS) und ändert am Kern nichts.
- `provisions` → `data.accounts[]` = `{account, expenseAccount, releaseAccount, label}` und
  `data.discounting` = `{fromMonths, basis}`. **Der Zinssatz steht bewusst nicht im Pack** (§ 253
  Abs. 2 HGB, monatlich von der Bundesbank bekanntgegeben): die *Regel* ist Pack-Datum, der *Satz*
  ist Eingabe je Vorgang. Fehlt er, wo abzuzinsen ist, wird abgewiesen
  (`E_PROVISION_DISCOUNT_RATE_REQUIRED`) statt undiskontiert gebucht.
- `deferrals` → `data.kinds[]` = `{kind, account, label}` für genau zwei Richtungen
  (`prepaidExpense`, `deferredIncome`). Welches Aufwands-/Ertragskonto betroffen ist, sagt der
  Aufrufer — das ist eine Tatsache über den Vorgang, keine über die Rechtsordnung.
- `inputTaxAdjustment` → `data.correctionPeriods[]` = `{assetKind, years, basis}`,
  `data.deMinimis` = `{inputTaxAtMost, sharePointsAtLeast, amountAtMost, basis}`, `data.accounts`
  = `{taxAccount, expenseAccount, incomeAccount}`, `data.reportingKey`, `data.basis`. Die Mechanik
  (anteilige Berichtigung über einen Beobachtungszeitraum mit zwei Bagatellgrenzen) ist Kern; eine
  dem Pack unbekannte `assetKind` ist `E_PACK_INCOHERENT`, nie ein Default — fünf Jahre statt zehn
  halbieren jede Berichtigung.

> **`dimensionRules` ist nicht Teil dieses v0.6-Schritts.** Eine `kind: dimensionRules`-
> Modulform (mit Regel-Feldern wie `typeCode`/`required`/`allowedCodes`/`accounts`) ist
> im Vertrag **noch nicht verankert** und wird **kommunal, später** definiert — sie steht
> daher **nicht** im `kind`-Enum oben und wird in Gate 0 nicht normiert.

### `packManifest` (benanntes, kuratiertes Modul-Bündel, Heimat `packs/<id>.json`)

Eine **noch nicht aufgelöste** Modulliste + Overrides + `packPolicy`-Kopie. Der Mandant
pinnt ein Pack-Manifest per `id`+`version` (wie heute die Profilversion); `createTenant`
löst es **einmal beim Aufbau** auf (Resolver, `api.md`).

```json
{
  "formatVersion": "0.9",
  "id": "de",
  "name": "Deutschland",
  "version": "2026.10",
  "modules": [
    { "kind": "accounts", "id": "de-konten", "version": "2026.4" },
    { "kind": "tax", "id": "de-ust", "version": "2026.4" },
    { "kind": "mapping", "id": "de-bilanz", "version": "2026.1" },
    { "kind": "mapping", "id": "de-guv", "version": "2026.1" },
    { "kind": "mapping", "id": "de-euer", "version": "2026.2" },
    { "kind": "depreciation", "id": "de-afa", "version": "2026.7" },
    { "kind": "assetAccounts", "id": "de-assets", "version": "2026.1" },
    { "kind": "policy", "id": "de-policy", "version": "2026.1" },
    { "kind": "legalForms", "id": "de-rechtsformen", "version": "2026.1" },
    { "kind": "constraint", "id": "de-kleinunternehmer", "version": "2026.1" }
  ],
  "overrides": [],
  "taxCodes": ["USt19", "VSt19"],
  "defaults": { "taxationMethod": "cash", "smallBusiness": false, "vatPeriod": "quarterly" },
  "packPolicy": {
    "roundingMode": "halfUpAwayFromZero",
    "taxRoundingGranularity": "perVoucher",
    "currencyScale": 2
  }
}
```

> **Gekürzt, nicht vollständig** — das ausgelieferte `de`-Manifest führt mehr Module; die
> maßgebliche Liste steht in `pack-library/de-pack/de.json`, nicht hier.
>
> *Bis 2026-08-28 stand hier ein Manifest `de-complete` mit den Modul-`id`s `summae-base`,
> `de-ust-2026`, `afa-de` und `summae-base-asset-accounts` — **keine davon hat je existiert**, und
> das Pack `de-complete` gibt es nicht. Das Beispiel beschrieb ein Produkt aus der Entwurfsphase.
> IMPL-034 hat den Namen an anderer Stelle entfernt; diese Stelle blieb stehen.*

| Feld | Typ | Bedeutung |
|---|---|---|
| `formatVersion` | semver | `0.6`. |
| `id` / `name` / `version` | String | Pack-Identität; der Mandant **pinnt `id`+`version`**. |
| `modules` | Array `{kind, id, version?}` | Kuratierte Liste. Fehlt `version` → Resolver wählt deterministisch die höchste verfügbare (**segmentweiser Vergleich**, numerisch wo beide Segmente Zahlen sind, sonst Codepoint — `2026.10` folgt damit auf `2026.9`; bis 2026-08-28 war es ein Codepoint-Vergleich der ganzen Zeichenkette, F-CORE-048). |
| `overrides` | Array `{op, ref, with?}` | Module **weglassen** (`op: "remove"`) oder **ersetzen** (`op: "replace"`, `with: {kind,id,version?}`) — Nutzungsweg 2. **Nur** `remove`/`replace`, kein Feld-Patch (würde Modul-Versionierung/Determinismus unterlaufen). Eigene Mandanten-Konten/-Schlüssel legt die App **darüber** (F-CORE-007), nicht via Override. |
| `taxCodes` | Array von Strings | `taxCode`-Auswahl des **synthetisierten Profils** (reale `profile.taxCodes`-Form). Resolver-Prüfung I4: jeder Code wird von einem aufgelösten `tax`-Modul bereitgestellt, sonst `E_PACK_UNRESOLVED_REF`. |
| `defaults` | Objekt `{taxationMethod, smallBusiness, vatPeriod}` | Reale Profil-`defaults` (`TaxProfile::fromData`: `taxationMethod` accrual\|cash · `smallBusiness` bool\|Segmentliste · `vatPeriod` monthly\|quarterly). Geht ins synthetisierte Profil; Quelle des ResolvedPack-Felds `taxProfileDefaults`. Mit `additionalProperties: false` damit **kein** Widerspruch — `defaults` ist explizit Teil des Pack-Manifest-Schemas. |
| `packPolicy` | Objekt | **Pflicht-Kopie (E-A)** der effektiv geltenden Policy — denormalisiert, damit Strom-Reader/Cross-Import Beträge validieren können, **ohne** Modulauflösung (analog `baseCurrency` im Export-Manifest). MUSS mit dem aufgelösten `policy`-Modul übereinstimmen, sonst `E_POLICY_INVALID`. |

`additionalProperties: false`.

> **Kein mitgelieferter Kontenrahmen ist „der" Basis-Rahmen.** SKR03/04 werden **nicht**
> gebündelt (Lizenzentscheidung 2026-06-21), bleiben aber per `importChartOfAccounts` verfügbar;
> der jurisdiktionsneutrale Rahmen ist der des `default`-Packs. Die USt-VA braucht **kein**
> Mapping-Modul (mapping-frei).
>
> *Hier stand eine „kanonische Modulliste", die laut eigener Aussage in drei Dokumenten
> byte-identisch geführt werden musste. Sie benannte Module, die es nicht gibt, und die zwei
> anderen Dokumente existieren nicht mehr — eine Sync-Pflicht zwischen drei Orten ist genau die
> Sorte Regel, die eine Liste veralten lässt, statt sie zu pflegen. Das Regressions-Orakel „DE
> komponiert == DE heute" ist eine **Fixture** (F-PACK-001/002), und dort gehört es hin.*

## v0.7 — `partner.status`

Rein additiv, ein Feld: `partner` trägt ein optionales `status` (`active` | `inactive`); fehlend
gilt als `active`. Bestehende Bestände bleiben byte-identisch gültig.

**Dieser Abschnitt entstand am 2026-08-28 nachträglich** — 0.7 war ausgerollt, während dieses
Dokument noch v0.6 im Titel trug. Er steht hier auch als das kleinste Beispiel dafür, wofür der
Wächter unten da ist: ein Schritt, der zu klein wirkte, um aufgeschrieben zu werden, und genau
deshalb keinen Eintrag bekam.

## v0.8 — Hash-Kette im Audit-Trail (F-CORE-043)

Jeder Satz in `auditLog.jsonl` trägt zwei zusätzliche Felder:

```json
{ "id": "0190…", "at": "…", "actor": "…", "objectType": "account", "objectId": "0190…",
  "action": "created", "changes": { … },
  "previousRecordHash": "9f2c…|null", "recordHash": "1a7d…|null" }
```

- **`previousRecordHash`** — SHA-256 des **Vorgängersatzes** über kanonisches JSON (RFC 8785, § Grundsätze 1).
  `null` beim ersten Satz eines Mandanten *und* bei jedem Satz, der vor 0.8 geschrieben wurde.
- **`recordHash`** — SHA-256 **dieses** Satzes über kanonisches JSON **ohne dieses Feld selbst**,
  `previousRecordHash` eingeschlossen. Deshalb entwertet jede Änderung an einem früheren Satz alle
  späteren Glieder.

**Warum am Audit-Satz und nicht an der Buchung.** Die reservierten Felder (§ oben) sperren
`previousEntryHash` an der Buchung: Writer DÜRFEN es in v0.x nicht belegen, Reader MÜSSEN es
ignorieren. Eine Kette, die jeder konforme Leser überspringen *muss*, ist Nachweis für niemanden.
Der Audit-Satz trägt keine solche Reservierung, und die offene Pflicht
(`docs/gobd-conformance.md` §14 5c) betraf ohnehin den **Trail**. Die Reservierung an der Buchung
bleibt bestehen (SPEC-022).

**Gelöschte Sätze hinterlassen eine Hülle, kein Loch.** Wird ein Satz nach Löschrecht entfernt
(`erasePartner`, F-CORE-040), bleibt die Zeile mit **beiden Hashes** stehen; `actor`, `objectType`,
`action` tragen den reservierten Wert `"redacted"`, `objectId` zeigt auf den Satz selbst, `changes`
ist leer. Sonst wäre eine rechtmäßige Löschung von einer Manipulation nicht zu unterscheiden — und
schlimmer: sie bräche die Kette dauerhaft, sodass jede spätere Prüfung eine Manipulation meldete,
die nie stattfand. Der Inhalt einer Hülle lässt sich nicht mehr gegen ihren Hash prüfen; genau das
heißt Löschung.

**Prüfung:** Projektion `auditTrailIntegrity` (ohne Parameter) → `records`, `chained`, `unchained`,
`redacted`, `head`, `intact`, `breaks[]`. `head` wird veröffentlicht, weil **keine** Kette bemerkt,
wenn am **Ende** Sätze fehlen: dafür muss der Kopf außerhalb aufbewahrt und verglichen werden.

## v0.9 — Geschlossenes `subtype`-Repertoire und bedingte Constraints

Zwei Änderungen, eine davon **nicht** rein additiv.

**`account.subtype` ist ein geschlossenes Repertoire** (F-CORE-046). Bis 0.8 ein freier String, seit
0.9 elf Werte: `bank`, `cash`, `transit`, `ar`, `ap`, `tax_in`, `tax_out`, `result_allocation`,
`fixed_asset`, `opening_balance`, `private`. Die ersten acht liest die Engine und verzweigt darauf;
die letzten drei sind Annotation, die die ausgelieferten Packs tragen und kein Code konsultiert —
sie stehen im Repertoire, weil die Packs sie benutzen. Das **verengt** die vom Schema akzeptierte
Sprache: ein Bestand mit einem selbst erfundenen Subtyp validiert nicht mehr. Genau das ist der
Zweck — ein Pack, das `tax-out` statt `tax_out` schrieb, erzeugte ein Konto, das annotiert *aussah*
und inert war, und nichts in der Ausgabe sagte, dass ein Steuerkonto fehlte. Geprüft wird an den
zwei Stellen, an denen ein Subtyp *verfasst* wird (Pack-Auflösung und Konten-API), ausdrücklich
**nicht** im Konstruktor: das Laden eines gespeicherten Kontos läuft durch denselben Konstruktor,
und eine Prüfung, die zurückzulesen verweigert, was sie selbst geschrieben hat, wäre der schlimmere
Fehler. Die kanonische Liste in § v0.4 (`### Kanonische subtype-Liste`) war seit 0.4 vorhanden und
bis 0.9 unverbindlich.

**Die Constraint-Sorte bekommt ein drittes Wort und eine Bedingung** (F-CORE-047, § `module` →
`kind: constraint`):

- **`accountUsageRules`** — welche Konten dieser Mandant **überhaupt nicht** bebuchen darf,
  `forbidAccountIn` ohne `when`. Bewusst keine Kombinationsregel mit `forbidAccountIn: 0000–9999`:
  der Trick greift, weil jede Buchung mindestens zwei Konten hat, liest sich als Bereich und ist
  zweimal falsch — Kontonummern vergleichen über **Codepoints**, also deckt `0000–9999` einen
  Kontenrahmen mit Buchstaben nicht ab und einen sechsstelligen nur zufällig.
- **`appliesWhen`** — bedingt eine Regel **beider** Sorten auf eine **geschlossene** Menge von
  Mandantentatsachen (`legalForm`, `taxationMethod`). Geschlossen mit Absicht: sobald Bedingungen
  eine Ausdruckssprache werden, trägt ein Pack Logik, und die Trennung Substrat/Pack ist dahin.
  `smallBusiness` und Betragsgrenzen sind ausdrücklich nicht dabei, mit Begründung in
  `docs/proposals/constraint-vocabulary.md`.

Eine unbekannte Bedingung ist `E_PACK_INCOHERENT`, eine `legalForm`, die das Pack nicht deklariert,
ebenfalls (Resolver-Invariante I10) — sonst wäre die Regel dauerhaft **schlafend**, also genau der
stille Fehler, gegen den das geschlossene Repertoire gebaut wurde. Eine Regel, deren Bedingung nicht
erfüllbar ist, weil die Tatsache fehlt (kein `setEntityProfile`), greift **nicht**;
`tenantConfiguration` meldet sie trotzdem, damit ein Aufrufer „keine Regel" von „Regel wartet auf
eine Angabe" unterscheiden kann.

## Wer dieses Dokument gegen das Produkt hält

Bis 2026-08-28 niemand, und das war der Befund IMPL-037: `format.schema.json` wird gegen
`FORMAT_VERSION` gehalten (`FormatVersionTest` / `format-version.test.ts`, beide Sprachen), Code und
Schema konnten also nicht auseinanderlaufen — die **normative Quelle**, aus der beide abgeleitet
sind, prüfte keiner. Die Autorität stand damit auf dem Kopf. 0.7 stand deshalb wochenlang nirgends
hier, bei grünem Gate; 0.9 wäre der nächste Fall geworden.

Seit 2026-08-28 prüfen `DataFormatDocTest` / `data-format-doc.test.ts` (beide Sprachen, neben dem
GoBD-Zensus) drei enge Behauptungen — keine Prosaprüfung, die wäre weder erreichbar noch gewollt:

1. Die Version in der Überschrift **und** in der `$id`-Zeile dieses Dokuments ist `FORMAT_VERSION`.
2. Zwischen der ältesten hier beschriebenen Version und der aktuellen fehlt **kein** `## v0.x`.
   Deshalb tragen auch die kleinen Schritte (0.3, 0.5, 0.7) einen eigenen Abschnitt: eine
   Veröffentlichung kann ihren eigenen Eintrag nicht mehr überspringen.
3. Jeder `$defs`-Schlüssel des Schemas kommt in diesem Dokument vor. Ein Objekt, das das Schema
   kennt und die Spezifikation nicht nennt, ist dieselbe Lücke aus der anderen Richtung.
4. **Seit 2026-08-29:** das `kind`-Enum der Modul-Tabelle oben ist mengengleich mit dem `kind`-Enum
   in `format.schema.json`. Punkt 3 fing das nicht: `module` *kam* vor, nur seine Aufzählung der
   Modulsorten war vier Sorten im Rückstand — zum zweiten Mal, in derselben Zeile. Eine Aufzählung,
   die sich als geschlossen ausgibt, muss gegen die Quelle gehalten werden, aus der sie geschlossen
   ist, sonst ist sie eine Behauptung über einen Stand von gestern.

### Wo jeder `$defs`-Schlüssel spezifiziert ist

Der Index zu Punkt 3 — und er ist selbst der Grund, warum die Behauptung prüfbar wurde: neun der
23 Schlüssel kamen im Fließtext gar nicht vor, weil das Dokument sie unter *fachlichen* Namen
beschreibt (`entryLine` heißt hier „Position", `manifest` „Export-Manifest") und die Modul-`data`-
Formen nur als `kind` auftauchen. Ein Leser, der vom Schema herkommt, fand sie deshalb nicht.

| `$defs` | spezifiziert in |
|---|---|
| `uuid` | § Grundsätze 3 (UUIDv7) |
| `date` | § Grundsätze 4 |
| `timestamp` | § Grundsätze 4 |
| `money` | § Grundsätze 2 |
| `dimensionValue` | § `dimensionType` / Stammdaten |
| `taxTag` | § v0.2 → `taxTag` |
| `entryLine` | § `journalEntry` (Positionen) |
| `journalEntry` | § `journalEntry` |
| `account` | § `account`; Subtyp-Repertoire § v0.9 |
| `voucher` | § `voucher`; Belegart § v0.4 |
| `partner` | § v0.4 → `partner`; `status` § v0.7 |
| `openItem` | § `openItem` |
| `profile` | § v0.2 → `profile` |
| `packPolicy` | § v0.2 → `packPolicy` |
| `module` | § v0.6 → `module` |
| `packManifest` | § v0.6 → `packManifest` |
| `mapping` | § v0.2 → `mapping` |
| `mappingPosition` | § v0.2 → `mapping` (Positionen) |
| `constraintData` | § v0.6 → `module`, `kind: constraint`; erweitert § v0.9 |
| `depreciationData` | § v0.6 → `module`, `kind: depreciation` |
| `productionCostData` | § v0.6 → `module`, `kind: productionCost` |
| `auditRecord` | § v0.3 / § `auditLog.jsonl`; Hashes § v0.8 |
| `manifest` | § Mandant & Export-Manifest; präzisiert § v0.5 |

## Offene Punkte v0.4 → v0.5

- Kommunale Erweiterung (Finanzrechnungs-Kreis, Produkt-Pflichtdimension) — wartet auf Budgeting-Kontext
- Rückfluss der Befunde aus der PHP-Implementierung
- Regelmodul-Inhalte als eigene Lieferaufgabe: DATEV-BWA Form 01, USt-Jahreserklärungs-Mapping, Anlage-EÜR-Vollmapping
