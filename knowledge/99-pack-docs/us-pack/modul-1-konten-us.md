# Modul 1 — Konten US (`accounts`)

```
kind: accounts · id: us-accounts-2026 · version: 2026.2 · formatVersion: 0.6
contributes: ["accounts"] · dependsOn: []
data.accounts[]  (account-Objekte, § datenformat „account")
```

## Zweck

Der **vollständige, eigenständige** US-Kontenrahmen. Keine Abhängigkeit nach außen — dieses
Modul liefert jedes Konto, das die US-Steuer-, Mapping-, Depreciation- und Policy-Module
referenzieren. 4-stellig, Kontenklasse = führende Ziffer (0–9), gleiche Nummernlogik wie das
DE-Pack, englische Bezeichnungen. **Kein gesetzlicher US-Kontenrahmen** (US-GAAP lässt ihn frei)
— wir wählen die Gliederung selbst.

> Die 32 Basiskonten sind derselbe abgenommene Nummernsatz wie beim DE-Pack (Sign-off
> 2026-06-21 — Nummern quasi-irreversibel), hier **US-eigen** geführt (nicht als externe
> Abhängigkeit) und auf Englisch benannt. Die 8 US-spezifischen Konten sitzen in den
> Nummern-Lücken.

## Basiskonten (32, abgenommener Nummernsatz, englisch benannt)

| Nr | Name (US) | type | subtype |
|---|---|---|---|
| 0100 | Property, Plant and Equipment | asset | fixed_asset |
| 1200 | Bank / Checking | asset | bank |
| 1210 | Cash on Hand | asset | cash |
| 1300 | Cash in Transit | asset | transit |
| 1400 | Accounts Receivable | asset | ar |
| 1450 | Other Receivables | asset | — |
| 1500 | Recoverable Tax — standard *(inherited, dormant in US)* | asset | tax_in |
| 1510 | Recoverable Tax — reduced *(inherited, dormant in US)* | asset | tax_in |
| 2000 | Owner's Capital / Common Stock | equity | — |
| 2100 | Retained Earnings | equity | — |
| 2200 | Opening Balances | equity | opening_balance |
| 2300 | Income Summary / Result Allocation | equity | result_allocation |
| 2400 | Owner's Draw / Distributions | equity | private |
| 3000 | Accounts Payable | liability | ap |
| 3100 | Sales Tax Output — standard | liability | tax_out |
| 3110 | Sales Tax Output — reduced | liability | tax_out |
| 3300 | Wages and Salaries Payable | liability | — |
| 3310 | Payroll Tax Withheld Payable | liability | — |
| 3320 | Payroll Taxes Payable (employer) | liability | — |
| 3500 | Other Liabilities | liability | — |
| 3550 | Customer Deposits / Advances | liability | — |
| 4000 | Sales Revenue — standard-rated | revenue | — |
| 4010 | Sales Revenue — reduced-rated | revenue | — |
| 4900 | Gain on Disposal of Assets | revenue | — |
| 5000 | Cost of Goods Sold / Subcontractors | expense | — |
| 6000 | Other Operating Expense (SG&A) | expense | — |
| 6300 | Wages and Salaries | expense | — |
| 6310 | Payroll Taxes / Benefits (employer) | expense | — |
| 6500 | Depreciation Expense | expense | — |
| 6510 | Immediate Expense — low-value / de minimis | expense | — |
| 6700 | Bad Debt Expense | expense | — |
| 6900 | Loss on Disposal of Assets | expense | — |

> **1500/1510 (tax_in)** sind aus dem geteilten Nummernsatz geerbt, im US-Standardregime aber
> **funktionslos**: Die Sales Tax ist einstufig, ohne Vorsteuerabzug. Entscheidung „behalten,
> umwidmen oder streichen" → `offene-entscheidungen.md`, Punkt G.
> **3100/3110 / 4000/4010** dienen Standard- und reduziertem Sales-Tax-Satz (viele Staaten haben
> reduzierte Sätze, z. B. auf Lebensmittel).

## US-spezifische Konten (in den Lücken — Sign-off offen)

Diese Rollen hat der Basissatz nicht; sie ergeben sich aus den US-Normen (Sales/Use Tax,
GAAP-Periodenabgrenzung, Contra-Revenue).

| Nr (Vorschlag) | Name | type | Warum eigenes Konto |
|---|---|---|---|
| 1900 | Prepaid Expenses | asset | GAAP-Periodenabgrenzung (Aktiv), keine Heimat im Basissatz |
| 3130 | Sales Tax Payable | liability | erhobene, abzuführende Sales Tax (≠ geerbtes „tax_out") — eigenes, englisch benanntes Konto |
| 3140 | Use Tax Payable | liability | selbst veranlagte, nicht erstattungsfähige Use Tax |
| 3900 | Deferred Revenue (Unearned Revenue) | liability | GAAP-Periodenabgrenzung (Passiv) |
| 4020 | Sales Returns and Allowances | revenue | Contra-Revenue (Retouren/Gutschriften), mindert Net Sales |
| 4030 | Sales Discounts | revenue | Contra-Revenue (Skonti/Rabatte) |
| 4040 | Exempt Sales (resale / interstate / nontaxable) | revenue | steuerfreie Umsätze getrennt vom steuerpflichtigen 4000 |
| 6020 | Use Tax Expense | expense | Aufwandsseite der selbst veranlagten Use Tax (s. Modul 2) |

> **Designentscheidung Sales Tax Payable (3130) statt geerbtem 3100:** Sales Tax ist
> konzeptionell **keine** USt — sie ist einstufig und ohne Vorsteuerabzug. Ein eigenes,
> englisch benanntes Konto ist sauberer als das geerbte „tax_out 3100". Alternative (3100
> wiederverwenden) → `offene-entscheidungen.md`, Punkt A.

## Hinweise für den Build

- **Contra-Revenue (4020/4030)** sind `type: revenue` mit typischerweise Soll-Saldo; das
  Income-Statement-Mapping (Modul 4) netzt sie über den Bereich 4000–4099 zu **Net Sales**.
- **Use Tax** braucht zwei Konten: Verbindlichkeit **3140** (credit) + Aufwand **6020**
  (debit) — die Mechanik dazu in Modul 2 (`reverse_charge`-Verdrahtung).
- **Sales-Tax-Steuerkonten:** SALETAX bucht auf **3130**, EXEMPT (Satz 0) ebenfalls auf 3130
  (nur Basis-Tag). USETAX: Verbindlichkeit 3140, Aufwand 6020. Siehe Modul 2.

## Konto-Rollen-Abdeckung

Payroll → 3300/3310/3320 + 6300/6310 · Depreciation/de minimis → 6500/6510 · Disposal →
4900/6900 · Bad debt → 6700 · Customer deposits → 3550 · Income summary → 2300/3500 ·
GAAP-Abgrenzung → 1900/3900.
