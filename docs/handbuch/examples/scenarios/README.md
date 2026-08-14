# Walkthrough scenarios — the gated form of the documentation

Each file here is one **complete bookkeeping lifecycle**, expressed as data:
workspace → invoices → payment and settlement → reversal → reports → period and
fiscal-year close → export. They are the machine-checkable counterpart to
[`../../cli-walkthrough.md`](../../cli-walkthrough.md), and they run in **both**
implementations' green gates. A documented behaviour that stops being true
turns a gate red instead of quietly rotting on the page.

One scenario per **configuration we currently offer**:

| File | Configuration | Exercises |
|---|---|---|
| `de.json` | `--pack de` | cash-basis VAT, `standard` + `intra_community_supply` mechanisms, GoBD/DATEV exports, EÜR |
| `us.json` | `--pack us --currency USD` | accrual, `standard` + `reverse_charge` + `exempt` mechanisms, AICPA ADS export, Schedule C |
| `default.json` | `--pack default` | the account-less base: no tax codes, no mappings — proves the substrate stands without any jurisdiction |
| `custom.json` | `--rules custom-rules.json` | free configuration: own chart of accounts, own tax code, own mapping, without any shipped pack |

## Format

```jsonc
{
  "id": "de",
  "init": { "pack": "de", "currency": "EUR", "firstFiscalYear": 2026 },
  "expect": { "created.accounts": 40 },          // on the init result
  "steps": [
    {
      "name": "outgoing invoice",                 // shown on failure
      "op": "postVoucher",                        // "op" or "report"
      "input": { … },                             // or "params" for a report
      "capture": { "invoice": "entry.id" },       // remember values for later steps
      "expect": { "grossTotal.amount": "1190.00" }
    },
    {
      "name": "posting into a closed period",
      "op": "post",
      "input": { "voucherId": "$invoice", … },    // "$name" resolves a captured value
      "expectError": "E_PERIOD_CLOSED",           // instead of "expect"
      "expectExitCode": 18
    }
  ]
}
```

**Paths** in `capture` and `expect` are dotted with bracket indices:
`openItemsCreated[0].id`, `keys.81.tax`, `rows[2].balance`. A path that does not
resolve is a failure, not a skip.

**Comparison** is by JSON value. Amounts are strings (`"1190.00"`), so a
rounding change fails loudly rather than comparing 1190 to 1190.0.

**What is deliberately not pinned:** UUIDs and timestamps. The CLI runs on the
system clock and UUIDv7, so IDs differ per run — that is why steps `capture`
them instead of hard-coding them. Byte-identical output across languages is the
job of the conformance suite (`testsuite/`), which drives the core directly with
a fixed clock; these scenarios cover what it cannot reach: the CLI surface, the
workspace, the pack library, and the documented parameter names.

## Running them

They run as part of each implementation's test suite:

```bash
cd implementations/node && pnpm vitest run packages/cli/test/walkthrough.test.ts
cd implementations/php  && vendor/bin/phpunit packages/cli/tests/WalkthroughTest.php
```

## Adding a configuration

Ship a new pack ⇒ add a scenario. The list above is meant to stay in step with
what `pack-library/` offers; a pack without a scenario is an untested offer.
