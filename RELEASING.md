# Releasing (maintainer)

How the packages get into the registries. User docs are in the
[handbook](docs/handbuch/README.md); this is for maintainers only.

> This document holds the **operative steps** only.

## Version scheme

A git tag `vX.Y.Z` marks a release. Before tagging:

- **Node:** `implementations/node/packages/core/package.json` → set `version` to `X.Y.Z`.
- **PHP:** no `version` fields (Composer derives them from the tag; `branch-alias`
  is set).

## npm — `@superheld/summae-core`

Publishing runs through GitHub Actions
([`.github/workflows/release-npm.yml`](.github/workflows/release-npm.yml)),
turnkey after a one-time setup:

1. **One-time:** create an npm automation token with publish rights for the `@superheld`
   scope (npmjs.com → Access Tokens → „Automation"). Store it as the repository secret
   `NPM_TOKEN`:
   ```bash
   gh secret set NPM_TOKEN --repo Superheld/summae
   ```
2. **Per release:** bump `version`, commit, push tag `vX.Y.Z` → the workflow
   builds (`pnpm build`) and publishes. Dry run anytime via „Run workflow"
   (input `dry_run = true`).

Manual alternative (local, one-time login):
```bash
cd implementations/node && pnpm install && pnpm build
npm login   # or `! npm login` in the session
pnpm --filter @superheld/summae-core publish --no-git-checks
```

## Composer — `superheld/summae-{core,laravel,cli}`

**Gotcha:** Packagist reads exactly **one** `composer.json` at the repo root.
This monorepo has the packages in subfolders — Packagist can't read them
directly. There are three ways; the choice is a footprint decision:

| Way | `composer require superheld/summae-core` | Footprint | Separation core/laravel/cli |
|---|---|---|---|
| **A — subtree split repos + Packagist** (Symfony/Laravel pattern) | ✅ direct | 3 extra read-only repos + split workflow + Packagist submit | ✅ cleanly separated |
| **B — VCS only** | ⚠ consumer needs a `repositories` entry per split repo | 3 split repos, no Packagist | ✅ |
| **C — one umbrella package `superheld/summae`** | just `require superheld/summae` (everything together) | 0 extra repos | ❌ pulls illuminate/* even for core users |

**Chosen: way A.** Set up:

- Read-only split repos: `Superheld/summae-core`, `Superheld/summae-laravel`,
  `Superheld/summae-cli` (content + tag `v0.1.0` initially mirrored manually).
- Split workflow [`.github/workflows/split-packages.yml`](.github/workflows/split-packages.yml):
  mirrors automatically on every `v*` tag.

Per release (after setup): push tag `vX.Y.Z` → the split workflow updates the
three repos incl. tag → Packagist follows via webhook.

**One-time maintainer steps (your accounts):**

1. **PAT for the split workflow:** create a personal access token with `repo` write
   access to the split repos, store it as a secret:
   ```bash
   gh secret set SPLIT_TOKEN --repo Superheld/summae
   ```
   (Since 0.2.0 the split runs turnkey via the workflow; the secret is set.
   The initial 0.1.0 split still ran manually via local SSH login.)
2. **Packagist sign-up:** log in on packagist.org with GitHub and submit the three
   split repos (Submit → repo URL). On submit, Packagist installs the GitHub webhook
   for auto-updates. Order: first `summae-core`, then `summae-laravel` / `summae-cli`
   (their `*` dependency on core is then resolved by Packagist).

After that, `composer require superheld/summae-core` (or -laravel/-cli) works
directly.
