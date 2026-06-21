# Pack-Bibliothek (ausgeliefert)

Der **Produkt-Datenbestand** von summae: die wählbaren *Packs* und die
wiederverwendbaren *Module*, aus denen sie komponiert sind. Kanonisch hier in der
Wissensbasis, per `make sync` ins Repo gespiegelt (`pack-library/`), mit der
Library ausgeliefert.

> Nicht zu verwechseln mit `70-testsuite/fixtures/pack/` — das sind *Tests*, die
> beweisen, dass diese Packs korrekt auflösen. Hier liegt das *Produkt*.

## Aufbau

```
pack-library/
├── modules/                 ← Bausteine, nach Sorte (kind)
│   ├── accounts/            ← Kontenrahmen
│   │   └── neutral.json         (id "neutral" — 32 Konten, jurisdiktionsfrei)
│   ├── tax/                 ← Steuersätze
│   ├── mappings/            ← Bilanz / EÜR / USt-Voranmeldung
│   ├── depreciation/        ← AfA
│   └── policy/              ← Rundung, Skala, Granularität
└── packs/                   ← die WAHL beim Anlegen (Manifeste)
    └── default.json             (id "default" → nimmt accounts/neutral)
```

## Begriffe

- **Pack** (`packs/<id>.json`): das, was man beim Anlegen eines Mandanten **wählt**
  (`createTenant(pack: "<id>")`). Ein Manifest, das Module benennt + die `packPolicy`
  trägt. Der Resolver schlägt es über seinen **Namen** nach.
- **Modul** (`modules/<kind>/<id>.json`): ein Baustein **einer** Sorte. Von mehreren
  Packs **wiederverwendbar** (z. B. zieht das künftige `de`-Pack dasselbe
  `accounts/neutral`).
- **base / Kern**: die Engine selbst — trägt **keine** Konten. Konten kommen
  ausschließlich übers Pack, einmalig beim Anlegen.
