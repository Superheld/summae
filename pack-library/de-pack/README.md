# de-pack — Deutschland

Das erste vollständige Jurisdiktions-Pack: Deutschland. Wählbar als `createTenant(pack: "de")`.
**Self-contained:** alle Module liegen in diesem Ordner, kein geteiltes Modul mit anderen Packs
(Packs bauen nicht aufeinander auf). **Eigener Kontenrahmen** (kein SKR übernommen) — wir verwalten
die Konten selbst; SKR03/04 bleiben über `importChartOfAccounts` zuladbar.

## Was drin ist (Module → das Manifest `de.json` komponiert sie)

| Modul | kind | Inhalt |
|---|---|---|
| `accounts/de-konten` | accounts | Eigener DE-Kontenrahmen, **40 Konten** (Standard + DE-Zusatz: 4020 Skonto/Erlösschmälerung · 4030 ig. Lieferungen · 4040 Kleinunternehmer-Erlöse · 4050 Wertabgabe · 6010/6020 Bewirtung · 1900/3900 Rechnungsabgrenzung) |
| `tax/de-ust` | tax | USt19, USt7, VSt19, VSt7, RC13b (§13b), igL (ig. Lieferung), USt19WA (Wertabgabe) — Sätze/Kennzahlen, Konten auf neutralen Nummern |
| `mappings/de-bilanz` | mapping | Bilanzgliederung HGB §266 |
| `mappings/de-guv` | mapping | GuV-Gliederung HGB §275 (Gesamtkostenverfahren) |
| `depreciation/de-afa` | depreciation | GWG-Grenzen (§6 Abs. 2 EStG), Nutzungsdauern |
| `assets/de-assets` | assetAccounts | Anlagen-Gegenkonten (Zugang/AfA/GWG/Abgang) auf neutralen Nummern |
| `policy/de` | policy | EUR, half-up je Beleg (`perVoucher`), Skala 2; Defaults: cash (EÜR), Regelbesteuerung, Quartal |

## Details stehen in der Doku (Wissensbasis), nicht hier

- **Fachwissen:** `10-fachwissen/` — USt (§13b, igL, Kleinunternehmer §19, Wertabgabe), EÜR, HGB-Bilanz/GuV, AfA.
- **Vertrag/Format:** `50-spezifikation/` (Datenformat, Resolver-Semantik), `30-anforderungen/` (F-/NF-IDs).
- **Entscheidungen:** `00-projekt/entscheidungen.md` (eigener Kontenrahmen statt SKR; de-pack auf neutralen Nummern).

## Konformität — jedes Modul testet seine Anforderungen

Alle Fixtures in `70-testsuite/fixtures/pack/de-pack/` laufen **rein aus dieser Bibliothek** (kein
Inline) und sind grün in **PHP und Node** (`--strict`, byte-identischer Doppellauf). Modul → Anforderung → Test:

| Modul | Anforderung | Test-Fixture |
|---|---|---|
| accounts (`de-konten`) | löst auf, 40 Konten, als Pack wählbar | `de-pack-resolves` |
| tax · USt19 Regelsatz | F-TAX-002 / SF-02 | `de-pack-resolves`, `de-jahresgang` |
| tax · USt7 ermäßigt | F-TAX-002 | `de-ust7-ermaessigt` |
| tax · VSt19 Vorsteuer | F-TAX-002 / SF-03 | `de-eingangsrechnung` |
| tax · RC13b §13b | F-TAX-006 | `de-reverse-charge` |
| tax · igL | F-TAX-012 / SF-21 | `de-ig-lieferung` |
| tax · USt19WA Wertabgabe | F-TAX-010 / SF-20 | `de-wertabgabe` |
| tax · USt-Voranmeldung | F-TAX-005 / SF-09 | `de-vat-return` |
| de-konten · 4020 Skonto §17 | F-TAX-008 / SF-18 | `de-skonto`, `de-jahresgang` |
| de-konten · 6010/6020 Bewirtung §4(7) | SF-23 | `de-bewirtung` |
| de-konten · 4040 Kleinunternehmer §19 | F-TAX-004 / SF-11 | `de-kleinunternehmer` |
| de-konten · 1900/3900 Rechnungsabgrenzung | HGB §266 | `de-jahresgang` |
| mappings · de-bilanz §266 + de-guv §275 | F-CORE-015 / SF-10 | `de-bilanz-guv`, `de-jahresgang` |
| depreciation + assetAccounts | F-AST-001/002/003 / SF-05 | `de-afa-lauf`, `de-jahresgang` |
| policy · perVoucher/Skala 2 | Determinismus | `de-pack-resolves`; Mechanismus `conformance-xx` |
| **Integration** (Bilanz+Journal jederzeit korrekt) | F-CORE-016 / SF-10 | `de-jahresgang` |

`de-jahresgang` ist der durchgehende End-to-End-Test eines Geschäftsjahres (Buchung → Vorsteuer →
Skonto → Anlagenzugang → Rechnungsabgrenzung → AfA) mit balancierter Bilanz zu mehreren Stichtagen.

**Auch vom Frontend (CLI) getestet:** `summae init --pack de` lädt das Pack aus der Bibliothek —
in **beiden** CLIs (PHP + Node). End-to-End-Smoke (init → buchen → Bilanz balanciert) ist in beiden
CLI-Test-Suites grün. Nutzung: `docs/handbuch` § 3.
