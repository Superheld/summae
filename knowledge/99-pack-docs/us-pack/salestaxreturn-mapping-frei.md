# Sales-Tax-Erklärung — bewusst **kein** Modul (mit US-Vorbehalt)

Die Sales-Tax-Erklärung ist **mapping-frei** und steht deshalb nicht in der
`us-complete`-Modulliste — analog zur deutschen USt-VA.

## Warum (wie bei DE)

Die Meldegröße **ist** bereits der `reportingKey` einer `TaxCodeVersion` (Modul 2). Eine
`salesTaxReturn`-Projektion liest die `taxTag`s der Buchungen, gruppiert nach `reportingKey`
und summiert — sie rechnet **nicht neu** und braucht **keine** Konto→Position-Zuordnung. Das
tax-Modul trägt die Information vollständig; ein Mapping wäre Doppelung.

## Kennzahlen, die das US-Pack heute erzeugt

`TAXABLE_SALES` (steuerpflichtiger Umsatz + erhobene Steuer, SALETAX) · `EXEMPT_SALES`
(steuerfreie Umsätze als Abzug) · `USE_TAX_DUE` / `USE_TAX_EXPENSE` /
`PURCHASES_SUBJECT_TO_USE_TAX` (selbst veranlagte Use Tax). Beschreibende Schlüssel statt
amtlicher Nummern (s. u.).

## US-Vorbehalt — wo der einfache DE-Fall **nicht** trägt

Anders als Deutschland mit **einer** bundeseinheitlichen UStVA-Kennzahlenliste hat in den USA
**jeder Staat sein eigenes Formular** mit eigenen Zeilen, eigenen Abzügen (z. B. lokale
Aufteilung, Bracket-Schedules) und teils Pflicht zur **jurisdiktionsweisen** Aufschlüsselung
(Bundesstaat + County + City). Daraus folgt:

- Die `reportingKey`s hier sind **generisch/beschreibend** (`TAXABLE_SALES` statt „Line 1") —
  sie tragen die Semantik, nicht das Format eines bestimmten Staates.
- Eine **staatsgenaue** Erklärung (Mapping Kennzahl→Formularzeile je Staat) wäre ein **späteres,
  staatsspezifisches** Mapping-Modul — der Punkt, an dem das US-Modell über den DE-Fall
  hinauswachsen muss. → `offene-entscheidungen.md`, Punkt F.
- Die **jurisdiktionsweise** Aufschlüsselung würde zusätzlich eine Dimension am `taxTag`
  (Bundesstaat/Locality) verlangen — heute nicht im Einzel-Regime abgebildet (Punkt D).

## Abgrenzung

Der im Schema vorhandene `vat-report`-Mapping-`kind` bleibt für den US-Fall unbenutzt;
reserviert für eine spätere staatsgenaue Sales-Tax-Return- bzw. Jahres-Reconciliation-Projektion.
