# USt-Voranmeldung — bewusst **kein** Modul

Die USt-VA ist **mapping-frei**. Sie braucht kein eigenes Modul und steht deshalb nicht in
der `de-complete`-Modulliste.

## Warum

Die VA-Kennzahl **ist** bereits der `reportingKey` einer `TaxCodeVersion` (Modul 2). Die
Projektion `vatReturn` liest die `taxTag`s der Buchungen, gruppiert nach `reportingKey` und
summiert — sie rechnet **nicht neu** und braucht **keine** Konto→Position-Zuordnung. Damit
trägt das tax-Modul die VA-Information vollständig; ein Mapping wäre Doppelung.

Beleg: `tax/vat-return.json` — die Projektion erzeugt aus den Kennzahlen 81/66 direkt
`{ "81": {base, tax}, "66": {tax} }` und den Zahllast-`payload`.

## Abgrenzung

- Der im Schema (`format.schema.json`) vorhandene `vat-report`-Mapping-`kind` ist für die VA
  **unbenutzt** — reserviert für die spätere USt-**Jahres**erklärung (eigene, nicht-gefloorte
  Basis-Ermittlung; RQ-2). Erst bauen, wenn eine Jahreserklärungs-Projektion kommt.
- Die **Volle-Euro-Abrundung** der Bemessungsgrundlage (amtliche VA-Konvention) ist
  Projektions-/Mapping-Konvention, **nicht** vom `roundingMode` der Policy berührt. Richtung
  (echtes Abrunden, nicht kaufmännisch) ist fachlich noch zu bestätigen → RQ-2
  (`offene-entscheidungen.md`).

## Kennzahlen, die das DE-Pack heute erzeugt

81 (Erlöse 19 %, Basis+Steuer) · 86 (Erlöse 7 %) · 66 (abziehbare Vorsteuer) · 47/67/46
(§13b: USt/Vorsteuer/Basis) · 41 (steuerfreie ig. Lieferung). Alle aus Modul 2, gegen die
Fixtures verifiziert.
