#!/usr/bin/env bash
#
# Synchronisiert die Konformitäts-Testsuite aus der Wissensbasis.
# Einbahnstraße: Wissensbasis -> testsuite/. Fixtures werden hier NIE editiert
# (Befunde gehören nach SPEC-FINDINGS.md, siehe RUNTIME-LEITFADEN).
#
# Quelle: $SUMMAE_TESTSUITE_SRC, sonst Auto-Suche nach ../Rechnungswesen*/70-testsuite
set -euo pipefail
cd "$(dirname "$0")/.."

SRC="${SUMMAE_TESTSUITE_SRC:-}"
if [[ -z "$SRC" ]]; then
    # summae als Geschwister der Wissensbasis (../Rechnungswesen*/70-testsuite)
    # ODER als Kind der Wissensbasis (../70-testsuite).
    for candidate in ../Rechnungswesen*/70-testsuite ../70-testsuite; do
        if [[ -d "$candidate" ]]; then
            SRC="$candidate"
            break
        fi
    done
fi

if [[ -z "$SRC" || ! -d "$SRC" ]]; then
    echo "FEHLER: Wissensbasis nicht gefunden. SUMMAE_TESTSUITE_SRC setzen, z. B.:" >&2
    echo "  SUMMAE_TESTSUITE_SRC='/pfad/zur/wissensbasis/70-testsuite' $0" >&2
    exit 1
fi

rsync -a --delete "$SRC"/ testsuite/

# Maschinenlesbares Schema (normative Ableitung) mitführen
SCHEMA_SRC="$(dirname "$SRC")/50-spezifikation/schema"
if [[ -d "$SCHEMA_SRC" ]]; then
    rsync -a --delete "$SCHEMA_SRC"/ testsuite/schema/
fi

# Fehlerkatalog (normativ) mitführen, damit validate.py die Code-Abdeckung
# auch im Spiegel prüft (sonst nur in der Wissensbasis auffindbar).
KATALOG_SRC="$(dirname "$SRC")/50-spezifikation/fehlerkatalog.md"
if [[ -f "$KATALOG_SRC" ]]; then
    cp "$KATALOG_SRC" testsuite/fehlerkatalog.md
fi

# Ausgelieferte Pack-Bibliothek (wählbare Packs + wiederverwendbare Module)
# mitführen — anders als testsuite/ ist das Produkt-Datenbestand, nicht Test.
PACKLIB_SRC="$(dirname "$SRC")/pack-library"
if [[ -d "$PACKLIB_SRC" ]]; then
    rsync -a --delete "$PACKLIB_SRC"/ pack-library/
    echo "Pack-Bibliothek synchronisiert: $(find pack-library -name '*.json' | wc -l | tr -d ' ') Dateien"
fi

echo "Testsuite synchronisiert aus: $SRC"
echo "Fixtures: $(find testsuite/fixtures -name '*.json' | wc -l | tr -d ' ')"
