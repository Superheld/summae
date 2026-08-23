#!/usr/bin/env bash
#
# Mirrors the conformance testsuite from the knowledge base.
# One-way street: knowledge base -> testsuite/. Fixtures are NEVER edited here
# (findings belong in SPEC-FINDINGS.md, see RUNTIME-LEITFADEN).
#
# Source: $SUMMAE_TESTSUITE_SRC, otherwise auto-search (see the layout note below).
set -euo pipefail
cd "$(dirname "$0")/.."

# The knowledge base has two anchors, and they are NOT the same directory:
#
#   <kb-root>/docs/70-testsuite      <- fixtures            ($SRC)
#   <kb-root>/docs/50-spezifikation  <- schema + catalogue  ($DOCS_ROOT)
#   <kb-root>/pack-library           <- shipped packs       ($KB_ROOT)
#
# Until 2026-08 the numbered folders sat directly at <kb-root>; the move to docs/
# left pack-library behind at the root, which is why one "dirname $SRC" cannot serve
# both. Both layouts are probed so an older checkout keeps working.
SRC="${SUMMAE_TESTSUITE_SRC:-}"
if [[ -z "$SRC" ]]; then
    # summae as a sibling of the knowledge base (../Rechnungswesen*/...)
    # OR as a child of it (../...). New layout first, old layout as fallback.
    for candidate in \
        ../Rechnungswesen*/docs/70-testsuite ../docs/70-testsuite \
        ../Rechnungswesen*/70-testsuite ../70-testsuite; do
        if [[ -d "$candidate" ]]; then
            SRC="$candidate"
            break
        fi
    done
fi

if [[ -z "$SRC" || ! -d "$SRC" ]]; then
    echo "ERROR: knowledge base not found. Set SUMMAE_TESTSUITE_SRC, e.g.:" >&2
    echo "  SUMMAE_TESTSUITE_SRC='/path/to/knowledge-base/docs/70-testsuite' $0" >&2
    exit 1
fi

# 50-spezifikation/ sits next to 70-testsuite; pack-library/ one level further up
# whenever the numbered folders are nested in docs/.
DOCS_ROOT="$(dirname "$SRC")"
KB_ROOT="$DOCS_ROOT"
[[ -d "$KB_ROOT/pack-library" ]] || KB_ROOT="$(dirname "$DOCS_ROOT")"

# Shipped pack library (selectable packs + reusable modules) — unlike testsuite/ this is
# product data, not test data. A missing source here used to be the silent case: the old
# `if [[ -d ]]` simply did not fire and the packs kept their last synced state, which looks
# exactly like a successful sync. It is an error now — and it is checked BEFORE the first
# rsync, so a mislocated source cannot flatten the testsuite mirror on its way to the
# error message.
PACKLIB_SRC="$KB_ROOT/pack-library"
if [[ ! -d "$PACKLIB_SRC" ]]; then
    echo "ERROR: pack library not found at $PACKLIB_SRC" >&2
    echo "  \$SRC resolved to: $SRC" >&2
    echo "  Expected <kb-root>/pack-library next to the numbered doc folders." >&2
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
SCHEMA_SRC="$DOCS_ROOT/50-spezifikation/schema"
if [[ -d "$SCHEMA_SRC" ]]; then
    rsync -a --delete "$SCHEMA_SRC"/ testing/testsuite/schema/
fi

# Error catalogue (normative), so validate.py can check code coverage in the mirror too
# (otherwise it is only findable in the knowledge base).
KATALOG_SRC="$DOCS_ROOT/50-spezifikation/fehlerkatalog.md"
if [[ -f "$KATALOG_SRC" ]]; then
    cp "$KATALOG_SRC" testing/testsuite/fehlerkatalog.md
fi

# Shipped pack library — source validated above.
rsync -a --delete "$PACKLIB_SRC"/ pack-library/
echo "Pack library synced: $(find pack-library -name '*.json' | wc -l | tr -d ' ') files"

# Every file a gate test reads must survive a sync. Five tests across both languages check
# against these three: the parameter drift guards (ProjectionParametersTest,
# projection-parameters.test.ts), the format/pack schema validation, and the exit-code
# guards (ExitCodesTest, exit-codes.test.ts). A missing file would not fail those tests
# with "the contract is broken" but with "the file is gone" — or, worse, quietly stop
# checking anything. Better to fail here, where the cause is still visible.
# The pack manifests are on the list for the same reason: the pack schema validation and
# the walkthrough scenarios read them, one per shipped pack.
missing=()
for required in \
    testing/testsuite/schema/api-parameters.json \
    testing/testsuite/schema/format.schema.json \
    testing/testsuite/fehlerkatalog.md \
    pack-library/default-pack/default.json \
    pack-library/de-pack/de.json \
    pack-library/us-pack/us.json; do
    [[ -f "$required" ]] || missing+=("$required")
done

if (( ${#missing[@]} > 0 )); then
    echo "ERROR: the sync removed files the gate tests read:" >&2
    printf '  %s\n' "${missing[@]}" >&2
    echo "They come from $DOCS_ROOT/50-spezifikation/ and $KB_ROOT/pack-library/, not from $SRC." >&2
    echo "Restore them with 'git checkout -- testing/testsuite pack-library' and check the source layout." >&2
    exit 1
fi

echo "Testsuite synced from: $SRC"
echo "Fixtures: $(find testing/testsuite/fixtures -name '*.json' | wc -l | tr -d ' ')"
