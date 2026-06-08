# superheld/summae-cli

Eigenständiges Kommandozeilen-Werkzeug (`summae`) für die Rechnungswesen-Bibliothek.
Alle Ein- und Ausgaben sind **JSON** — Zielnutzer ist ein Mensch *oder ein
LLM-Operator*, der Buchhaltung über `op`/`report` bedient. Exit-Codes
entsprechen den Fehlercodes der API.

> Die CLI ist die **PHP-Implementierung mit Terminal-Oberfläche**, kein
> backend-agnostischer Client. Sie braucht eine PHP-Runtime und persistiert in
> einen eigenen Arbeitsbereich (SQLite). Andere Backends (Node, Python) bringen
> später ihre eigene, gleichsprachige CLI mit.

## Voraussetzungen

- PHP ≥ 8.3
- Schreibrechte im Arbeitsverzeichnis (für `summae.json` + SQLite-Datei)

## Konfiguration

Die CLI braucht **keine Datenbank-Zugangsdaten** — sie legt einen lokalen
Arbeitsbereich an:

| Datei | Inhalt |
|---|---|
| `summae.json` | Mandanten-Meta (Name, Währung, `tenantId`) + Regelmodul-Daten (Konten, Steuerschlüssel, Mappings, …) |
| `summae.sqlite` | die Buchungsdaten (Eloquent/SQLite) |

Beide entstehen im Arbeitsverzeichnis (`--dir`, Default: aktuelles Verzeichnis).

## Benutzung

```bash
# Arbeitsbereich anlegen — Regeldatei trägt Konten, Geschäftsjahre, Steuerschlüssel
summae init --name "Muster GmbH" --currency EUR --rules regeln.json --dir ./buchhaltung

# Schreiboperation (SF-02: Beleg + Steuerexpansion + Buchung in einem Aufruf)
summae op postVoucher --dir ./buchhaltung --input '{
  "voucher": {"voucherNumber": "AR-001", "voucherDate": "2026-02-10"},
  "entryDate": "2026-02-10", "text": "Beratung",
  "taxCode": "USt19", "direction": "output",
  "netLines": [{"account": "8400", "money": {"amount": "1000.00", "currency": "EUR"}}],
  "counterAccount": "1200"
}'

# Projektion
summae report trialBalance --dir ./buchhaltung --params '{"fiscalYear": 2026, "throughPeriod": 12}'
```

`--input`/`--params` akzeptieren JSON direkt oder `@datei.json`. Bei Fehlern
gibt die CLI `{"error": "E_…", "message": …, "details": …}` aus und beendet mit
einem Exit-Code ≥ 10 (stabile Abbildung des Fehlerkatalogs, siehe
`src/ExitCodes.php`).

## Lizenz

MIT — siehe [LICENSE](../../LICENSE).
