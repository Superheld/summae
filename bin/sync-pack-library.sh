#!/usr/bin/env bash
#
# Mirrors the shipped pack library from the knowledge base.
#
# This is the LAST remaining mirror. Until 2026-08-23 the conformance testsuite was mirrored
# the same way, because its source lived outside the repository; the knowledge base moved into
# `knowledge/` and the testsuite became its own source, so that half is gone. The pack library
# is still authored outside, so this one stays — one-way, source wins.
set -euo pipefail
cd "$(dirname "$0")/.."

# Source: $SUMMAE_PACKLIB_SRC, otherwise the knowledge-base root next to (or above) the repo.
SRC="${SUMMAE_PACKLIB_SRC:-}"
if [[ -z "$SRC" ]]; then
    for candidate in ../Rechnungswesen*/pack-library ../pack-library; do
        if [[ -d "$candidate" ]]; then
            SRC="$candidate"
            break
        fi
    done
fi

if [[ -z "$SRC" || ! -d "$SRC" ]]; then
    echo "ERROR: pack library source not found. Set SUMMAE_PACKLIB_SRC, e.g.:" >&2
    echo "  SUMMAE_PACKLIB_SRC='/path/to/knowledge-base/pack-library' $0" >&2
    exit 1
fi

rsync -a --delete "$SRC"/ pack-library/
echo "Pack library synced from $SRC: $(find pack-library -name '*.json' | wc -l | tr -d ' ') files"

# Every manifest a gate test reads must survive the sync. `--delete` removes whatever is here
# and not in the source, so a source that resolved but is incomplete would leave the pack
# schema validation and the walkthrough scenarios failing with "the file is gone" rather than
# "the contract is broken". Better to fail here, where the cause is still visible.
missing=()
for required in \
    pack-library/default-pack/default.json \
    pack-library/de-pack/de.json \
    pack-library/us-pack/us.json; do
    [[ -f "$required" ]] || missing+=("$required")
done

if (( ${#missing[@]} > 0 )); then
    echo "ERROR: the sync removed pack manifests the gate tests read:" >&2
    printf '  %s\n' "${missing[@]}" >&2
    echo "Restore them with 'git checkout -- pack-library' and check the source layout." >&2
    exit 1
fi
