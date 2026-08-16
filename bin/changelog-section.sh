#!/usr/bin/env bash
#
# Prints the CHANGELOG section for one version — the body of a GitHub release.
#
# Usage: bin/changelog-section.sh 0.9.1
#
# Fails loudly when the section is missing: a tag without its CHANGELOG entry is a release
# nobody can read, and silence here would produce an empty release note instead of an error.
set -euo pipefail
cd "$(dirname "$0")/.."

version="${1:-}"
if [[ -z "$version" ]]; then
    echo "usage: $0 <version>   (e.g. 0.9.1, without the leading v)" >&2
    exit 2
fi

# Section = everything between `## <version> — <date>` and the next top-level heading.
# Trimming happens in awk too: `tac` does not exist on macOS, and this script has to run both
# locally and in the Linux runner.
section=$(awk -v want="$version" '
    /^## / {
        if (inside) exit
        # "## 0.9.1 — 2026-08-17" -> compare the second field
        if ($2 == want) { inside = 1; next }
    }
    inside { buf = buf $0 "\n" }
    END {
        gsub(/^\n+/, "", buf)
        gsub(/\n+$/, "\n", buf)
        printf "%s", buf
    }
' CHANGELOG.md)

if [[ -z "$section" ]]; then
    echo "ERROR: CHANGELOG.md has no section '## $version — …'" >&2
    echo "Add it before tagging — the release notes are generated from it." >&2
    exit 1
fi

printf '%s\n' "$section"
