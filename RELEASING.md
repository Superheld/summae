# Veröffentlichung (Maintainer)

Wie die Pakete in die Registries kommen. Nutzer-Doku liegt im
[Handbuch](docs/handbuch/README.md); dies hier ist nur für Maintainer.

## Versionsschema

Ein Git-Tag `vX.Y.Z` markiert ein Release. Vor dem Tag:

- **Node:** `implementations/node/packages/core/package.json` → `version` auf `X.Y.Z`.
- **PHP:** keine `version`-Felder (Composer leitet aus dem Tag ab; `branch-alias`
  ist gesetzt).

## npm — `@superheld/summae-core`

Veröffentlichung läuft über GitHub Actions
([`.github/workflows/release-npm.yml`](.github/workflows/release-npm.yml)),
turnkey nach einer einmaligen Einrichtung:

1. **Einmalig:** npm-Automation-Token mit Publish-Recht für den `@superheld`-Scope
   erzeugen (npmjs.com → Access Tokens → „Automation"). Als Repository-Secret
   `NPM_TOKEN` hinterlegen:
   ```bash
   gh secret set NPM_TOKEN --repo Superheld/summae
   ```
2. **Pro Release:** `version` bumpen, committen, Tag `vX.Y.Z` pushen → der Workflow
   baut (`pnpm build`) und publiziert. Trockenlauf jederzeit über „Run workflow"
   (Eingabe `dry_run = true`).

Manuelle Alternative (lokal, einmaliger Login):
```bash
cd implementations/node && pnpm install && pnpm build
npm login   # bzw. `! npm login` in der Session
pnpm --filter @superheld/summae-core publish --no-git-checks
```

## Composer — `superheld/summae-{core,laravel,cli}`

**Stolperstein:** Packagist liest genau **eine** `composer.json` im Repo-Root.
Dieses Monorepo hat die Pakete in Unterordnern — Packagist kann sie so nicht
direkt einlesen. Es gibt drei Wege; die Wahl ist eine Footprint-Entscheidung:

| Weg | `composer require superheld/summae-core` | Footprint | Trennung core/laravel/cli |
|---|---|---|---|
| **A — Subtree-Split-Repos + Packagist** (Symfony/Laravel-Muster) | ✅ direkt | 3 zusätzliche read-only Repos + Split-Workflow + Packagist-Submit | ✅ sauber getrennt |
| **B — nur VCS** | ⚠ Consumer braucht `repositories`-Eintrag je Split-Repo | 3 Split-Repos, kein Packagist | ✅ |
| **C — ein Sammelpaket `superheld/summae`** | nur `require superheld/summae` (alles zusammen) | 0 extra Repos | ❌ zieht illuminate/* auch für Core-Nutzer |

Weg A ist der Standard für „bequem installierbar" mit sauberer Paket-Trennung.
Er braucht: pro Paket ein read-only Split-Repo (`Superheld/summae-core` usw.),
einen Split-Workflow (extrahiert Unterordner-Historie bei jedem Tag) und die
einmalige Packagist-Anmeldung der Split-Repos (Web-Formular, GitHub-Login).

> Offen — die Wahl zwischen A/B/C ist noch nicht getroffen (siehe Footprint-
> Präferenz „nur der Ordner summae"). Bis dahin: From-Source-/Path-Repo-Install
> wie im Handbuch beschrieben.
