# Pack library (shipped)

summae's **product data**: the selectable *packs* and the *modules* they are
composed of. Maintained in an internal source, mirrored into the repo via
`make sync` (`pack-library/`), and shipped with the library.

> Not to be confused with the pack **fixtures** under `testsuite/fixtures/pack/` —
> those are *tests* that prove these packs resolve correctly. Here lives the *product*.

## Layout

**Each pack is a self-contained folder** with its own manifest and its own modules.
Packs do not build on each other — own ids, own chart of accounts, no shared `modules/`.

```
pack-library/
├── default-pack/                ← pack "default": neutral starter
│   ├── accounts/neutral.json       (id "neutral" — 32 jurisdiction-free accounts)
│   └── default.json                (manifest → its own accounts/neutral)
└── de-pack/                     ← pack "de": Germany (self-contained)
    ├── accounts/de-konten.json     (40 accounts)
    ├── tax/de-ust.json
    ├── mappings/de-bilanz.json · de-guv.json
    ├── depreciation/de-afa.json
    ├── assets/de-assets.json
    ├── policy/de.json
    └── de.json                     (manifest → the de-* modules)
```

The loader classifies **content-based** (manifest = has `modules[]`, module = has `kind`) and
collects recursively across the library, resolving a manifest's module refs by their `id` — the
folder structure is convention, not enforced. By design each pack ships its own modules.

## Terms

- **Pack** (`<name>-pack/` with manifest `<id>.json`): what you **choose** when creating a
  tenant (`createTenant(pack: "<id>")`). A manifest names modules + carries the `packPolicy`;
  the resolver looks it up by **name**.
- **Module** (`<kind>` file with `data`): a building block of **one** kind, living in its
  pack's folder.
- **base / core**: the engine itself — carries **no** accounts. Accounts come exclusively via
  the pack, once at creation.
