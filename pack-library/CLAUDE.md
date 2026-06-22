# CLAUDE.md — `pack-library/` (Pack-Autoren)

Hier liegen die ausgelieferten **Packs** — die **Stecker** der drei Politiksorten, nie Code/Recht des Kerns.
Produkt-Daten, **keine Tests** (Konformitäts-Fixtures leben in `testsuite/`).

> **Quelle ist die Wissensbasis** (`/Rechnungswesen/pack-library/`), ins Repo via `make sync` (`rsync --delete`)
> gespiegelt — **den Ordner im Repo nie direkt editieren**, sonst überschreibt der nächste Sync.

## Aufbau

- Ein **Pack** = self-contained Ordner `pack-library/<pack>/` mit Manifest + eigenen Modulen. Packs **bauen nicht
  aufeinander auf** (eigene IDs, eigener Kontenrahmen, **kein geteiltes `modules/`**).
- Ein **Modul** = ein Stecker für **genau eine** Politiksorte, meist eine Daten-Datei (`kind` + `data`).

| `kind` | Politiksorte |
|---|---|
| `tax` · `depreciation` · `assetAccounts` | **Expansion** (Stecker) |
| `mapping` | **Projektion** (Mappings) |
| `accounts` | füllt das (kontenlose) **Substrat**-Konto-Primitiv |
| `policy` | **Parameter** (Rundung/Skala via `packPolicy`) |
| *(`constraint` — fehlt noch)* | Constraint (heute nur generisch im Kern) |

- Der **Resolver** (`PackResolver`, byte-gleich PHP↔Node) faltet Manifest + Module zu *einem* Bündel und
  **scheitert laut** bei fehlenden/inkohärenten Referenzen (`E_PACK_UNRESOLVED_REF` / `E_PACK_INCOHERENT`).

## Regel

- **Kein Code/Recht ins Substrat.** Ein Pack ist Daten; ein neues *Paradigma* mit eigenem Algorithmus wäre ein
  komponierbares Modul **hinter dem Sockel** — nie in den Kern geschmiert (Zielmodell, Root-`CLAUDE.md`).
- Konsumenten **referenzieren** ein Pack per Name, statt Konten/Regeln inline zu kopieren.

Pack von Hand schreiben (Skelette je `kind`, Manifest): Handbuch `docs/handbuch/README.md`.
