# testing/scenarios/ — language-neutral CLI scenarios

One format, one runner, **both** implementations. Each file is a list of CLI calls with
pinned expectations; the Node `walkthrough.test.ts` and the PHP `WalkthroughTest.php` read
these very files, so a difference between the languages turns one of them red.

```
testing/scenarios/
  walkthrough/   the user documentation in executable form — one per shipped configuration
  regression/    fixed defects, pinned so they cannot come back
```

| | `walkthrough/` | `regression/` |
|---|---|---|
| purpose | prove the handbook is true | prove a bug stays dead |
| audience | a reader following `docs/handbuch/cli-walkthrough.md` | the build |
| add one when | a new pack or configuration ships | a defect is fixed |
| may contain nonsense input | no — every step is exemplary | **yes, that is the point** |

A forged tax tag or a `1.5e+21` amount has no business on a page someone copies from; a
guard that only exercises the happy path guards nothing. Hence the split — but both live
here, not in `docs/`, because they are tests.

**Coverage rule:** every shipped pack must have a walkthrough scenario. A guard test fails
otherwise — a pack without one is an untested offer.

## What these cover that the conformance fixtures cannot

`testing/testsuite/` drives the **core** directly with a fixed clock. These drive the **binary** a
user types: the CLI surface, argument handling, the workspace files, pack-library loading,
and the documented parameter names. Both are needed; neither replaces the other.

Full picture of where tests live: [`../README.md`](../README.md).

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
    },
    {
      "name": "close all twelve periods",
      "repeat": { "over": "period", "values": [1, 2, 3] },   // one run per value
      "op": "closePeriod",
      "input": { "fiscalYear": 2026, "period": "$period" },
      "expect": { "status": "closed" }
    }
  ]
}
```

**Paths** in `capture` and `expect` are dotted with bracket indices:
`openItemsCreated[0].id`, `keys.81.tax`, `rows[2].balance`. A path that does not resolve is
a failure, not a skip.

**Comparison** is by JSON value. Amounts are strings (`"1190.00"`), so a rounding change
fails loudly rather than comparing 1190 to 1190.0.

**Deliberately not pinned:** UUIDs and timestamps. The CLI runs on the system clock and
UUIDv7, which is why steps `capture` ids instead of hard-coding them. Byte-identical output
across languages is the conformance suite's job; it runs with a fixed clock.

## Running them

```bash
cd implementations/node && pnpm vitest run packages/cli/test/walkthrough.test.ts
cd implementations/php  && vendor/bin/phpunit --filter WalkthroughTest
```

Both run as part of the normal test suites, so `pnpm test` and `make test` include them.
