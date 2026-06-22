# CLAUDE.md — `pack-library/` (pack authors)

Here live the shipped **packs** — the **plugs** of the three policy kinds, never core code/law.
Product data, **no tests** (conformance fixtures live in `testsuite/`).

> **The source is an internal repository**, mirrored into this repo via `make sync` (`rsync --delete`)
> — **never edit the folder in the repo directly**, the next sync overwrites it.

## Layout

- A **pack** = self-contained folder `pack-library/<pack>/` with manifest + own modules. Packs **do not build
  on each other** (own ids, own chart of accounts, **no shared `modules/`**).
- A **module** = a plug for **exactly one** policy kind, usually a data file (`kind` + `data`).

| `kind` | policy kind |
|---|---|
| `tax` · `depreciation` · `assetAccounts` | **expansion** (plug) |
| `mapping` | **projection** (mappings) |
| `accounts` | fills the (account-less) **substrate** account primitive |
| `policy` | **parameters** (rounding/scale via `packPolicy`) |
| *(`constraint` — still missing)* | constraint (today only generic in the core) |

- The **resolver** (`PackResolver`, byte-equal PHP↔Node) folds manifest + modules into *one* bundle and
  **fails loudly** on missing/incoherent references (`E_PACK_UNRESOLVED_REF` / `E_PACK_INCOHERENT`).

## Rule

- **No code/law into the substrate.** A pack is data; a new *paradigm* with its own algorithm would be a
  composable module **behind the socket** — never smeared into the core (target model, root `CLAUDE.md`).
- Consumers **reference** a pack by name instead of copying accounts/rules inline.

Writing a pack by hand (skeletons per `kind`, manifest): handbook `docs/handbuch/README.md`.
