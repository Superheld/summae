# Fehlerkatalog

*(Die Versionsangaben in Klammern hinter den Codes — `(v0.6)`, `(v0.8.0)` — bezeichnen das
summae-Release, in dem der Code dazukam. Der Katalog selbst wird nicht eigenständig versioniert;
bis 2026-08-16 stand hier „v0.5", was neben den Release-Markern nur verwirrte.)*

Fehler sind Vertragsbestandteil: gleicher Verstoß → gleicher Code in allen Implementierungen. Jeder Code MUSS mindestens eine Fixture haben (Spalte „Fixture"; ✗ = noch zu bauen). Codes sind stabil — Umbenennung ist ein Breaking Change.

## E_ENTRY — Buchung

| Code | Auslösende Invariante | Fixture |
|---|---|---|
| `E_ENTRY_UNBALANCED` | Σ Soll ≠ Σ Haben (F-CORE-001) | post-and-invariants |
| `E_ENTRY_NO_VOUCHER` | voucherId fehlt (F-CORE-003) | post-and-invariants |
| `E_VOUCHER_UNKNOWN` | voucherId gesetzt, aber kein Beleg vorhanden (v0.5/SPEC-001) | voucher-unknown |
| `E_ENTRY_TOO_FEW_LINES` | < 2 Positionen | post-malformed |
| `E_ENTRY_INVALID_AMOUNT` | Betrag ≤ 0, falsches Format oder Fremdwährung ≠ Mandantenwährung (v1) | post-malformed |
| `E_ENTRY_FINALIZED` | Korrekturversuch nach Festschreibung (F-CORE-002) | finalize-reverse-period |
| `E_ENTRY_ALREADY_REVERSED` | Doppelstorno | finalize-reverse-period |
| `E_ENTRY_UNKNOWN` | entryId existiert nicht | post-malformed |
| `E_ENTRY_HAS_OPEN_ITEMS` | Zeilen-Korrektur an einer Buchung, aus der offene Posten entstanden sind (v0.6) | correct-open-items |
| `E_ENTRY_HAS_SETTLED_ITEMS` | Storno einer Buchung, deren offener Posten bereits (teil-)ausgeglichen ist (v0.6/IMPL-008) | reverse-settled-item |

**Storno und offene Posten (IMPL-008, v0.6).** `reverse` gleicht die offenen Posten der stornierten
Buchung **aus** — mit einem Ausgleich, der auf die Storno-Buchung zeigt und `cause:
"cancellation"` trägt; der Posten erhält damit den abgeleiteten Status `cancelled` und
verschwindet aus der OP-Liste. Vorher blieb er stehen: das Hauptbuch zeigte das
Forderungskonto auf `0.00`, die OP-Liste dieselbe Forderung weiter als offen **und
ausgleichbar**. Ist ein Posten dagegen schon angefasst (mindestens ein Ausgleich), wird das
Storno **verweigert** — dieselbe Linie, die SAP mit F5308 zieht („document includes already
cleared items — reversal not possible"): erst den Ausgleich zurücknehmen oder eine Gutschrift
buchen, sonst verschwände Geld aus der OP-Historie, das tatsächlich geflossen ist. Das ist
zugleich die Antwort auf den offenen IMPL-005-Rest: der Fall „ausgeglichen, dann storniert"
entsteht gar nicht mehr, und die Korrektur läuft als eigene, zahlungswirksame Buchung mit
eigenem Datum — also genau so, wie §17 Abs. 1 UStG es verlangt („in dem Besteuerungszeitraum, in
dem die Änderung eingetreten ist"). Bei Ist-Versteuerung zählt ein `cancellation`-Ausgleich
**nicht** als Vereinnahmung: die USt-VA überspringt ihn, sonst erklärte ein Storno Steuer für
Geld, das nie geflossen ist.

## E_PERIOD / E_FISCALYEAR

| Code | Invariante | Fixture |
|---|---|---|
| `E_PERIOD_CLOSED` | Buchung in geschlossene Periode (F-CORE-004) | finalize-reverse-period |
| `E_PERIOD_OUT_OF_ORDER` | Schließen außer Reihenfolge | period-ordering |
| `E_PERIOD_UNKNOWN` | Buchungsdatum außerhalb angelegter Geschäftsjahre | period-ordering |
| `E_FISCALYEAR_CLOSED` | Wiedereröffnung nach Jahresabschluss | edge-errors |
| `E_FISCALYEAR_UNFINALIZED_ENTRIES` | closeFiscalYear bei nicht festgeschriebenen Buchungen (v0.5/SPEC-003) | fiscalyear-close-guard |

## E_APPROPRIATION — Ergebnisverwendung (v0.14.0)

`appropriateResult` bucht einen Gewinnverwendungsbeschluss (F-CORE-024/SF-25). Die
beiden Codes trennen zwei verschiedene Verweigerungen: **das Pack bietet den Vorgang
oder das Ziel nicht an** (Konfiguration — Schweigen ist eine gültige Antwort, eine halbe
nicht) gegen **die Bücher geben den Betrag nicht her** (Constraint). Beides ist absichtlich
kein `E_INPUT_INVALID`: die Eingabe ist wohlgeformt, sie ist nur nicht erfüllbar.

| Code | Invariante | Fixture |
|---|---|---|
| `E_APPROPRIATION_UNSUPPORTED` | Das Pack liefert kein `resultAppropriation`-Modul, oder das angefragte `target` ist darin nicht deklariert (v0.14.0) | result-appropriation-guards |
| `E_APPROPRIATION_EXCEEDS_RESULT` | Σ der Verwendungen übersteigt das noch nicht verwendete Ergebnis, oder es ist nichts zu verwenden (v0.14.0) | result-appropriation-guards |

## E_ACCOUNT / E_COA

| Code | Invariante | Fixture |
|---|---|---|
| `E_ACCOUNT_UNKNOWN` | Konto existiert nicht | post-malformed |
| `E_ACCOUNT_LOCKED` | Konto gesperrt | post-and-invariants |
| `E_ACCOUNT_NUMBER_TAKEN` | Kontonummer doppelt (Repository-Kontrakt) | accounts-and-import |
| `E_ACCOUNT_NOT_VALID_AT_DATE` | Buchung auf ein Konto außerhalb seines Gültigkeitsfensters `validFrom`/`validTo` (2026-08-28, F-CORE-045). `details`: `number`, `entryDate`, `validFrom`, `validTo` | account-validity |
| `E_ACCOUNT_USE_FORBIDDEN` | Das Pack verbietet diesem Mandanten die Nutzung des Kontos überhaupt — `accountUsageRules`, ggf. unter `appliesWhen` (2026-08-28, F-CORE-047). `details`: `account`, `forbiddenFrom`, `forbiddenTo`, `appliesWhen` | xx-12-constraint-applies-when |
| `E_COA_FORMAT_INVALID` | Kontenrahmen-Import nicht parsebar | accounts-and-import |


**Sperre und Gültigkeit sind zwei verschiedene Verweigerungen (2026-08-28).** `E_ACCOUNT_LOCKED` ist
unbedingt und gilt *jetzt*: das Konto nimmt keine Buchung an, auch keine Korrektur mit einem Datum
vor der Sperre. `E_ACCOUNT_NOT_VALID_AT_DATE` prüft gegen das **Buchungsdatum** — ein zum
Jahresende stillgelegtes Konto nimmt die verspätete Dezember-Korrektur weiter an und lehnt den
Januar ab, und genau das heißt „ein Konto stilllegen". Ein Aufrufer, der beide nicht auseinanderhält,
kann dem Anwender nicht sagen, ob er das Konto entsperren oder das Datum korrigieren muss. Die
Felder `validFrom`/`validTo` waren im Schema deklariert und wurden von **keiner** Implementierung
gelesen oder geschrieben — der Import verwarf sie stillschweigend, also nahm ein Konto mit
`validTo: 2025-12-31` eine Buchung vom 01.06.2026 anstandslos an. **Nur Schreibvorgänge:** in den
Auswertungen bleibt ein Konto außerhalb seines Fensters mit allen Buchungen sichtbar, denn die
Historie ist passiert.

`E_ENTRY_HAS_OPEN_ITEMS`: `correct` schrieb die Zeilen um und ließ den daraus entstandenen
offenen Posten unangetastet — Betrag, Konto und Fälligkeit im Nebenbuch stammten danach aus einer
Buchung, die es so nicht mehr gab, ohne jeden Hinweis. Der **Text** einer solchen Buchung bleibt
korrigierbar; für Beträge ist der GoBD-konforme Weg ohnehin Storno und Neubuchung, und der hält
Haupt- und Nebenbuch beisammen. Befund R-3, verwandt mit IMPL-008.

## E_SETTLEMENT / E_OPENITEM

| Code | Invariante | Fixture |
|---|---|---|
| `E_SETTLEMENT_EXCEEDS_ITEM` | Σ Ausgleiche > OP-Betrag | open-items-settlement |
| `E_SETTLEMENT_EXCEEDS_ENTRY` | Σ Ausgleiche gegen ein Konto > was die ausgleichende Buchung auf diesem Konto tatsächlich bewegt (v0.6) | settlement-bound |
| `E_SETTLEMENT_DIFFERENCE_INVALID` | `difference.kind` unbekannt, Betrag ≤ 0 oder > Restbetrag (v0.3) | settlement-discount |
| `E_OPENITEM_UNKNOWN` | openItemId existiert nicht | open-items-settlement |

**Unbekannter Mechanismus (v0.8.0).** Das Mechanismus-Repertoire ist **geschlossen** (Entscheidung
2026-08-16): ein Pack wählt einen der im Kern registrierten Mechanismen (`standard`,
`reverse_charge`, `intra_community_supply`, `exempt`) und trägt keinen Code. Ein Name, den es nicht
gibt, ist deshalb ein Tippfehler oder ein Pack gegen einen neueren Kern — vorher fiel beides still
auf `standard` zurück, und ein als `reverse-charge` vertippter Reverse-Charge-Schlüssel buchte eine
gewöhnliche Steuerzeile auf dem gewöhnlichen Konto in der gewöhnlichen USt-VA-Kennzahl. An der
Ausgabe war nicht zu erkennen, dass der Mechanismus weggefallen war. Der Fehler kommt beim
Auflösen, nicht erst bei der ersten Buchung. Fixture `resolver-unknown-mechanism`.

Abgrenzung der beiden `EXCEEDS`-Codes: `…_ITEM` schaut auf den **Posten** („du willst mehr
ausgleichen, als offen ist"), `…_ENTRY` auf die **Buchung** („du willst mehr ausgleichen, als
diese Zahlung überhaupt bewegt hat"). Beides sind Aufruferfehler und beide sind nötig — ohne den
zweiten kann eine Teilzahlung von 500,00 einen Posten über 1.190,00 vollständig schließen: das
Hauptbuch führt danach dauerhaft eine Forderung, die die OP-Liste nicht mehr kennt, und bei
Ist-Versteuerung wird Steuer als vereinnahmt gemeldet, die nie geflossen ist. Die Schranke ist die
**Netto-Minderung** des Kontos durch die Buchung, nicht die Buchungssumme: eine Zahlung mit Skonto
bucht den vollen Forderungsbetrag gegen die Forderung, die Differenz ist Teil des Ausgleichs und
nicht obendrauf — deshalb bleiben Skonto- und Forderungsverlustfälle gültig.

## E_CASHBASIS

| Code | Invariante | Fixture |
|---|---|---|
| `E_CASHBASIS_DEVIATING_FISCAL_YEAR` | EÜR-Projektion über abweichendes Geschäftsjahr (v0.3) | deviating-fiscal-year |

## E_PARTNER / E_FISCALYEAR (v0.4)

| Code | Invariante | Fixture |
|---|---|---|
| `E_PARTNER_UNKNOWN` | partnerId am Beleg existiert nicht | partner-and-ec-sales |
| `E_PARTNER_IN_USE` | `erasePartner` auf einen Partner, den ein Beleg oder ein offener Posten nennt — Aufbewahrungspflicht schlaegt Loeschrecht (v0.15.1, F-CORE-040). `details` nennt `vouchers`/`openItems` | partner-erasure |
| `E_FISCALYEAR_OVERLAP` | createFiscalYear überschneidet bestehendes Jahr | fiscal-year-management |

## E_TAX

| Code | Invariante | Fixture |
|---|---|---|
| `E_TAXCODE_UNKNOWN` | Steuerschlüssel nicht definiert | tax-expansion |
| `E_TAXCODE_NO_VALID_VERSION` | keine Regelversion zum Belegdatum gültig | tax-expansion |
| `E_PROFILE_RETROACTIVE_CONFLICT` | Profiländerung in festgeschriebenen Zeitraum | small-business-switch |
| `E_PROFILE_UNKNOWN` | Mandanten-Profil nicht vorhanden (createTenant) | create-tenant-profile |

## E_DIMENSION

| Code | Invariante | Fixture |
|---|---|---|
| `E_DIMENSION_INVALID` | unbekannter Typ/Code oder Pflichtdimension fehlt (Regelmodul) | edge-errors |
| `E_COMBINATION_REQUIRED` | Die Buchung beruehrt ein Konto aus `whenAccountIn`, aber keines aus `requireAccountIn` (constraint-Modul, v0.15.1, F-CORE-042). `details`: `account`, `requiredFrom`, `requiredTo` | xx-8-constraint-account-combination |
| `E_COMBINATION_FORBIDDEN` | Die Buchung beruehrt ein Konto aus `whenAccountIn` und eines aus `forbidAccountIn` (constraint-Modul, v0.15.1, F-CORE-042). `details`: `account`, `forbidden` | xx-8-constraint-account-combination |

## E_DEPRECIATION / E_COSTING

| Code | Invariante | Fixture |
|---|---|---|
| `E_ASSET_UNKNOWN` | Anlagegut existiert nicht | edge-errors |
| `E_ASSET_DISPOSED` | Operation auf abgegangenem Anlagegut | edge-errors |
| `E_COSTING_RUN_RELEASED` | Änderungsversuch an released Lauf | allocation-run |
| `E_COSTING_RUN_UNKNOWN` | runId existiert nicht (release/Projektion) (v0.5/SPEC-006) | costing-run-unknown |
| `E_COSTING_CYCLE` | Stufenleiter mit Zyklus | edge-errors |
| `E_COSTING_UNSOLVABLE` | Gleichungsverfahren: Kostenstellen reichen alles im Kreis weiter, keine behält etwas — das Gleichungssystem hat keine Lösung | allocation-method-refused |
| `E_COSTING_RUN_NOT_RELEASED` | Ein **draft**-Lauf soll die Bilanz bewerten (`valuateInventory.runId`). Eigener Code statt `E_COSTING_RUN_UNKNOWN`, weil die beiden **entgegengesetzte** Korrekturen verlangen: freigeben oder eine andere `runId` nennen. `details`: `runId`, `status` | inventory-valuation |
| `E_INVENTORY_ACCOUNT_INVALID` | `valuateInventory` soll auf ein Konto bewerten, das kein Vorratskonto ist (`subtype` ≠ `inventory`). Die Buchung ginge auf, jede Invariante bliebe erfüllt — und der Betrag stünde in der falschen Bilanzposition. `details`: `account`, `subtype` | inventory-valuation |
| `E_PROVISION_UNKNOWN` | `provisionId` existiert nicht | provisions |
| `E_PROVISION_ACCOUNT_INVALID` | `recognizeProvision` soll eine Rückstellung auf ein Konto bilden, das keines ist (`subtype` ≠ `provision`). `details`: `account`, `subtype` | provisions |
| `E_PROVISION_EXCEEDS_CARRYING` | Es soll mehr aufgelöst werden, als die Rückstellung trägt — die Differenz wäre erfundener Ertrag. **Nicht** beim *Verbrauch*: eine höhere Rechnung als geschätzt ist der Normalfall und wird als Aufwand des laufenden Jahres gebucht, nicht abgewiesen. `details`: `provisionId`, `carryingAmount` | provisions |
| `E_PROVISION_DISCOUNT_RATE_REQUIRED` | Die Restlaufzeit überschreitet die vom Pack erklärte Grenze, es ist also abzuzinsen — und kein `discountRate` liegt vor. **Abgewiesen statt undiskontiert gebucht:** der Satz wird periodisch veröffentlicht und ist keine Pack-Konstante; ein veralteter Rechtssatz, der amtlich aussieht, ist schlimmer als ein fehlender. `details`: `months`, `fromMonths` | provisions |

## E_MAPPING

| Code | Invariante | Fixture |
|---|---|---|
| `E_MAPPING_OVERLAP` | ein Konto fällt in mehrere Mapping-Positionen | mapping-import |

(Mapping-Lücken sind kein Fehler: `gapWarnings[]` + Auffangposition.)

## E_PACK / E_POLICY (Pack-Komposition)

Der Pack-Resolver scheitert **laut** statt still falsch zu rechnen: Er liefert entweder
einen vollständig integren `ResolvedPack` oder genau **einen** dieser Codes mit
`details` (beteiligte `{kind,id}`, Konto-`number`, `code`). Trennlinie der
beiden Resolver-Codes: `E_PACK_UNRESOLVED_REF` = eine Referenz zeigt ins Nichts;
`E_PACK_INCOHERENT` = die Referenzen existieren, aber das Bündel ist in sich
widersprüchlich. `E_PACK_UNRESOLVED_REF` hat Vorrang, wenn beide zugleich zuträfen.

| Code | Auslösende Invariante | Fixture |
|---|---|---|
| `E_PACK_UNRESOLVED_REF` | Eine Referenz im Manifest/Modul zeigt ins Leere: Modul-`id`/`version` im Modulbestand nicht gefunden, `dependsOn` zeigt auf ein nicht in der effektiven Liste enthaltenes Modul, oder ein gefalteter Beitrag referenziert ein fehlendes Ziel — `taxAccount` (bzw. `inputTaxAccount` bei `reverse_charge`) ohne Konto (I1), Mapping-Selektor trifft kein Konto / zeigt vollständig ins Leere (I2), eines der fünf `assetAccounts.*Account` fehlt (I3), ein vom Profil/Manifest referenzierter `taxCode` wird von keinem aufgelösten `tax`-Modul bereitgestellt (I4, mapping-frei) | resolver-errors |
| `E_PACK_INCOHERENT` | Referenzen existieren, aber das Bündel passt nicht zusammen: Abhängigkeits-Zyklus, Konto-`number`-Kollision aus zwei Kontenrahmen (I6), doppelter `taxCode.code`/`mapping.id` oder mehr als ein `policy`-Modul (I7), kollidierender oder ins Leere greifender `override` (Doppel-Override, `replace` auf nicht gelistetes Modul), unbekanntes `kind`, **unbekannter `mechanism` an einem `taxCode`** (v0.8.0) | resolver-errors |
| `E_POLICY_INVALID` | `packPolicy`-Wert ungültig oder inkonsistent: unbekannter `roundingMode`/`taxRoundingGranularity`-Enum, `currencyScale` nicht ganzzahlig oder außerhalb 0–4, ISO-Exponent-Widerspruch, Manifest-`packPolicy`-Kopie ≠ aufgelöstes `policy`-Modul, oder `currencyScale`-Änderung auf bestehendem Mandanten | resolver-policy-invalid |
| `E_AMOUNT_SCALE_MISMATCH` | Betrag im Bestand hat eine andere Nachkommastellenzahl als der `currencyScale` des Mandanten verlangt (exakte Stellenzahl inkl. Pflicht-Nullen, kanonische Form) — Reader-/Writer-Prüfung jenseits des kontextfreien amount-Patterns | Adapter-Test |

**Die Zahlen stehen bewusst nicht mehr hier — `validate.py` zählt sie.** Bis 2026-08-28 stand an
dieser Stelle „Stand 2026-08-16: 44 Codes in Tabellen, 40 mit Fixture", und beides war falsch
geworden (es sind 53 und 49). Schlimmer als die Zahl war die Liste dahinter: sie führte
`E_POLICY_INVALID` als „ohne Fixture", obwohl `resolver-policy-invalid` seit dem 2026-08-16 läuft —
ein Katalog, der eine Lücke behauptet, die geschlossen ist, ist genauso unbrauchbar wie einer, der
eine Lücke verschweigt. Eine handgepflegte Zahl neben einem Skript, das sie ausrechnet, veraltet;
sie ist ersatzlos gestrichen.

**Ohne Fixture, und je aus einem eigenen Grund:**

- `E_AMOUNT_SCALE_MISMATCH` — **Persistenz-Ebene, über die Suite nicht erreichbar** (seit
  2026-08-28 gebaut, IMPL-040). Er beurteilt einen Betrag, der **schon im Bestand steht**, nicht
  einen, den ein Aufrufer anbietet — den beurteilt `E_ENTRY_INVALID_AMOUNT`, und `post-malformed`
  nagelt das fest. Einen Bestand mit falscher Stellenzahl kann diese Engine gar nicht erzeugen; er
  kommt aus einer anderen Runtime, einer Rücksicherung oder von Hand. Eine Fixture kann so einen
  Bestand nicht herstellen, ein Adapter-Test schon: `AmountScaleTest` /
  `amount-scale.test.ts`, je fünf Fälle, beide Richtungen. Er war bis dahin der einzige Code, der
  deklariert war und von nichts ausgelöst wurde.
- `E_WORKSPACE_INVALID` — CLI-Ebene, über die Suite gar nicht erreichbar; per Sprache durch einen
  Kontrakt-Test belegt.
- `E_NOT_IMPLEMENTED` und `E_UNEXPECTED` — Auffangcodes, die auszulösen bedeuten würde, den Fehler
  zu bauen, den sie melden. Ebenfalls Kontrakt-Test statt Fixture.

Hinweis: `runDepreciation` auf bereits gelaufene Periode ist **kein Fehler** (idempotent, `alreadyRun: true`) — bewusste Abweichung, siehe `api.md`.

## E_INPUT — Eingabe (v0.5.2)

| Code | Invariante | Fixture |
|---|---|---|
| `E_INPUT_INVALID` | ein übergebener Parameter/Feld ist **vorhanden, aber kein gültiger Wert** — Pflichtparameter fehlt, falscher Typ, unbekannter Aufzählungswert, unbekannte Mapping-Kennung | input-invalid |

Abgrenzung: `E_INPUT_INVALID` ist ein **Aufruferfehler**, kein interner Fehler. Vorher
endeten diese Fälle entweder als ungefangene `InvalidValue` (Stacktrace bzw. `E_UNEXPECTED`
mit Exit 1 — für einen automatisierten Aufrufer nicht von einem summae-Bug unterscheidbar)
oder wurden still auf einen plausiblen Standardwert gezogen, was zu falschen Zahlen ohne
jede Fehlermeldung führte (Befunde IMPL-006, IMPL-007, IMPL-013).

Wo ein **spezifischerer** Code existiert, hat dieser Vorrang: ein unbekanntes Konto bleibt
`E_ACCOUNT_UNKNOWN`, ein unbekannter Steuerschlüssel `E_TAXCODE_UNKNOWN`. `E_INPUT_INVALID`
greift nur, wenn die Eingabe schon als Eingabe unbrauchbar ist.

## E_WORKSPACE (CLI-Arbeitsverzeichnis, v0.6)

| Code | Invariante | Fixture |
|---|---|---|
| `E_WORKSPACE_INVALID` | `summae.json` ist lesbar, aber ein Pflichtfeld fehlt oder ist unbrauchbar (`tenantId`, `name`, `baseCurrency`) | CLI-Test (kein Fixture: nur über die CLI erreichbar) |

Die Arbeitsdatei ist ein Vertrag, keine Anregung. Vorher wurde jedes Feld auf einen Standardwert
gezogen und die `tenantId` neu erzeugt, wenn sie fehlte — die CLI öffnete dieselbe Datenbank unter
einer anderen Identität und meldete ein leeres Hauptbuch. Von „die Bücher sind leer" war das für
niemanden zu unterscheiden. Befund R-9.

## E_NOT_IMPLEMENTED (Dispatcher, v0.8.0)

| Code | Invariante | Fixture |
|---|---|---|
| `E_NOT_IMPLEMENTED` | `TenantOperations` kennt den Namen der Operation/Projektion nicht — Tippfehler, Verwechslung, oder eine Fähigkeit, die diese Implementierung noch nicht verdrahtet hat | Kontrakt-Test (kein Fixture: nur über den Dispatcher erreichbar) |

Nachgetragen 2026-08-16 (Befund IMPL-018). Der Code wurde von Anfang an geworfen, hatte eine
Exit-Nummer (44) und stand im Handbuch — nur hier fehlte er. Die Grenze dieses Katalogs ist
nicht „fachlicher Fehler", sondern **alles, worauf ein Aufrufer sich verlassen kann**: derselbe
Grund, aus dem der reine CLI-Code `E_WORKSPACE_INVALID` oben drinsteht. Ohne Zeile hier war er
für jede maschinelle Prüfung unsichtbar — `validate.py` sah ihn nicht, und der Exit-Code-Wächter
der Implementierungen prüft gegen diesen Katalog.

Abgrenzung nach unten: `E_NOT_IMPLEMENTED` heißt „diesen Namen gibt es hier nicht", nicht „die
Eingabe ist unbrauchbar" — Letzteres ist `E_INPUT_INVALID`. Ein **bekannter** Name mit falschen
Parametern ist nie `E_NOT_IMPLEMENTED`.

Nicht zu verwechseln mit `E_UNEXPECTED`: der ist **bewusst kein Katalogcode** und behält Exit 1.
Er bedeutet, dass summae den Fehler nicht klassifizieren konnte — ein Bug-Report, kein Fall, den
ein Aufrufer behandeln kann. `validate.py` zählt ihn trotzdem mit und meldet ihn dauerhaft als
„ohne Fixture", weil sein Regex jedes `` `E_…` `` im Fließtext greift, nicht nur Tabellenzeilen.

## Konventionen

Fehler tragen strukturierte Details (`code`, `message` (implementierungsfrei formulierbar), `details`-Objekt mit den beteiligten IDs/Werten). Die Fixture prüft nur `code` — Wortlaut ist frei.
