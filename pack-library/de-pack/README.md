# de-pack — Deutschland

Das erste vollständige Jurisdiktions-Pack: Deutschland auf dem **neutralen** Kern. Wählbar als
`createTenant(pack: "de")`. **Eigener Kontenrahmen** (kein SKR übernommen) — wir verwalten die
Konten selbst; SKR03/04 bleiben über `importChartOfAccounts` zuladbar.

## Was drin ist (Module → das Manifest `de.json` komponiert sie)

| Modul | kind | Inhalt |
|---|---|---|
| `neutral` *(geteilt, aus `../modules/accounts/`)* | accounts | 32 neutrale Standardkonten |
| `accounts/de-extras` | accounts | 6 DE-Zusatzkonten: 4020 Skonto/Erlösschmälerung · 4030 ig. Lieferungen · 4040 Kleinunternehmer-Erlöse · 4050 unentgeltliche Wertabgaben · 6010/6020 Bewirtung abziehbar/nicht |
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

## Konformität

Fixtures in `70-testsuite/fixtures/pack/de-pack/` (laufen rein aus dieser Bibliothek, kein Inline):
Auflösung (38 Konten) · USt 19/7 · Vorsteuer-Eingangsrechnung · §13b · ig. Lieferung · Wertabgabe ·
Bewirtungs-Split · Kleinunternehmer · Skonto (§17, settle) · Bilanz/GuV. Grün in PHP **und** Node.

**Offen (ehrlich):** AfA- und accrual-USt-VA-*Verhalten* im Pack-Modus (`createTenant(pack)`) brauchen
`acquireAsset`/manuelle `post`-Schritte mit vorab erzeugtem Beleg — dafür fehlt im Pack-Modus noch ein
Weg, einen Beleg zu setzen (Folge-Punkt: eine `createVoucher`-Operation). Die AfA-/Anlagen-Module
*lösen* aber sauber auf (I3 + Bridge, von `de-pack-resolves` bewiesen); das AfA-/VA-*Verhalten* ist
durch die Engine-Fixtures `gwg-and-depreciation` bzw. `vat-return` (Inline-Pfad) bewiesen.
