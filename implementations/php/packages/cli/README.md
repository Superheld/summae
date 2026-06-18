# superheld/summae-cli

Eigenständiges Kommandozeilen-Werkzeug (`summae`) für summae. Alle Ein- und
Ausgaben sind **JSON** — Zielnutzer ist ein Mensch oder ein LLM-Operator.
Exit-Codes entsprechen den Fehlercodes der API. Persistiert in einen lokalen
Arbeitsbereich (SQLite, via Eloquent-Adapter).

```bash
composer require superheld/summae-cli

summae init   --name "Muster GmbH" --currency EUR --rules regeln.json --dir ./buchhaltung
summae op     postVoucher --dir ./buchhaltung --input @beleg.json
summae report trialBalance --dir ./buchhaltung --params '{"fiscalYear":2026,"throughPeriod":12}'
```

`--input` / `--params` akzeptieren JSON direkt oder `@datei.json`.

**📖 Vollständige Dokumentation** — Arbeitsbereich, Regeldatei, komplette
API-Referenz (alle Operationen & Projektionen), Fehlerkatalog:
**[summae-Handbuch](../../../../docs/handbuch/README.md)**.

Lizenz: MIT — siehe [LICENSE](../../LICENSE).
