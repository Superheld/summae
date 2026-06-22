# @superheld/summae-cli

Terminal tool (Node) for [summae](../core) — JSON in, JSON out, with a
persistent SQLite workspace (via [`@superheld/summae-knex`](../knex)).
Counterpart to the PHP CLI; same operations, same data format.

```bash
npm install -g @superheld/summae-cli

summae init --name "Example Ltd" --rules rules.json    # summae.json + summae.sqlite
summae op postVoucher --input @voucher.json             # write operation (or --input '{…}')
summae report trialBalance --params '{"fiscalYear":2026}'
```

`summae.json` carries tenant meta + pack data (app layer); `summae.sqlite`
the posting data. Each call loads the tenant, runs, and the DB persists.
Exit codes map the error catalog (0 = success).

Full API + data format: **[central handbook](https://github.com/Superheld/summae/blob/main/docs/handbuch/README.md)**.
