# Pack-Bibliothek (ausgeliefert)

Der **Produkt-Datenbestand** von summae: die wählbaren *Packs* und die
wiederverwendbaren *Module*, aus denen sie komponiert sind. Kanonisch hier in der
Wissensbasis, per `make sync` ins Repo gespiegelt (`pack-library/`), mit der
Library ausgeliefert.

> Nicht zu verwechseln mit `70-testsuite/fixtures/pack/` — das sind *Tests*, die
> beweisen, dass diese Packs korrekt auflösen. Hier liegt das *Produkt*.

## Aufbau

**Ein Ordner je Pack**; geteilte Bausteine in `modules/`.

```
pack-library/
├── modules/                      ← GETEILTE Bausteine (von mehreren Packs genutzt)
│   └── accounts/neutral.json        (id "neutral" — 32 Konten, jurisdiktionsfrei)
├── default-pack/                 ← Pack "default": neutraler Starter
│   └── default.json                 (Manifest → nimmt modules/accounts/neutral)
└── de-pack/                      ← Pack "de": Deutschland (eigene Module + Manifest)
    ├── accounts/de-extras.json      (DE-Zusatzkonten)
    ├── tax/de-ust.json
    ├── mappings/de-bilanz.json · de-guv.json
    ├── depreciation/de-afa.json
    ├── assets/de-assets.json
    ├── policy/de.json
    └── de.json                      (Manifest → neutral + die de-Module)
```

Der Loader klassifiziert **inhaltsbasiert** (Manifest = hat `modules[]`, Modul = hat `kind`) und
sammelt rekursiv über die ganze Bibliothek — die Ordnerstruktur ist also nur Konvention, kein Zwang.
Ein Pack referenziert geteilte Module über deren `id` (z. B. zieht `de` dasselbe `accounts/neutral`).

## Begriffe

- **Pack** (`<name>-pack/` mit Manifest `<id>.json`): das, was man beim Anlegen eines Mandanten
  **wählt** (`createTenant(pack: "<id>")`). Ein Manifest benennt Module + trägt die `packPolicy`;
  der Resolver schlägt es über den **Namen** nach.
- **Modul** (`<kind>`-Datei mit `data`): ein Baustein **einer** Sorte. Geteilte liegen in
  `modules/<kind>/`, pack-spezifische im jeweiligen Pack-Ordner.
- **base / Kern**: die Engine selbst — trägt **keine** Konten. Konten kommen ausschließlich übers
  Pack, einmalig beim Anlegen.
