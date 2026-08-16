#!/usr/bin/env bash
#
# Mirrors the conformance testsuite from the knowledge base.
# One-way street: knowledge base -> testsuite/. Fixtures are NEVER edited here
# (findings belong in SPEC-FINDINGS.md, see RUNTIME-LEITFADEN).
#
# Source: $SUMMAE_TESTSUITE_SRC, otherwise auto-search for ../Rechnungswesen*/70-testsuite
set -euo pipefail
cd "$(dirname "$0")/.."

SRC="${SUMMAE_TESTSUITE_SRC:-}"
if [[ -z "$SRC" ]]; then
    # summae as a sibling of the knowledge base (../Rechnungswesen*/70-testsuite)
    # OR as a child of it (../70-testsuite).
    for candidate in ../Rechnungswesen*/70-testsuite ../70-testsuite; do
        if [[ -d "$candidate" ]]; then
            SRC="$candidate"
            break
        fi
    done
fi

if [[ -z "$SRC" || ! -d "$SRC" ]]; then
    echo "ERROR: knowledge base not found. Set SUMMAE_TESTSUITE_SRC, e.g.:" >&2
    echo "  SUMMAE_TESTSUITE_SRC='/path/to/knowledge-base/70-testsuite' $0" >&2
    exit 1
fi

rsync -a --delete "$SRC"/ testing/testsuite/

# The three files below do NOT come from $SRC — they live in the knowledge base under
# 50-spezifikation/, next to the spec they belong to. The rsync above deletes them
# (--delete: whatever is here and not in the source goes), and the blocks below put them
# back. That is intentional, but it means a source that fails to resolve is invisible: the
# `if` would simply not fire and the mirror would be missing a file nobody asked about.
# The check at the end of this script is what makes that loud instead of silent.

# Machine-readable schema (normative derivation)
SCHEMA_SRC="$(dirname "$SRC")/50-spezifikation/schema"
if [[ -d "$SCHEMA_SRC" ]]; then
    rsync -a --delete "$SCHEMA_SRC"/ testing/testsuite/schema/
fi

# Error catalogue (normative), so validate.py can check code coverage in the mirror too
# (otherwise it is only findable in the knowledge base).
KATALOG_SRC="$(dirname "$SRC")/50-spezifikation/fehlerkatalog.md"
if [[ -f "$KATALOG_SRC" ]]; then
    cp "$KATALOG_SRC" testing/testsuite/fehlerkatalog.md
fi

# Shipped pack library (selectable packs + reusable modules) — unlike testsuite/ this is
# product data, not test data.
PACKLIB_SRC="$(dirname "$SRC")/pack-library"
if [[ -d "$PACKLIB_SRC" ]]; then
    rsync -a --delete "$PACKLIB_SRC"/ pack-library/
    echo "Pack library synced: $(find pack-library -name '*.json' | wc -l | tr -d ' ') files"
fi

# Every file a gate test reads must survive a sync. Five tests across both languages check
# against these three: the parameter drift guards (ProjectionParametersTest,
# projection-parameters.test.ts), the format/pack schema validation, and the exit-code
# guards (ExitCodesTest, exit-codes.test.ts). A missing file would not fail those tests
# with "the contract is broken" but with "the file is gone" — or, worse, quietly stop
# checking anything. Better to fail here, where the cause is still visible.
missing=()
for required in \
    testing/testsuite/schema/api-parameters.json \
    testing/testsuite/schema/format.schema.json \
    testing/testsuite/fehlerkatalog.md; do
    [[ -f "$required" ]] || missing+=("$required")
done

if (( ${#missing[@]} > 0 )); then
    echo "ERROR: the sync removed files the gate tests read:" >&2
    printf '  %s\n' "${missing[@]}" >&2
    echo "They come from $(dirname "$SRC")/50-spezifikation/, not from $SRC." >&2
    echo "Restore them with 'git checkout -- testing/testsuite' and check the source layout." >&2
    exit 1
fi

echo "Testsuite synced from: $SRC"
echo "Fixtures: $(find testing/testsuite/fixtures -name '*.json' | wc -l | tr -d ' ')"
