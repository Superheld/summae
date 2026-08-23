# Ledger — taktisches Kernmodell

**Kontext: Ledger (Core Domain).** Stand 2026-06-07, provisional aber präzise. Namen aus dem Glossar (EN = API-Name).

> **Schicht: Kern (Substrat + Mechanik), jurisdiktionsfrei.** DE-Beispiele (USt, GoBD-Festschreibung) illustrieren die Mechanik — Beispiel, nicht Festlegung. Schichtenzuordnung: `jurisdiction-profil.md`.

## Aggregate

Schnittkriterium: Was muss *synchron in einer Transaktion* konsistent sein? Nur das gehört in ein Aggregat. Referenzen zwischen Aggregaten ausschließlich per ID.

### 1. `JournalEntry` (Buchung) — das wichtigste Aggregat

- **Root:** JournalEntry. **Intern:** Positionen als Value Objects (`EntryLine`: Konto-Ref, Seite, Money, Dimensionen, Steuer-Tag). Positionen haben keine eigene Identität über die Buchung hinaus — referenziert wird per Buchungs-ID + Positionsindex.
- **Invarianten (beim Schreiben erzwungen):**
  - Σ Soll = Σ Haben (F-CORE-001)
  - ≥ 2 Positionen, jede mit gültigem Konto und Betrag > 0
  - genau eine Belegreferenz (`voucherId`) — F-CORE-003
  - Buchungsperiode ist offen (Prüfung beim Buchen, s. u.)
  - drei Daten verpflichtend: Belegdatum, Buchungsdatum, Erfassungszeitpunkt (GoBD)
- **Lebenszyklus:** `entered` (erfasst, korrigierbar mit Audit-Trail) → `finalized` (festgeschrieben, unveränderlich; danach nur `reverse`). Storno erzeugt eine *neue* Buchung mit Rückverweis (`reverses: entryId`), Generalumkehr.
- **Festschreibungs-Granularität (Entscheidung):** Zustand lebt **an der Buchung**; die *Massenoperation* „Periode festschreiben" (alle erfassten Buchungen bis Datum X) ist der übliche Auslöser (DATEV-Praxis, GoBD: spätestens mit USt-VA). Beides, sauber getrennt: Zustand einzeln, Auslöser wahlweise.

### 2. `Account` (Konto)

- Kontonummer, Name, **Kontotyp** (bestimmt Saldenmechanik: AKTIV/PASSIV/AUFWAND/ERTRAG + Spezialtypen BANK, AR/AP, TAX), Status (aktiv/gesperrt), Gültigkeitszeitraum.
- **Kein Saldo im Aggregat!** Salden sind Projektionen des Journals. Ein gespeicherter Saldo wäre eine zweite Wahrheit neben dem Journal — verboten.
- Eindeutigkeit der Kontonummer je Mandant: über die Aggregatgrenze hinaus → als Repository-Kontrakt zugesichert (dokumentierte Assertion), nicht durch ein Riesen-Aggregat „Kontenplan enthält alle Konten".

### 3. `FiscalYear` (Geschäftsjahr) mit Perioden

- **Root:** FiscalYear; **intern:** `Period`-Entities (lückenlos, nicht überlappend — das ist die Invariante, die sie ins selbe Aggregat zwingt).
- Periodenstatus: `open` → `closed`. Schließen nur in Reihenfolge; Wiedereröffnen nur, solange das Geschäftsjahr nicht abgeschlossen ist (Audit-Trail).

### 4. `Voucher` (Beleg)

- Belegnummer, Belegdatum, Aussteller, Betrag, Dateireferenzen (Storage ist App-Sache, wir halten Referenz + Hash), **Metadaten für Projektionen:** `due` (Fälligkeit), `recurring`, `economicYear` (F-CORE-010).
- Eigenes Aggregat, weil Belege vor/ohne Buchung existieren (Belegeingang) und mehrere Buchungen einen Beleg referenzieren können (Rechnung + Zahlung + Storno).

### 5. `OpenItem` (Offener Posten)

- Entsteht aus einer Buchung auf ein AR/AP-Konto (Forderung/Verbindlichkeit); referenziert Ursprungsbuchung + Position.
- **Invariante:** Σ Ausgleiche ≤ Betrag; Teilausgleiche erlaubt (Testsuite-Fall aus dem EÜR-Beweis); jeder Ausgleich referenziert die ausgleichende Buchung.
- Trägt die OP-Verknüpfung, die die EÜR-Projektion braucht (F-CORE-009): Zahlung → OpenItem → Ursprungsbuchung → Kategorie.

## Value Objects (immutabel, mit Verhalten)

| VO | Inhalt | Verhalten (Closure of Operations wo möglich) |
|---|---|---|
| `Money` | Dezimalbetrag + Währung | add/subtract/negate (Money→Money), allocate (Rundungsverteilung!), Vergleich. Währungsmix wirft Fehler |
| `EntryLine` | accountRef, side, money, dimensions[], taxTag? | balanced-Prüfung auf Listen von Lines |
| `DimensionValue` | dimensionType (frei definierbar) + code | — (Entscheidung: Typen sind Stammdaten, nicht Schema — Kommune/KLR erweitern ohne Formatänderung) |
| `AccountNumber`, `VoucherRef`, `PeriodRef` (Jahr+Nr), `DocumentRef` (URI+Hash) | Identitäts-/Referenztypen | Validierung, Formatierung |

## Domain Events (Vergangenheitsform, Ubiquitous Language)

`EntryPosted` · `EntryCorrected` (nur im Status entered, mit Vorher/Nachher) · `EntryFinalized` · `EntryReversed` · `PeriodClosed` · `PeriodReopened` · `FiscalYearClosed` · `OpenItemSettled` (auch teilweise) · `AccountLocked`

`EntryPosted` ist *das* Integrationsereignis: Tax (VA-Projektion), Costing (Primärkostenübernahme), später Budgeting (Fortschreibung) konsumieren es über die Published Language. Das Journal selbst ist fachlich die Event-Historie — die Events sind keine zweite Buchhaltung, sondern die Benachrichtigung darüber.

## Domain Services (nur was kein Aggregat besitzt)

- **`post`** (buchen): prüft Periodenstatus (FiscalYear) + appendiert die Buchung (JournalEntry) + vergibt die lückenlose Journalnummer. Berührt zwei Aggregate → Domain Service. Die Buchung selbst entsteht *vollständig und gültig* (Factory-Charakter), nie teilkonstruiert.
- **`reverse`** (stornieren): erzeugt Gegenbuchung mit Rückverweis, markiert Original.
- **`settle`** (ausgleichen): ordnet Zahlungsbuchung einem/mehreren OpenItems zu (proportionale Aufteilung bei Teilzahlung).
- **`closePeriod`**: Reihenfolgeprüfung, danach `PeriodClosed`.

Bewusst **nicht** als Service: Validierung der Buchungsinvarianten (gehört in JournalEntry selbst), Saldenberechnung (Projektion), Steuerexpansion (Tax-Kontext).

## Transaktions- und Konsistenzregeln

- Eine Transaktion = ein Aggregat. Ausnahme nach Abwägung: `post` schreibt JournalEntry und liest Periodenstatus konsistent (Periodensperre als Prüfung-beim-Schreiben; `closePeriod` synchronisiert über die Journal-Sequenz).
- Alles Abgeleitete (Salden, Hauptbuch, SuSa, Bilanz, EÜR) ist **Projektion** — neu berechenbar, nie Quelle.
- Projektionen sind deterministisch inkl. Rundung/Sortierung (NF-2.3) und tragen ein Bezugsdatum (NF-5.1).

## Taktische Fragen — entschieden (2026-06-07, Details im Entscheidungslog)

1. Journalnummer: **je Geschäftsjahr** lückenlos (DATEV-Praxis).
2. Korrektur im Status `entered`: **nur `correct` mit Audit-Trail, kein Löschen** (konservativ GoBD-sicher).
3. Mehrwährung: **v2** — Format reserviert Kursfelder, v1 prüft Mandantenwährung (`E_ENTRY_INVALID_AMOUNT`).
4. Dimensions-Validierung: Mechanik im Kern, Inhalte als Regelmodul-`dimensionRules` (Fixture edge-errors).
5. Bewertungsbereiche: v1 **einer**; `valuationArea` an der Position reserviert.
