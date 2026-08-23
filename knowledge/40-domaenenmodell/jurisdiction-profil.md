# Jurisdiction Profile (Pack)

**Status: benennt ein bereits implizit existierendes Konzept (2026-06-08).** Profile gibt es längst (TaxProfile, Mappings, Subtypes, `assetAccounts` = „Profil-Bestandteil" laut assets-modell.md). Dieses Dokument hebt es zu einem first-class Konzept und verallgemeinert es. Keine Vertragsänderung — Datenformat/API bleiben v0.5.

## Idee in einem Satz

Der Kern ist jurisdiktionsfrei (Substrat + Mechanik, siehe NF-5.3). Ein **Pack** ist das Bündel aller Daten und Regeln, die eine konkrete Jurisdiktion ausmachen — **tzdata fürs Rechnungswesen**. „Deutschland" ist das erste vollständige Pack, nicht die eingebaute Annahme.

## Was ein Pack bündelt

| Bestandteil | heute schon als | Politiksorte (NF-5.3) |
|---|---|---|
| Kontenrahmen + Kontensemantik-Tags (bank/cash/tax/result …) | Kontenrahmen-Import + Subtypes | Daten |
| Steuerschlüssel + Versionen + Versteuerungsregeln | `TaxProfile`, `taxCodes` | Expansion (Daten; Code nur bei neuem Paradigma) |
| Rundungspolitik (Modus, Steuer-Granularität, Währungsskala) | heute in `determinismus.md` als DE-Default | Parameter (Daten) |
| Abschluss-/Auswertungs-Mappings (Bilanz, GuV, EÜR-Zeilen, VA-Kennzahlen) | `mappings` | Projektion (Daten) |
| Anlagen-Konten + AfA-Methoden/-Tabellen | `assetAccounts`, Regelmodul-AfA | Expansion (Daten) |
| Kalender-/Geschäftsjahr-Konvention | `fiscalYear` | Constraint + Parameter |
| Belegpflichtfelder (z. B. USt-IdNr. bei igL) | Constraint am Beleg | Constraint (Daten) |
| Export-Adapter (DATEV / SAF-T / FEC …) | `datevExport`, `journalExport` | Projektion + dünner Code je Format |
| E-Rechnungs-Pflichtfelder | App-Sache, Pack liefert die Felddefinition | Daten |

Lesart: **fast alles ist Daten.** Echten *Code* braucht ein Pack an genau zwei Stellen — ein neues Steuer*paradigma* (US-Sales-Tax hat keinen Vorsteuerabzug → anderer Algorithmus, nicht anderer Zahlenwert) und je ein dünner Export-Serializer.

## Konformitätsanspruch (das Abbruchkriterium als Test)

Ein Pack ist „echt entkoppelt", wenn:

1. das **DE-Pack** ohne eine einzige Kernänderung läuft (heute erfüllt), und
2. ein bewusst **schräges fiktives Pack** (3-Nachkomma-Währung, Rundung je Position, kein Vorsteuerabzug) ebenfalls ohne Kernänderung liefe.

Punkt 2 ist heute noch nicht als Fixture gebaut — er braucht die Profil-Policy-Felder (Rundungsmodus, Skala) explizit im Format, und die sind bewusst auf die nachfragegetriebene Stufe verschoben (siehe `offene-fragen.md`). **Ehrliche Einordnung: Bis das fiktive Test-Pack grün läuft, ist die Jurisdiktionsfreiheit *behauptet, nicht erzwungen*.** Punkt 1 (DE-Pack ohne Kernänderung) ist durch die abgeschlossene PHP-Referenz faktisch belegt; Punkt 2 ist die noch offene Hälfte des Vertrags — bewusste, festgehaltene Lücke, kein Versehen.

## Komponierbare Packs: Modul / Manifest / Resolver (Design 2026-06-09, Bau nachfragegetrieben)

Ein Pack ist **kein Monolith**, sondern selbst eine Komposition — dasselbe Prinzip wie Kern/Pack/App, eine Ebene tiefer. „DE-complete" ist nur *ein kuratiertes* Manifest, nicht der einzige Weg.

- **Modul** = adressierbare Einheit, **Granularität: kohärenter Regelsatz** (nicht atomar): ein Kontenrahmen, ein Steuerschlüssel-Satz, ein einzelnes Mapping (`de-hgb-bilanz-266`), ein AfA-Regelsatz, eine Kalenderregel, eine Rundungspolitik. Ein Modul deklariert, **was es beiträgt** (welche Politiksorten-Beiträge) und **wovon es abhängt** (z. B. Mapping-Modul → Kontenrahmen-Modul, dessen Konten es referenziert).
- **Pack** = benannte, **aufgelöste** Liste von Modulen (Manifest). Kuratierte Packs (DE-complete) sind gepflegte Manifeste mit eigenen Konformitäts-Fixtures als Kohärenzbeweis.
- **Drei Nutzungswege, ein Mechanismus:**
  1. *Kuratiert nehmen* — `pack: "de-complete"`, batteries included.
  2. *Kuratiert + anpassen* — Module **weglassen** oder **überschreiben** (DE-Steuer behalten, eigener Kontenrahmen).
  3. *Selbst komponieren* — eigene Modulliste à la carte, ohne das volle DE-Pack (z. B. nur `de-ust-2026` + `skr03` + eigenes Minimal-Mapping).
  Einzelne eigene Konten/Schlüssel legt die App **darüber** (F-CORE-007), unabhängig von Modulen.
- **Resolver (der Knackpunkt):** Beim Zusammenstellen prüft ein Pack-Resolver Abhängigkeiten und referentielle Integrität (bucht ein Steuerschlüssel auf ein Konto, das der gewählte Kontenrahmen nicht hat? referenziert ein Mapping fehlende Konten? braucht eine Projektion ein taxTag, das kein gewähltes Modul erzeugt?) und **scheitert laut** statt still falsch zu rechnen — geplante Fehlerklasse `E_PACK_UNRESOLVED_REF` / `E_PACK_INCOHERENT`. Freie Komposition erzeugt die Pflicht zur Validierung; das ist ihr Preis und ihr Sicherheitsnetz.
- **Konformität:** Eine Eigenkomposition geht durch denselben Resolver und kann gegen dieselbe Suite geprüft werden. Hier findet auch das vorgemerkte **fiktive Test-Pack** seinen Platz (Naht-Beweis).

**Self-contained Packs (Entscheidung 2026-06-21, umgesetzt).** Die *ausgelieferten* Packs sind abgeschlossene Bündel: jedes hält seine eigenen Module in seinem Ordner (`pack-library/<pack>/`, z. B. `de-pack/`, `default-pack/`), **kein über Packs geteiltes `modules/`**, Modul-IDs je Pack eindeutig. „Packs bauen nicht aufeinander auf" gilt strikt: kein Pack referenziert die Module eines anderen (das `de`-Pack hat seinen *eigenen* Kontenrahmen `de-konten`, nicht den des `default`-Packs). Modul → Politiksorte ist über `kind` eindeutig (`tax`/`depreciation`/`assetAccounts`→Expansion, `mapping`→Projektion, `accounts`→Substrat, `policy`→Parameter). Die drei À-la-carte-Nutzungswege bleiben für *eigene* Kompositionen erhalten.

**Status:** Konzept festgehalten, **Umsetzung nachfragegetrieben** (Modul-Registry, Resolver, `E_PACK_*`-Codes, Manifest-Format im Datenformat, Resolver-Fixtures — siehe `offene-fragen.md`). Festgehalten *jetzt*, damit der Pack nicht versehentlich als Blob ins Format gegossen wird (späterer Breaking Change).

## Schichtenzuordnung (die Rosetta — was ist Kern, was DE-Pack, was App)

Damit die Komposition nachvollziehbar bleibt und altes (DE-zentrisches) und neues (jurisdiktionsfreies) Denken nicht verschwimmen: Diese Tabelle ordnet jedes Konzept einer Schicht zu. **Faustregel:** zitiert es einen Paragraphen → DE-Pack; ergäbe es für eine fiktive Jurisdiktion Sinn → Kern; beginnt es mit „der Anwender muss…" → App; **erfordert es Ermessen → App.**

**Ermessens-Kriterium (Determinismus-Test, 2026-06-09):** Eine fachliche Fähigkeit ist modulfähig (Expansion mit Pack-Stecker), wenn sie deterministisch aus Regeldaten + Buchungsbestand ableitbar ist — gleicher Input, gleiche Buchungen. Darum ist AfA eine Expansion (tabellengetrieben, ermessensfrei) und Bewertungs-Ermessen (Rückstellungshöhe, Forderungsabwertung, Niederstwerttest) App-Sache. Die Grenze ist nicht prinzipiell: Die App darf die Ermessensgröße als Parameter an eine Expansion reichen — der *mechanische* Teil (Abzinsung, Auflösungs-/Differenzbuchung, Kursdifferenz bei gegebenem Kurs) kann später Modul werden, das Urteil selbst nie.

| Konzept / Artefakt | Schicht | Bemerkung |
|---|---|---|
| Buchung, Konto, Journal, Saldo, Periode, Festschreibung, Storno, OP, Dimension | **Kern (Substrat)** | jurisdiktionsfrei; `ledger-modell.md` |
| `post`, `settle`, `reverse`, `closePeriod`/`closeFiscalYear`, Projektions-*Mechanismus*, `expand`-*Mechanismus*, `allocate` | **Kern (Mechanik)** | Politiksorten-Sockel, gesetzesfrei |
| Datenformat, API-Operationen, Fehlercodes, Determinismus-*Mechanik* | **Kern** | `50-spezifikation/`; DE-Beispiele dort sind Illustration |
| Steuerschlüssel-Inhalte, USt-Sätze/-Regeln, Soll/Ist, KU-Schwellen | **DE-Pack** | `tax-modell.md` (Mechanik) + Pack-Daten (Werte/Regeln) |
| Basis-Kontenrahmen `summae-base`, Kontensemantik-Tags | **mitgeliefert (Default)** | offener, jurisdiktionsneutraler Rahmen — der ausgelieferte Default |
| Kontenrahmen SKR03/04 | **DE-Pack** | nicht mitgeliefert; via `importChartOfAccounts` verfügbar (Entscheidung 2026-06-21) |
| Bilanz/GuV/EÜR/USt-VA-Mappings | **DE-Pack** | Projektions-Mappings (Daten) |
| AfA-Tabellen, GWG-Grenzen, degressive Sätze | **DE-Pack** | `assets-modell.md` (Mechanik) + Pack-Daten |
| Rundungsmodus, USt-Granularität, Währungsskala, GJ-Konvention | **DE-Pack-Policy** | Mechanik global, *Werte* im Pack (`determinismus.md`-Kasten) |
| Z3/GoBD-Export, DATEV-Stapel/-Stammdaten, ZM-Grundlage | **DE-Pack** | Export-Adapter/-Projektionen |
| Gesamtes `10-fachwissen/` | **DE-Pack-Wissen** | Domänenwissen des ersten Packs, nicht „der Produktscope" |
| E-Rechnung, ELSTER, Lohnabrechnung, Aufbewahrungs-Workflow, UI, Übermittlung | **App** | `30-anforderungen/out-of-scope.md` |
| Bewertungs-Ermessen (Rückstellungshöhe, Forderungsabwertung, Niederstwerttest) | **App** | Ermessens-Kriterium (oben); mechanischer Teil später als Expansion möglich |

**Konvention für alle Modell- und Spec-Dokumente:** DE-Beispiele (USt, GoBD-Festschreibung, SKR-Konten, §-Verweise) illustrieren die jurisdiktionsfreie Mechanik — sie sind **Beispiel, nicht Festlegung**. Was tatsächlich DE-Pack ist, steht in dieser Tabelle. Das Substrat enthält keinen Paragraphen.

## Politiksorten-Zensus (die zweite Achse — welcher der drei Kompositionstypen)

Die Rosetta oben sortiert nach **Schicht** (Kern/Pack/App). Diese Ansicht sortiert dieselben Operationen nach **Politiksorte** (NF-5.3). Erst beide Achsen zusammen sind der vollständige Zensus — und machen die zentrale Behauptung („alles über dem Substrat ist genau eine von drei Sorten") *durch Aufzählung* prüfbar, nicht nur durch Definition. Für Pack-Autoren ist es die Bauanleitung: **eine Jurisdiktion ergänzen = die Stecker der Expansionen + die Mappings der Projektionen + die Regeln der Constraints liefern.**

**Substrat (keine Politiksorte — die Mechanik darunter):** `post` (Buchung anlegen), Journal-Append, Saldo-Faltung, `sequenceNumber`-Vergabe, `correct` (Entwurf im Status `entered` ändern). Jurisdiktionsfrei, ohne Stecker.

**Expansionen** (Absicht → ausbalancierte Buchungen; Sockel Kern + Stecker Pack):
`expandTax` · `postVoucher`-Komposition · `settle`/`settleVoucher` mit Differenz (Skonto/§ 17) · `runDepreciation` (AfA-Lauf) · GWG-Weiche bei `acquireAsset` · `disposeAsset` (Abgangsbuchung) · `reverse` (Generalumkehr-Gegenbuchung) · Costing-Umlagen/kalkulatorische Kosten.

**Projektionen** (Journal → Sicht; Mechanik Kern + Mapping Pack):
`trialBalance`/SuSa · `balanceSheet` · `incomeStatement` (inkl. Monats-GuV) · `cashBasisReport`/EÜR · `vatReturn` · `ecSalesList`/ZM · `openItems` · `assetRegister` · `auditLog` · `unfinalizedEntries` · `costAllocationSheet`/BAB · `journalExport`/Z3 · `datevExport`.

**Constraints** (Prädikate, die gelten müssen — durchgesetzt beim Schreiben; verwaltet durch Zustandsübergänge):
Σ Soll = Σ Haben · Belegpflicht (`voucherId`) · Periode offen (Übergänge: `closePeriod`/`reopenPeriod`/`closeFiscalYear`) · Festschreibung unveränderbar · Dimensions-Validierung (Mechanik Kern + Pack-`dimensionRules`) · Belegpflichtfelder (z. B. USt-IdNr. bei igL → Pack) · Journalnummer-Lückenlosigkeit (NF-6) · EÜR nur bei Kalenderjahr (`E_CASHBASIS_DEVIATING_FISCAL_YEAR`). Die Durchsetzungsreihenfolge steht in `api.md` (Prüfreihenfolge), die Verstöße im Fehlerkatalog.

> Hinweis zur Präzision: `correct` und `post` sind **Substrat**, keine Expansion (sie tragen keinen Stecker). `closePeriod`/`closeFiscalYear` sind **Operationen, die Constraint-Zustand verwalten** — der Constraint ist „Periode offen", die Operation ist der Übergang.

## Begriffsklärung (eine Linie durch drei Benennungen)

Über die Versionen sind drei Sprachfassungen desselben Gedankens entstanden — sie meinen dasselbe, in zunehmender Schärfe:

- **Drei-Schichten** (Kern / Regelmodule / App, Entscheidung 2026-06-07) — die erste, grobe Fassung.
- **Substrat / Politiksorten** (NF-5.3, 2026-06-08) — die präzise Fassung: der „Kern" ist das Substrat, die „Regelmodule" zerfallen in Constraint/Projektion/Expansion.
- **Pack** (dieses Dokument) — das konkrete Bündel, in dem die Regelmodul-Inhalte einer Jurisdiktion zusammengefasst sind.

Kurzformel: **Regelmodul-Inhalt = Pack-Inhalt; „Regelmodul" und „Pack" sind synonym** (Pack ist der zusammengefasste, versionierte Liefergegenstand). Wo ältere Dokumente „Regelmodul" sagen, ist Pack gemeint.

## Verhältnis zu bestehenden Dokumenten

Schärft `00-projekt/entscheidungen.md` (Drei-Schichten, 2026-06-07) und NF-5.3. Der „fiktive-Jurisdiktion"-Lackmustest aus dem Lieferumfang wird hier zum formalen Konformitätsanspruch. Mechanik-Details bleiben in `ledger-modell.md`, `tax-modell.md`, `assets-modell.md`.
