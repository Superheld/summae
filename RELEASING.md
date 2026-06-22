# Veröffentlichung (Maintainer)

Wie die Pakete in die Registries kommen. Nutzer-Doku liegt im
[Handbuch](docs/handbuch/README.md); dies hier ist nur für Maintainer.

> **Modell-Entscheidung** (Weg A, eine SemVer-Linie, verworfene Alternativen B/C)
> liegt in der Wissensbasis: `00-projekt/entscheidungen.md` (2026-06-20) und
> `oss-governance.md`. Dieses Dokument hält nur die **operativen Schritte**.

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

**Gewählt: Weg A.** Eingerichtet:

- Read-only Split-Repos: `Superheld/summae-core`, `Superheld/summae-laravel`,
  `Superheld/summae-cli` (Inhalt + Tag `v0.1.0` initial manuell gespiegelt).
- Split-Workflow [`.github/workflows/split-packages.yml`](.github/workflows/split-packages.yml):
  spiegelt bei jedem `v*`-Tag automatisch.

Pro Release (nach der Einrichtung): Tag `vX.Y.Z` pushen → Split-Workflow
aktualisiert die drei Repos inkl. Tag → Packagist zieht via Webhook nach.

**Einmalige Maintainer-Schritte (deine Accounts):**

1. **PAT für den Split-Workflow:** Personal Access Token mit `repo`-Schreibrecht
   auf die Split-Repos erzeugen, als Secret hinterlegen:
   ```bash
   gh secret set SPLIT_TOKEN --repo Superheld/summae
   ```
   (Seit 0.2.0 läuft der Split turnkey über den Workflow; das Secret ist gesetzt.
   Der initiale 0.1.0-Split lief noch manuell über die lokale SSH-Anmeldung.)
2. **Packagist-Anmeldung:** auf packagist.org mit GitHub einloggen und die drei
   Split-Repos einreichen (Submit → Repo-URL). Beim Submit installiert Packagist
   den GitHub-Webhook für Auto-Updates. Reihenfolge: erst `summae-core`, dann
   `summae-laravel` / `summae-cli` (deren `*`-Abhängigkeit auf core wird dann
   von Packagist aufgelöst).

Danach funktioniert `composer require superheld/summae-core` (bzw. -laravel/-cli)
direkt.
