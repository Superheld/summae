# @superheld/summae-cli

Terminal-Werkzeug (Node) für [summae](../core) — JSON rein, JSON raus, mit
persistentem SQLite-Arbeitsbereich (über [`@superheld/summae-knex`](../knex)).
Pendant zur PHP-CLI; gleiche Operationen, gleiches Datenformat.

```bash
npm install -g @superheld/summae-cli

summae init --name "Muster GmbH" --rules regeln.json   # summae.json + summae.sqlite
summae op postVoucher --input @beleg.json               # Schreiboperation (oder --input '{…}')
summae report trialBalance --params '{"fiscalYear":2026}'
```

`summae.json` trägt Mandanten-Meta + Regelmodul-Daten (App-Schicht); `summae.sqlite`
die Buchungsdaten. Jeder Aufruf lädt den Mandanten, führt aus, die DB persistiert.
Exit-Codes bilden den Fehlerkatalog ab (0 = Erfolg).

Vollständige API + Datenformat: **[zentrales Handbuch](https://github.com/Superheld/summae/blob/main/docs/handbuch/README.md)**.
