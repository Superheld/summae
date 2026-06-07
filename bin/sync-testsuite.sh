#!/usr/bin/env bash
#
# Synchronisiert die Konformitäts-Testsuite aus der Wissensbasis.
# Einbahnstraße: Wissensbasis -> testsuite/. Fixtures werden hier NIE editiert
# (Befunde gehören nach SPEC-FINDINGS.md, siehe AGENT-BRIEFING).
#
# Quelle: $RW_TESTSUITE_SRC, sonst Auto-Suche nach ../Rechnungswesen*/70-testsuite
set -euo pipefail
cd "$(dirname "$0")/.."

SRC="${RW_TESTSUITE_SRC:-}"
if [[ -z "$SRC" ]]; then
    for candidate in ../Rechnungswesen*/70-testsuite; do
        if [[ -d "$candidate" ]]; then
            SRC="$candidate"
            break
        fi
    done
fi

if [[ -z "$SRC" || ! -d "$SRC" ]]; then
    echo "FEHLER: Wissensbasis nicht gefunden. RW_TESTSUITE_SRC setzen, z. B.:" >&2
    echo "  RW_TESTSUITE_SRC='/pfad/zur/wissensbasis/70-testsuite' $0" >&2
    exit 1
fi

rsync -a --delete "$SRC"/ testsuite/

# Maschinenlesbares Schema (normative Ableitung) mitführen
SCHEMA_SRC="$(dirname "$SRC")/50-spezifikation/schema"
if [[ -d "$SCHEMA_SRC" ]]; then
    rsync -a --delete "$SCHEMA_SRC"/ testsuite/schema/
fi

echo "Testsuite synchronisiert aus: $SRC"
echo "Fixtures: $(find testsuite/fixtures -name '*.json' | wc -l | tr -d ' ')"
