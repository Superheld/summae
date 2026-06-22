# superheld/summae-cli

Standalone command-line tool (`summae`) for summae. All input and
output is **JSON** — the target user is a human or an LLM operator.
Exit codes correspond to the API's error codes. Persists to a local
workspace (SQLite, via the database adapter).

```bash
composer require superheld/summae-cli

summae init   --name "Example Ltd" --currency EUR --rules rules.json --dir ./accounting
summae op     postVoucher --dir ./accounting --input @voucher.json
summae report trialBalance --dir ./accounting --params '{"fiscalYear":2026,"throughPeriod":12}'
```

`--input` / `--params` accept JSON directly or `@file.json`.

**📖 Full documentation** — workspace, rule file, complete
API reference (all operations & projections), error catalog:
**[summae handbook](https://github.com/Superheld/summae/blob/main/docs/handbuch/README.md)**.

License: MIT — see [LICENSE](https://github.com/Superheld/summae/blob/main/implementations/php/LICENSE).
