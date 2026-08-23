# Determinismus-Anhang v0.5

Gleiche Eingabe → byte-identisches Ergebnis (nach Kanonisierung) in allen Implementierungen (NF-2.3). Die hier definierten Regeln sind die häufigste Quelle von Cross-Implementierungs-Abweichungen — jede Regel bekommt Rundungs-/Sortier-Fixtures.

> **Mechanik global, Politik im Pack (Attribution, 2026-06-08).** Der *Determinismus selbst* — exakte Dezimalarithmetik, definierte Rundungs- und Sortier-Mechanik, byte-Gleichheit — ist global garantiert und nicht verhandelbar. Die konkreten *Politikwerte* unten (Rundungsmodus, Steuer-Granularität, Währungsskala) sind dagegen **Pack-Parameter** (siehe `40-domaenenmodell/jurisdiction-profil.md`); hier stehen sie mit den **Werten des DE-Packs** als Beispiel. Andere Jurisdiktionen setzen andere Werte ein, ohne die Mechanik zu berühren. Heute ist nur DE belegt, daher keine eigenen Felder im Format — das ist die nachfragegetriebene Stufe (siehe `offene-fragen.md`). **Diese Klarstellung ändert kein Verhalten und keine Fixture.**

## 1. Arithmetik

- Alle Geldrechnung in **exakter Dezimalarithmetik** (PHP: BCMath/brick-money; Node: big.js o. ä. — nie `number`; Python: `decimal.Decimal`). Zwischenergebnisse werden NICHT gerundet, außer eine Regel verlangt es ausdrücklich.
- Skala: Zwischenwerte mind. 6 Nachkommastellen führen; Endwerte auf die **Währungsskala** runden — diese ist eine Eigenschaft der Währung (ISO-4217-Exponent: EUR 2, JPY 0, BHD 3), kein globaler Festwert. DE-Pack arbeitet in EUR (2).

## 2. Rundungsverfahren

- **Rundungsmodus (DE-Pack-Wert: kaufmännisch half-up, von Null weg bei genau .5)** — `2.345 → 2.35`, `-2.345 → -2.35`; abweichend von IEEE „banker's rounding" (half-even), das viele Sprachbibliotheken als Default haben — **explizit konfigurieren, Fixture prüft genau diesen Unterschied** (`1.225 → 1.23`, half-even ergäbe fälschlich 1.22). Der Modus ist ein **Pack-Parameter** (`roundingMode`); dass *ein* Modus deterministisch gilt, ist global, *welcher* ist Pack-Sache.
- **Steuer-Granularität (DE-Pack-Wert: pro Beleg, nicht pro Position)** (UStAE zu § 14): Netto-Summe je Steuersatz bilden → Steuer berechnen → einmal runden. Positionsweise Rundung mit Summen-Differenz ist in DE nicht konform — andere Jurisdiktionen runden je Position; daher **Pack-Parameter** (`taxRoundingGranularity`: perVoucher | perLine).
- **Verteilungsrundung (`allocate`):** Wenn ein Betrag auf n Teile verteilt wird (Umlagen, Sammelposten-Fünftel, AfA-Monatsraten): Teile einzeln auf Skala runden, Differenz zur Ausgangssumme als **Restverteilung nach größtem Rest** (largest remainder), bei Gleichstand an den **ersten Teil in stabiler Reihenfolge**. Invariante: Σ Teile = Ausgangsbetrag, immer. (Die `allocate`-*Mechanik* ist global; der Rundungsmodus *darin* folgt dem Pack-Parameter.)
- **AfA:** Jahresbetrag = AHK / Nutzungsdauer (Monate genau, pro rata); Monats-/Jahreswerte per `allocate` über die Laufzeit — kein „Restwert-Rest" im letzten Jahr durch naive Rundung.

## 3. Sortierung

- **Journal:** `sequenceNumber` aufsteigend — die einzige autoritative Ordnung. `entryDate` ist fachlich, nicht ordnend.
- **Projektionszeilen:** primär Kontonummer als String-Vergleich **nach Unicode-Codepoints** (keine Locale-Collation! `"10" < "9"` ist gewollt, führende Nullen sind signifikant), sekundär sequenceNumber.
- **OP-Listen:** voucherDate, dann sequenceNumber. **Ausgleichsreihenfolge bei Sammellzahlung ohne explizite Zuordnung: gibt es nicht** — Zuordnung ist immer explizit (API erzwingt `settle`-Zuordnungen; kein FIFO-Automatismus im Kern, Auto-Zuordnungs-Vorschläge sind App-Sache).
- **JSON-Ausgabe:** kanonisch nach RFC 8785 (Schlüssel sortiert, definierte Zahlen-/String-Repräsentation), wenn gehasht oder verglichen wird.

## 4. Zeit und Zeitzonen

- Fachliche Daten (Belegdatum, Buchungsdatum) sind **zonenlose Kalenderdaten** — kein UTC-Shift-Risiko.
- **Moment-Zeitstempel (`recordedAt`, Audit-`at`, `exportedAt`) — kanonisches Format (SPEC-C01, 2026-06-20):** serialisiert als **RFC 3339 in UTC, mit fester Millisekunden-Stelle und `Z`** — z. B. `2026-06-07T10:00:00.000Z` (byte-identisch zu JS `Date.toISOString`). **Kein** erhaltener Zonen-Offset, **keine** variable Sekundenbruch-Stelle. Begründung: Diese Felder fließen als Roh-Bytes in die `manifest.contentHashes` (SHA-256 je Strom) — nur *eine* Schreibweise macht die Hashes über Runtimes hinweg byte-identisch (sonst divergiert Node↔PHP trotz gleichen Moments). Vergleiche bleiben auf Instant-Basis; neu ist, dass die **Schreibweise selbst Vertrag** ist, nicht Konvention. Referenz-Helfer: PHP `Summae\Core\Shared\Timestamp::canonical()`, Node erzeugt es nativ.
- Periodenzuordnung ausschließlich über Buchungsdatum vs. Periodengrenzen (Datumsvergleich, keine Zeit).

## 5. Identität und Hashing

- UUIDv7 erzeugen die Implementierungen selbst; Fixtures vergleichen nie ID-Werte, nur Platzhalter-Gleichheit (`"$E1"` referenziert dieselbe ID über Steps hinweg).
- Manifest-Hashes: SHA-256 über RFC-8785-kanonisiertes JSON, Ströme zeilenweise.

## v0.3-Ergänzungen

- **Teilzahlung bei Ist-Versteuerung:** Bemessungsgrundlage und USt werden **anteilig im Verhältnis Zahlbetrag/OP-Betrag** dem VA-Zeitraum der Zahlung zugeordnet; Rundung der anteiligen Steuer half-up auf Cent, **Schlusszahlung erhält den Rest** (Σ Anteile = Gesamtsteuer, exakt — gleiche Logik wie `allocate`).
- **USt-VA-Darstellung:** Bemessungsgrundlagen je Kennzahl auf volle Euro **abgerundet** (amtliche Konvention), Steuerbeträge centgenau. Die Abrundung passiert auf der **Kennzahlen-Summe**, nicht je Buchung.

## Fixture-Pflichtfälle (→ `testing/testsuite/fixtures/determinism/`)

1. half-up vs. half-even Falle: `1.225 → 1.23`
2. USt pro Beleg: 3 Positionen à 0,33 € netto, 19 % — pro Beleg 0,19 €, positionsweise wäre 0,18 €
3. allocate: 100,00 € auf 3 Teile → 33,34 / 33,33 / 33,33 (largest remainder, Gleichstand → erster)
4. AfA-Verteilung über 36 Monate ohne Restfehler
5. Kontonummern-Sortierung mit führenden Nullen ("0420" < "1200" < "8400")
