# Glossar — Ubiquitous Language

Regeln:

1. **Ein Begriff, eine Bedeutung — pro Kontext.** Wo dasselbe Wort in zwei Gebieten Verschiedenes bedeutet, steht es mehrfach hier, mit Kontextangabe. Solche Stellen sind Kandidaten für Bounded-Context-Grenzen.
2. **Deutscher Fachbegriff ist führend** (die Domäne spricht Deutsch); der EN-Name ist der verbindliche API-/Code-Name. Einmal festgelegt, wird er überall benutzt — Code, Doku, Gespräche.
3. Einträge sind vorläufig, aber präzise. Änderungen über `00-projekt/entscheidungen.md`.

## Kern (kontextübergreifend)

| DE | EN (API) | Definition |
|---|---|---|
| Beleg | `voucher` (entschieden 2026-06-07) | Nachweis eines Geschäftsvorfalls; Grundlage jeder Buchung. Die Belegdatei heißt `document` (Referenz + Hash; Storage ist App-Sache) |
| Geschäftsvorfall | `business transaction` | wirtschaftliches Ereignis, das Vermögen/Schulden/Erfolg ändert; kann mehrere Buchungen auslösen |
| Buchung | `journal entry` | Abbildung eines Geschäftsvorfalls als Soll-/Haben-Positionen; Invariante Σ Soll = Σ Haben |
| Buchungsposition | `entry line` | einzelne Zeile einer Buchung: Konto, Seite, Betrag |
| Soll / Haben | `debit` / `credit` | linke/rechte Seite eines Kontos |
| Konto | `account` | Sammelstelle gleichartiger Buchungen; Typ bestimmt Saldenmechanik |
| Kontenrahmen | `chart of accounts template` | standardisiertes Ordnungsschema (SKR03/04, kommunale Rahmen) — Daten, nicht Code |
| Kontenplan | `chart of accounts` | konkreter, abgeleiteter Plan eines Mandanten |
| Journal / Grundbuch | `journal` | alle Buchungen chronologisch, append-only; autoritative Aufzeichnung |
| Hauptbuch | `general ledger` | Projektion des Journals je Konto |
| Nebenbuch | `subledger` | Detaillierung eines Sammelkontos (Debitoren, Kreditoren, Anlagen) |
| Periode | `period` | abgrenzbarer Buchungszeitraum; schließbar |
| Geschäftsjahr | `fiscal year` | Jahresrahmen der Perioden |
| Storno | `reversal` | Korrektur durch Umkehrbuchung; referenziert das Original |
| Festschreibung | `finalization` / `finalize` | GoBD-Zustandswechsel: Buchung wird unveränderbar |
| Saldo | `balance` | Differenz Soll−Haben eines Kontos |
| Mandant | `tenant` | buchführende Einheit; oberste Datengrenze |
| Betrag | `money` | Dezimalwert + Währung; nie Float |
| Steuerschlüssel | `tax code` | gebündelter Steuersachverhalt: Satz (mit Gültigkeit) + Buchungsregel + Meldekennzahl |
| Offener Posten | `open item` | unausgeglichene Forderung/Verbindlichkeit; referenziert Ursprungsbuchung |
| Ausgleich | `settlement` | Zuordnung Zahlung → offene(r) Posten, auch teilweise |
| Dimension | `dimension` | frei definierbare Zusatzzuordnung einer Position (Kostenstelle, Kostenträger, Produkt, …); Typen sind Stammdaten |
| Buchen | `post` | Domain Service: Buchung vollständig und gültig ins Journal aufnehmen |
| Journalnummer | `sequence number` | lückenlose laufende Nummer im Journal, je Geschäftsjahr (GoBD-Journalfunktion) |
| Geschäftspartner | `partner` | Debitor/Kreditor als schlankes Stammdatenobjekt (Name, USt-IdNr., Konto-Refs) — kein CRM |
| Debitor / Kreditor | `customer / supplier` | Kunde (Forderungsseite) / Lieferant (Verbindlichkeitsseite); `partner.kind` |
| Leistungsdatum | `service date` | Datum/Zeitraum der Leistungserbringung — steuert Steuerregelversion (§ 27 UStG) und VA-Zuordnung bei Soll-Versteuerung |
| Gutschrift | `credit note` | Korrekturbeleg; gleicht offene Posten aus, erzeugt keinen neuen (F-CORE-023) |
| Summen- und Saldenliste (SuSa) | `trial balance` | Konten mit EB-Wert, Verkehrszahlen Soll/Haben, Saldo |
| BWA | (keine eigene Op — Grundlage: `incomeStatement`) | betriebswirtschaftliche Auswertung (Monats-GuV + Kennzahlen); `incomeStatement` liefert die Grundlage, volle DATEV Form 01 = geplantes Mapping-Regelmodul (Lieferaufgabe, kein Modellthema) |
| Saldovortrag | `opening balance` | übernommener Anfangsbestand; Eröffnungsbuchung gegen `opening_balance`-Konto mit Pflichtbeleg |
| Ergebnisverwendung | `profit appropriation` | Buchung des Gewinnverwendungsbeschlusses (JÜ → Gewinnvortrag/Ausschüttung) über `result_allocation`-Konto |
| Geldtransit | `transit` (Konto-Subtype) | Umbuchung zwischen Geldkonten (Bank↔Kasse, PSP); EÜR-neutral |
| Lohnbuchungsbeleg | `payroll posting document` | Sammelbeleg der Lohnabrechnung; Verbuchung in Scope, Abrechnung nicht |
| Umsatzsteuer-Voranmeldung (UStVA) | `VAT return` | periodische USt-Meldung ans Finanzamt; zentrale Projektion `vatReturn` über das Journal |
| Zusammenfassende Meldung (ZM) | `EC sales list` | Meldung ig. Umsätze je USt-IdNr.; Projektion `ecSalesList` liefert die Grundlage |

## Architektur (Internationalisierung, benannt 2026-06-08)

> In einem Satz: **Substrat** = die jurisdiktionsfreie Buchungs-Algebra; **Politiksorte** = die Form, in der Variables andockt (Constraint/Projektion/Expansion); **Pack** = das ausgelieferte Bündel der Pack-Inhalte einer Jurisdiktion. „Regelmodul-Inhalt" und „Pack-Inhalt" sind dasselbe. Schichtenzuordnung: `40-domaenenmodell/jurisdiction-profil.md`.

| DE | EN (API) | Definition |
|---|---|---|
| Substrat | `substrate` | der jurisdiktionsfreie irreduzible Kern: Buchung summiert zu null, Konto akkumuliert, Journal append-only, Saldo = Faltung. Keine Steuer, kein Kontenrahmen, keine Rundung (NF-5.3) |
| Politiksorte | `policy kind` | jedes variable Feature ist genau eines: **Constraint** (Prädikat), **Projektion** (Journal → Sicht), **Expansion** (Absicht → Buchungen) |
| Sockel / Stecker | `mechanism / rule` | eine Expansion = gesetzesfreier Sockel (Mechanismus) + Stecker (Regel/Parameter); Steuer ist der prominenteste Stecker, nicht der einzige |
| Jurisdiction Profile / Pack | `pack` | benannte, aufgelöste Komposition von Modulen für eine Jurisdiktion. „tzdata fürs Rechnungswesen"; DE-complete = erstes *kuratiertes* Pack (nicht der einzige Weg). Siehe `40-domaenenmodell/jurisdiction-profil.md` |
| Modul | `module` | adressierbare Pack-Einheit, Granularität kohärenter Regelsatz (Kontenrahmen, Steuerschlüssel-Satz, ein Mapping, AfA-Regelsatz, Kalender, Rundungspolitik); deklariert Beitrag + Abhängigkeiten |
| Pack-Manifest / Resolver | `pack manifest` / `resolver` | Manifest = Modulliste eines Packs; Resolver prüft Abhängigkeiten + referentielle Integrität, scheitert laut (`E_PACK_*`) |

## EÜR-Kontext

| DE | EN (API) | Definition |
|---|---|---|
| Betriebseinnahme | `business income` | Zufluss in Geld/Geldeswert, betrieblich veranlasst |
| Betriebsausgabe | `business expense` | Abfluss, betrieblich veranlasst (§ 4 Abs. 4 EStG) |
| Zufluss/Abfluss | `cash receipt / cash payment` | maßgebliches Datum der EÜR (§ 11 EStG) |
| Durchlaufender Posten | `pass-through item` | in fremdem Namen vereinnahmt/verausgabt; erfolgsneutral |
| Anlageverzeichnis | `asset register` | Pflichtverzeichnis des Anlagevermögens (auch bei EÜR) |

## Doppik-HGB-Kontext

| DE | EN (API) | Definition |
|---|---|---|
| Aufwand | `expense` | periodisierter Werteverzehr (≠ Auszahlung, ≠ Kosten!) |
| Ertrag | `revenue` (i. w. S. `income`) | periodisierter Wertezuwachs |
| Bilanz | `balance sheet` | Stichtagsrechnung Aktiva = Passiva |
| GuV | `income statement` (P&L) | Periodenerfolgsrechnung |
| Rückstellung | `provision` | ungewisse Verbindlichkeit (Grund/Höhe/Zeitpunkt) |
| Rechnungsabgrenzung | `accrual / deferral` | RAP — periodengerechte Zuordnung über den Stichtag |
| Abschreibung (AfA) | `depreciation` | Verteilung von AK/HK über die Nutzungsdauer |
| Forderung / Verbindlichkeit | `receivable` / `payable` | offene Posten aus Lieferung/Leistung |
| Jahresabschluss | `annual financial statements` | Bilanz + GuV (+ Anhang, Lagebericht) |
| Ergebnis (HGB) | `net income` | Jahresüberschuss/-fehlbetrag — Saldo der GuV |

## Doppik-kommunal-Kontext

| DE | EN (API) | Definition |
|---|---|---|
| Ergebnisrechnung | `statement of operations` | Erträge/Aufwendungen → Ressourcenverbrauch. **Achtung:** „Ergebnis" hier ≠ „Ergebnis" HGB-Kontext (anderer Bezugsrahmen: Haushaltsausgleich) |
| Finanzrechnung | `cash flow statement` | Ein-/Auszahlungen als laufend *gebuchte* dritte Komponente |
| Vermögensrechnung | `statement of net assets` | kommunale Bilanz |
| Haushalt(splan) | `budget` | rechtsverbindlicher Plan (Satzung!) — nicht bloß Planungsinstrument |
| Produkt | `product` | Leistungseinheit der Verwaltung; Gliederungsdimension des Haushalts |
| Obligo / Mittelbindung | `encumbrance` | Budgetreservierung vor der Buchung |
| Deckungsfähigkeit | `budget transferability` | gegenseitige Verwendbarkeit von Budgetansätzen |
| Sonderposten | `special reserve item` | passivierte Zuwendung, parallel zur AfA aufgelöst |

## KLR-Kontext

| DE | EN (API) | Definition |
|---|---|---|
| Kosten | `costs` | betriebsbedingter bewerteter Werteverzehr — **≠ Aufwand** (neutraler Aufwand raus, kalkulatorische Kosten rein) |
| Leistung | `output` (vorläufig) | betriebsbedingte Wertentstehung — ≠ Ertrag |
| Kostenart | `cost type` | Was? — Gliederung der Kosten |
| Kostenstelle | `cost center` | Wo? — Ort der Entstehung |
| Kostenträger | `cost object` | Wofür? — Produkt/Auftrag |
| Einzel-/Gemeinkosten | `direct / indirect costs` | direkt zurechenbar ja/nein |
| variable/fixe Kosten | `variable / fixed costs` | beschäftigungsabhängig ja/nein |
| kalkulatorische Kosten | `imputed costs` | Kosten ohne (gleichhohen) Aufwand |
| BAB | `cost allocation sheet` | Betriebsabrechnungsbogen — Verteilungsrechnung |
| Umlage | `allocation` | innerbetriebliche Leistungsverrechnung |
| Deckungsbeitrag | `contribution margin` | Erlös − variable Kosten |
| Abgrenzungsrechnung | `reconciliation` (Fibu↔KLR) | Überleitung Aufwand→Kosten |

## Bekannte Begriffskonflikte (= BC-Grenzsignale)

- **„Ergebnis"**: HGB (Jahresüberschuss) vs. kommunal (ordentliches/außerordentliches Ergebnis mit Ausgleichspflicht) vs. KLR (Betriebsergebnis).
- **„Aufwand" vs. „Kosten" vs. „Ausgabe" vs. „Auszahlung"**: vier verschiedene Konzepte; in der EÜR fällt „Ausgabe" mit „Auszahlung" fast zusammen, in der Doppik nicht.
- **„Budget"**: Unternehmen (internes Planungsinstrument) vs. Kommune (Rechtsnorm mit Verfügbarkeitskontrolle).
- **„Konto"**: Fibu-Konto vs. Bankkonto vs. KLR nutzt gar keine Konten, sondern Dimensionen.
- **„Buchung"** in der EÜR: umgangssprachlich die Kategorisierung einer Zahlung — modellhaft etwas anderes als der doppische Buchungssatz (entschieden: EÜR ist eine Projektion über das doppische Journal, kein eigener Buchungsstil).
