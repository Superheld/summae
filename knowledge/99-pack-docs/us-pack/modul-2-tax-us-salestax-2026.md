# Modul 2 — Sales & Use Tax USA 2026 (`tax`)

```
kind: tax · id: us-salestax-2026 · version: 2026.2 · formatVersion: 0.6
contributes: ["taxCodes"] · dependsOn: [{kind: accounts, id: us-accounts-2026}]
data.taxCodes[]  (TaxCode = {code, versions[]}; tax-modell.md §1)
```

## Zweck

Die US-Steuerschlüssel als versionierte Regeldaten. Anders als die deutsche USt (mehrstufig,
mit Vorsteuerabzug) ist die US **Sales Tax einstufig** — fällig nur beim Verkauf an den
Endverbraucher, **kein Input-Tax-Credit** über die Handelskette. Daraus folgt der ganze
Modul-Aufbau. `mechanism` ist ein offener String; die Engine kennt real `standard`,
`reverse_charge`, `intra_community_supply` — wir bilden die US-Codes auf `standard` und
(umgewidmet) `reverse_charge` ab, **ohne neuen Engine-Mechanismus**.

## Die fundamentale Abweichung zu DE

| Aspekt | Deutschland (USt) | USA (Sales/Use Tax) |
|---|---|---|
| Stufen | mehrstufig (Mehrwertsteuer) | **einstufig** (Retail) |
| Vorsteuerabzug | ja (VSt 1500/1510) | **nein** — Resale-/Exemption-Certificate stattdessen |
| Erhebungsebene | Bund, ein Satz­system | **Bundesstaaten + lokale** Jurisdiktionen (tausende Sätze) |
| Schwelle | Kleinunternehmer §19 | **Economic Nexus** (Wayfair, ~100.000 USD/Staat) |
| Selbstveranlagung Einkauf | §13b (erstattungsfähig, netto 0) | **Use Tax** (nicht erstattungsfähig → Kosten) |

## TaxCode-Version — Felder (aus datenformat.md)

`validFrom`, `validTo` (nullbar), `rate`, `taxAccount`, `reportingKey` (nullbar),
`mechanism` (Default `standard`), `inputTaxAccount`, `inputReportingKey`,
`baseReportingKey` (alle nullbar).

## Die Codes

| Code | Satz | mechanism | taxAccount | reportingKey | input-Konto / Kz | base-Kz |
|---|---|---|---|---|---|---|
| **SALETAX** | 7,00 ⚠ Platzhalter | standard | 3130 | `TAXABLE_SALES` | — | — |
| **USETAX** | 7,00 ⚠ Platzhalter | reverse_charge *(umgewidmet)* | 3140 | `USE_TAX_DUE` | 6020 / `USE_TAX_EXPENSE` | `PURCHASES_SUBJECT_TO_USE_TAX` |
| **EXEMPT** | 0,00 | standard | 3130 | `EXEMPT_SALES` | — | — |

### SALETAX — erhobene Sales Tax (Ausgangsumsatz)

`standard`-Mechanismus, output: Verkauf bucht die Steuer als **Verbindlichkeit** auf **3130
Sales Tax Payable** (credit). Brutto = Netto + Steuer; Zahllast an den Staat = gesammelte 3130.
Direkt analog zu DE `USt19`, nur dass die Gegenstelle eine reine Verbindlichkeit ohne
Vorsteuer-Pendant ist.

### USETAX — selbst veranlagte Use Tax (Eingangsumsatz) — der Kniff

Kauft ein Unternehmen steuerpflichtige Güter aus einem anderen Staat **ohne** berechnete Sales
Tax, schuldet es dem eigenen Staat die spiegelbildliche **Use Tax** — selbst veranlagt und
**nicht erstattungsfähig**.

> **Wir nutzen denselben `reverse_charge`-Mechanismus wie der deutsche §13b — aber das
> Input-Bein zeigt auf ein Aufwandskonto (6020) statt auf ein erstattungsfähiges
> Vorsteuerkonto.** Beim §13b heben sich USt (credit) und VSt (debit) zu null auf
> (erstattungsfähig). Hier wird die Steuer korrekt zu **Kosten + Verbindlichkeit**:
>
> ```
> Use Tax Expense (6020)      Soll   8,00     ← inputTaxAccount
>   an Use Tax Payable (3140) Haben  8,00     ← taxAccount
> ```
>
> Gleiche Engine-Mechanik, nur andere Konten-Verdrahtung — kein neuer Mechanismus nötig.
> Zahlbetrag an den Lieferanten bleibt Netto (er hat keine Steuer berechnet), die Use Tax geht
> separat an den Staat. **Sign-off** zu dieser Modellierung → `offene-entscheidungen.md`, Punkt B.

### EXEMPT — steuerfreie Umsätze (Resale / Interstate / Nontaxable)

`standard`, Satz `0,00`. Für Umsätze mit Resale-/Exemption-Certificate, steuerbefreite Käufer
oder Lieferungen in Staaten ohne Nexus. Erlös bucht auf **4040 Exempt Sales**; das Basis-Tag
(`EXEMPT_SALES`) hält die Bemessungsgrundlage für die Erklärung fest (steuerfreie Umsätze sind
auf der Sales-Tax-Erklärung als Abzug zu melden).

> ⚠ **Nullzeile:** Der `standard`-Pfad erzeugt bei Satz 0 eine **0,00-Steuerzeile** (plus
> Basis-Tag). Das DE-Pack vermeidet das bei der igL über einen eigenen Mechanismus
> (`intra_community_supply`, nur Basis taggen). Ein analoger `exempt`-Mechanismus wäre eine
> kleine Engine-Ergänzung → `offene-entscheidungen.md`, Punkt E.

## Sätze sind Platzhalter (generisches Einzel-Regime)

⚠ Die `7,00` sind **Platzhalter**. Die USA haben keinen einheitlichen Satz: 45 Staaten + DC +
tausende lokale Jurisdiktionen; kombinierte Sätze 2026 von 0 % (NOMAD-Staaten: NH, OR, MT, AK,
DE) bis 10,11 % (Louisiana). Dieses Pack trägt **einen konfigurierbaren Satz**, je
Mandant/Staat überschreibbar (sauber wie das DE-Pack ein Satz­system trägt). Staatsgenaue Sätze
sind Mandantenkonfiguration bzw. eine spätere Rate-Engine → `offene-entscheidungen.md`, Punkt D.

## Versionierung / Rechtsstand

- `validFrom` der Platzhalter-Sätze: `2024-01-01`, `validTo: null`.
- Satzwechsel = neue `version` im `versions[]`-Array (nie still überschreiben). Anzuwendende
  Version folgt dem Leistungs-/Belegdatum.
- **Economic Nexus (Wayfair, 2018):** kein eigener Code — die Erhebungspflicht je Staat ist
  Profil-/`smallBusiness`-Sache, nicht ein Steuerschlüssel. Schwelle i. d. R. 100.000 USD
  Umsatz/Staat (Transaktionszähler wird zunehmend gestrichen, z. B. AK, UT).
