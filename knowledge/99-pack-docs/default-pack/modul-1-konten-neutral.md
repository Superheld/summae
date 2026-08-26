# Modul 1 — Neutraler Kontenrahmen (`accounts`)

```
kind: accounts · id: neutral · version: 2026.1 · formatVersion: 0.6
contributes: ["accounts"] · dependsOn: []
data.accounts[] (32 Konten, § datenformat „account")
```

## Zweck

Der **jurisdiktionsfreie** Kontenrahmen des `default`-Packs: die Kontoarten, die jede doppelte
Buchführung braucht, ohne eine Rechtsordnung zu behaupten. Er ist zugleich die **Vorlage zum
Abkupfern**, wenn ein neues Pack entsteht — `de` und `us` sind beide aus diesem Muster gebaut und
danach eigenständig geworden (Packs bauen nicht aufeinander auf).

Die Nummern folgen dem neutralen Rahmen: 1xxx Vermögen, 2xxx Eigenkapital, 3xxx Verbindlichkeiten,
4xxx Erträge, 6xxx Aufwendungen. Dass das *zufällig* wie SKR aussieht, ist keine Aussage — es ist
eine Ordnung, keine Vorschrift.

## Konten

**asset**

| Nr. | Name | subtype |
|---|---|---|
| 0100 | Anlagen | fixed_asset |
| 1200 | Bank | bank |
| 1210 | Kasse | cash |
| 1300 | Geldtransit | transit |
| 1400 | Forderungen aus Lieferungen und Leistungen | ar |
| 1450 | Sonstige Forderungen | — |
| 1500 | Vorsteuer Regelsatz | tax_in |
| 1510 | Vorsteuer ermäßigter Satz | tax_in |

**liability**

| Nr. | Name | subtype |
|---|---|---|
| 3000 | Verbindlichkeiten aus Lieferungen und Leistungen | ap |
| 3100 | Umsatzsteuer Regelsatz | tax_out |
| 3110 | Umsatzsteuer ermäßigter Satz | tax_out |
| 3300 | Verbindlichkeiten Löhne und Gehälter | — |
| 3310 | Verbindlichkeiten Lohnsteuer | — |
| 3320 | Verbindlichkeiten soziale Sicherheit | — |
| 3500 | Sonstige Verbindlichkeiten | — |
| 3550 | Erhaltene Anzahlungen | — |

**equity**

| Nr. | Name | subtype |
|---|---|---|
| 2000 | Kapital | — |
| 2100 | Gewinnvortrag | — |
| 2200 | Saldenvorträge | opening_balance |
| 2300 | Ergebnisverwendung | result_allocation |
| 2400 | Privat | private |

**revenue**

| Nr. | Name | subtype |
|---|---|---|
| 4000 | Erlöse Regelsatz | — |
| 4010 | Erlöse ermäßigter Satz | — |
| 4900 | Erträge aus Anlagenabgang | — |

**expense**

| Nr. | Name | subtype |
|---|---|---|
| 5000 | Wareneinsatz und Fremdleistungen | — |
| 6000 | Sonstiger betrieblicher Aufwand | — |
| 6300 | Löhne und Gehälter | — |
| 6310 | Soziale Aufwendungen (Arbeitgeberanteil) | — |
| 6500 | Abschreibungen auf Anlagen | — |
| 6510 | Sofortabschreibung geringwertiger Anlagegüter | — |
| 6700 | Forderungsverluste | — |
| 6900 | Verluste aus Anlagenabgang | — |

> **Das `default`-Pack liefert kein Mapping** — weder Bilanz noch GuV noch EÜR. Ein
> jurisdiktionsfreier Kontenrahmen hat keine rechtmäßige Gliederung, die er mitliefern könnte, also
> behauptet er keine. `balanceSheet` und `incomeStatement` verlangen `mapping`; auf einem
> `default`-Mandanten muss die Einbettung erst eins mit `importMapping` laden. Offen als IMPL-032:
> heute erfährt der Aufrufer das über einen Parameterfehler statt über eine Aussage.
