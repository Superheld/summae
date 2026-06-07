#!/usr/bin/env python3
"""Fixture-Validierung + Abdeckungsbericht. Aufruf: python3 validate.py"""
import json, pathlib, re, sys
from decimal import Decimal

BASE = pathlib.Path(__file__).parent
ok = True
all_covers, all_errors = set(), set()

for f in sorted((BASE / "fixtures").rglob("*.json")):
    try:
        d = json.loads(f.read_text())
        missing = [k for k in ["fixture", "description", "covers", "setup", "steps"] if k not in d]
        if missing:
            print(f"FEHLT {f.name}: {missing}"); ok = False
        all_covers.update(d.get("covers", []))
        for s in d.get("steps", []):
            if "error" in s.get("expect", {}):
                all_errors.add(s["expect"]["error"])
            # Soll=Haben nur prüfen, wenn die Buchung angenommen werden soll
            if s["op"] == "post" and "lines" in s.get("input", {}) and "result" in s.get("expect", {}):
                deb = sum(Decimal(l["money"]["amount"]) for l in s["input"]["lines"] if l["side"] == "debit")
                cred = sum(Decimal(l["money"]["amount"]) for l in s["input"]["lines"] if l["side"] == "credit")
                if deb != cred:
                    print(f"FEHLER {f.name}: angenommene Buchung unbalanciert ({deb}/{cred})"); ok = False
        print(f"OK   {f.relative_to(BASE)}")
    except Exception as e:
        print(f"FAIL {f.name}: {e}"); ok = False

katalog = (BASE / "../50-spezifikation/fehlerkatalog.md").read_text()
defined = set(re.findall(r"`(E_[A-Z_]+)`", katalog))
undefined = sorted(all_errors - defined)
uncovered = sorted(defined - all_errors)
print(f"\nFehlercodes: {len(all_errors)} mit Fixture / {len(defined)} im Katalog")
if uncovered: print("  ohne Fixture:", ", ".join(uncovered))
if undefined: print("  NICHT IM KATALOG:", ", ".join(undefined)); ok = False
sf = sorted(c for c in all_covers if c.startswith("SF-"))
print(f"Standardfälle abgedeckt: {', '.join(sf)}")
print("\nGESAMT:", "OK" if ok else "FEHLER")
sys.exit(0 if ok else 1)
