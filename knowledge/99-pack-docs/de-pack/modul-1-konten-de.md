# Modul 1 — Konten DE (`accounts`)

```
kind: accounts · id: de-konten · version: 2026.6 · formatVersion: 0.6
contributes: ["accounts"] · dependsOn: []
data.accounts[]  (account-Objekte, § datenformat „account")
```

## Zweck

Der **vollständige, eigenständige** DE-Kontenrahmen. Keine Abhängigkeit nach außen — dieses
Modul liefert jedes Konto, das die DE-Steuer-, Mapping-, AfA- und Policy-Module referenzieren.
4-stellig, Kontenklasse = führende Ziffer (0–9), an SKR04-Gliederungslogik angelehnt, eigene
Nummern (keine SKR-Datenübernahme).

> Die 32 Basiskonten entsprechen dem abgenommenen Nummernsatz (vormals `summae-base`/`neutral`,
> Sign-off 2026-06-21 — Nummern quasi-irreversibel). Sie werden hier **DE-eigen** geführt, nicht
> als externe Abhängigkeit übernommen. Die 4–5 DE-spezifischen Konten sitzen in den
> Nummern-Lücken.

## Basiskonten (32, abgenommen)

| Nr | Name | type | subtype |
|---|---|---|---|
| 0100 | Anlagen | asset | fixed_asset |
| 1200 | Bank | asset | bank |
| 1210 | Kasse | asset | cash |
| 1300 | Geldtransit | asset | transit |
| 1400 | Forderungen aus Lieferungen und Leistungen | asset | ar |
| 1450 | Sonstige Forderungen | asset | — |
| 1500 | Vorsteuer Regelsatz (19 %) | asset | tax_in |
| 1510 | Vorsteuer ermäßigter Satz (7 %) | asset | tax_in |
| 2000 | Kapital | equity | — |
| 2100 | Gewinnvortrag | equity | — |
| 2200 | Saldenvorträge | equity | opening_balance |
| 2300 | Ergebnisverwendung | equity | result_allocation |
| 2400 | Privat | equity | private |
| 3000 | Verbindlichkeiten aus Lieferungen und Leistungen | liability | ap |
| 3100 | Umsatzsteuer Regelsatz (19 %) | liability | tax_out |
| 3110 | Umsatzsteuer ermäßigter Satz (7 %) | liability | tax_out |
| 3300 | Verbindlichkeiten Löhne und Gehälter | liability | — |
| 3310 | Verbindlichkeiten Lohnsteuer | liability | — |
| 3320 | Verbindlichkeiten soziale Sicherheit | liability | — |
| 3500 | Sonstige Verbindlichkeiten | liability | — |
| 3550 | Erhaltene Anzahlungen | liability | — |
| 4000 | Erlöse Regelsatz (19 %) | revenue | — |
| 4010 | Erlöse ermäßigter Satz (7 %) | revenue | — |
| 4900 | Erträge aus Anlagenabgang | revenue | — |
| 5000 | Wareneinsatz und Fremdleistungen | expense | — |
| 5010 | Erhaltene Skonti und Nachlässe (vorsteuerpflichtig) | expense | Aufwandsminderung; trägt die Vorsteuerkorrektur (Modul 12) |
| 6000 | Sonstiger betrieblicher Aufwand | expense | — |
| 6300 | Löhne und Gehälter | expense | — |
| 6310 | Soziale Aufwendungen (Arbeitgeberanteil) | expense | — |
| 6500 | Abschreibungen auf Anlagen | expense | — |
| 6510 | Sofortabschreibung geringwertiger Anlagegüter (GWG) | expense | — |
| 6700 | Forderungsverluste | expense | — |
| 6900 | Verluste aus Anlagenabgang | expense | — |

## DE-spezifische Konten (in den Lücken — Sign-off offen)

Diese Rollen hat der Basissatz bewusst nicht; die Fixtures belegen sie (mit SKR03-Nummern,
die hier auf neutrale Lücken-Nummern umgesetzt werden).

| Nr (Vorschlag) | Name | type | Warum eigenes Konto | Fixture-Beleg (SKR03) |
|---|---|---|---|---|
| 4020 | Gewährte Skonti / Erlösschmälerung (umsatzsteuerpflichtig) | revenue | §17-Korrektur, keine Heimat im Basissatz; seit 2026.4 **erzwingt** das Konto sie ([Modul 12](modul-12-constraint-entgeltminderung.md)) | `core/settlement-discount` (8731) |
| 4030 | Steuerfreie ig. Lieferungen (§4 Nr.1b) | revenue | steuerfrei, ≠ Regelerlös 4000 | `core/partner-and-ec-sales` (8125) |
| 6010 | Bewirtungskosten, abziehbar (70 %) | expense | §4 Abs.7 EStG: getrennte Aufzeichnung | `core/entertainment-split` (4650) |
| 6020 | Bewirtungskosten, nicht abziehbar (30 %) | expense | dito | `core/entertainment-split` (4654) |
| 4040 ⚠ | Unentgeltliche Wertabgaben | revenue | Wertabgabe-Ertrag ≠ Regelerlös; **vom Review übersehen** | `tax/non-cash-benefit` (8924) |

⚠ **4040 ist die offene fünfte Lücke.** Ohne eigenes Konto kollabiert der
Wertabgabe-Ertrag (USt19WA) still auf 4000. Entscheidung in `offene-entscheidungen.md`.

## Hinweise für den Build

- Bewirtung gehört trotz SKR03-„4xxx" in die **6xxx-Aufwandsklasse** (Basissatz folgt
  SKR04-Logik: 4xxx = Erlöse, 6xxx = Aufwand). Skonto (4020) und igL (4030) sind dagegen
  korrekt 4xxx (Erlös bzw. Erlösschmälerung).
- §13b-Fremdleistung braucht **kein** neues Konto → bucht auf **5000** (Wareneinsatz und
  Fremdleistungen). In `tax/reverse-charge` ist es nur lokal als „4900" geführt.
- Reverse-Charge- und Wertabgabe-**Steuerkonten**: siehe Modul 2 — die Frage „eigenes Konto
  vs. 3100/1500 + Kennzahl-Trennung" ist dort verortet.

## Fixture-Rollen-Abdeckung (verifiziert)

Payroll → 3300/3310/3320 + 6300/6310 · AfA/GWG → 6500/6510 · Anlagenabgang → 4900/6900 ·
Forderungsverlust → 6700 · Anzahlungen → 3550 · Ergebnisverwendung → 2300/3500.
