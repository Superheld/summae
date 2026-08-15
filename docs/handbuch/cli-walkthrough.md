# CLI walkthrough — empty directory to closed fiscal year

A **task-oriented** companion to the [handbook](README.md). The handbook is the
reference — it answers *"what does `settle` take?"*. This page answers *"I have
an empty directory; how do I get to a bookkeeping year I can hand to an
auditor?"* — in the order the operations actually have to happen.

Everything below was executed against the shipped `de` pack; the outputs are
real CLI output, abridged only where marked with `…`. The same sequence is a
runnable script — [`examples/cli-walkthrough.sh`](examples/cli-walkthrough.sh)
— so this page can be re-verified against the engine instead of trusted:

```bash
cd implementations/node && SUMMAE="npx tsx packages/cli/src/summae.ts" \
  bash ../../docs/handbuch/examples/cli-walkthrough.sh
```

> **This page is gated.** The same lifecycle exists as data in
> [`scenarios/walkthrough/`](../../scenarios/README.md) — one scenario per
> configuration we ship (`de`, `us`, `default`, and a free `rules.json`) — and
> runs in **both** implementations' test suites with its numbers pinned.
> (Guards for *fixed defects* use the same machinery and live next door in
> `scenarios/regression/`, so this page stays exemplary.) If the
> engine stops behaving as described here, a build goes red rather than the
> documentation quietly going stale. The other three configurations are not
> written out below; read their scenario files for the differences (US sales
> tax and use tax, the tax-free base, your own chart of accounts).

**Who this is for.** Developers evaluating summae, and **AI agents driving it**.
The CLI is deliberately the smallest useful surface: three commands, JSON in,
JSON out, one stable exit code per error. That makes it the easiest way to
learn the domain model — and the easiest thing for an agent to automate,
because there is nothing to scrape and no interactive prompt to get stuck on.

**Contents**

1. [The whole surface](#1-the-whole-surface)
2. [Install and invoke](#2-install-and-invoke)
3. [Create the workspace](#3-create-the-workspace)
4. [Look around](#4-look-around)
5. [Post an outgoing invoice](#5-post-an-outgoing-invoice)
6. [Receive the payment and settle](#6-receive-the-payment-and-settle)
7. [Post an incoming invoice and reverse it](#7-post-an-incoming-invoice-and-reverse-it)
8. [Read the books](#8-read-the-books)
9. [Close periods and the fiscal year](#9-close-periods-and-the-fiscal-year)
10. [Export](#10-export)
11. [Errors and exit codes](#11-errors-and-exit-codes)
12. [Parameter cheat sheet](#12-parameter-cheat-sheet)

---

## 1. The whole surface

There are exactly three commands. Everything else is an operation or
projection *name* — the same names in both languages, listed in the handbook
§ 6 and § 7.

| Command | Purpose | Payload flag |
|---|---|---|
| `summae init` | create workspace (`summae.json` + `summae.sqlite`) | `--pack` / `--rules` |
| `summae op <operation>` | write operation (26 of them: `post`, `settle`, `reverse`, `closePeriod`, …) | `--input` |
| `summae report <projection>` | read-only projection (14 of them: `trialBalance`, `vatReturn`, `journalExport`, …) | `--params` |

Common to all three: `--dir <path>` selects the workspace (default: current
directory). `--input` / `--params` take **JSON inline or `@file`**:

```bash
summae op post --input @posting.json          # read from a file
summae op post --input '{"voucherId": "…"}'   # inline
```

Output is always **one line of JSON** on stdout — a result object on success,
`{"error", "message", "details"}` on a domain error. Nothing else is printed,
so `| jq` works unconditionally.

---

## 2. Install and invoke

```bash
# Node
npm install -g @superheld/summae-cli
summae --help

# PHP
composer global require superheld/summae-cli
summae --help
```

Inside this repository, without installing:

```bash
# Node (via tsx, no build needed)
cd implementations/node && npx tsx packages/cli/src/summae.ts <args>

# PHP
implementations/php/packages/cli/bin/summae <args>
```

The two CLIs take **identical** options and produce identical results (that is
the point of the conformance suite). This page uses `summae` throughout;
substitute your invocation.

---

## 3. Create the workspace

A tenant needs a **pack**: the chart of accounts, tax codes, mappings and
depreciation rules of one jurisdiction. Pick a shipped one rather than
hand-maintaining a `rules.json`:

```bash
summae init --name "Mustermann Consulting" --pack de --first-fiscal-year 2026 --dir ./accounting
```

```json
{"initialized":true,"tenant":"Mustermann Consulting","baseCurrency":"EUR","created":{"accounts":40,"fiscalYears":1}}
```

That single call resolved the `de` pack, created 40 accounts and the fiscal
year 2026 with its twelve monthly periods. Two files now exist:

| File | Content |
|---|---|
| `summae.json` | tenant metadata + the resolved pack data |
| `summae.sqlite` | the journal and everything derived from it |

The pack choice is **pinned at creation**. Shipped packs: `default`
(account-less base), `de`, `us`. Your own rules go through
`--rules rules.json` instead (handbook § 5).

> **The pack does not set the currency.** `--currency` defaults to `EUR`
> regardless of pack, so `init --pack us` without `--currency USD` gives you a
> US chart of accounts booked in euros. Set both.

> **Fiscal year is not optional.** Without `--first-fiscal-year` you get a
> tenant you cannot post into — every posting resolves its period from
> `entryDate`, and a date outside any fiscal year is `E_PERIOD_UNKNOWN`. You
> can add years later with `op createFiscalYear`.

---

## 4. Look around

Before the first posting, the trial balance is genuinely empty — not "all
zeroes", but no rows, because balances are computed from the journal and there
is no journal yet:

```bash
summae report trialBalance --params '{"fiscalYear":2026}'
```
```json
{"rows":[]}
```

To see what the pack gave you, read `summae.json` — `rules.accounts` (the chart
of accounts), `rules.taxCodes` (`USt19`, `USt7`, `VSt19`, `VSt7`, `RC13b`,
`igL`, `USt19WA` for the `de` pack), and `rules.taxProfile`:

```json
{"taxationMethod":"cash","smallBusiness":false,"vatPeriod":"quarterly"}
```

`taxationMethod: "cash"` matters for everything that follows: VAT becomes due
when money moves, not when the invoice is written. The accounts you need below
are `1200` Bank, `1400` receivables, `3000` payables, `3100` output VAT, `4000`
revenue, `6000` other operating expense.

---

## 5. Post an outgoing invoice

`postVoucher` is the one-call standard case: it creates the voucher, expands
the tax from **net** lines, and posts — all in one step. You supply the net
revenue line and the contra account; the gross line and the tax line are
derived.

```bash
summae op postVoucher --input '{
  "voucher": { "voucherNumber": "AR-001", "voucherDate": "2026-02-10" },
  "entryDate": "2026-02-10",
  "text": "Consulting February",
  "taxCode": "USt19",
  "direction": "output",
  "netLines": [ { "account": "4000", "money": { "amount": "1000.00", "currency": "EUR" } } ],
  "counterAccount": "1400"
}'
```

```json
{"entry":{"id":"01a001ba-…","sequenceNumber":1,"status":"entered","entryDate":"2026-02-10",
  "periodRef":{"fiscalYear":2026,"period":2},
  "lines":[
    {"account":"1400","side":"debit","money":{"amount":"1190.00","currency":"EUR"},"taxTag":null},
    {"account":"4000","side":"credit","money":{"amount":"1000.00","currency":"EUR"},
     "taxTag":{"code":"USt19","appliedVersion":"2024-01-01","reportingKey":"81","baseMoney":{"amount":"1000.00","currency":"EUR"}}},
    {"account":"3100","side":"credit","money":{"amount":"190.00","currency":"EUR"},"taxTag":{…}}],
  "reverses":null,"reversedBy":null},
 "openItemsCreated":[{"id":"01a001ba-…","kind":"receivable","money":{"amount":"1190.00","currency":"EUR"},
   "remaining":{"amount":"1190.00","currency":"EUR"},"status":"open","settlements":[]}],
 "grossTotal":{"amount":"1190.00","currency":"EUR"},
 "voucherId":"01a001ba-…"}
```

Four things happened that are worth naming, because they are the model:

- **The tax line was derived, not supplied.** `1000.00 × 19 %` came from the
  tax code's version valid on the voucher date (`appliedVersion`).
- **Every tax-relevant line carries a `taxTag`** with its `reportingKey` (`81`).
  The VAT return is built from these tags, never from account numbers.
- **An open item appeared by itself.** Debit on an `ar`-subtype account ⇒
  receivable. You do not create open items; you settle them.
- **The posting is `entered`, not `finalized`.** It can still be corrected.

Note the `entry.id` and the open item's `id` — the next steps need both. IDs
are UUIDv7 and differ on every run; an agent should read them from the JSON,
never hard-code them.

---

## 6. Receive the payment and settle

A payment is a plain posting, so it needs a voucher of its own first. This is
the one place the reference is currently thin: `createVoucher` takes the
voucher fields **nested under `voucher`**, exactly like `postVoucher`:

```bash
summae op createVoucher --input '{"voucher":{"voucherNumber":"BK-001","voucherDate":"2026-03-05"}}'
```
```json
{"id":"01a001ba-afda-…","voucherNumber":"BK-001"}
```

Now the bank posting — gross, no tax expansion, because the tax was already
tagged on the invoice:

```bash
summae op post --input '{
  "voucherId": "01a001ba-afda-…",
  "entryDate": "2026-03-05",
  "text": "Payment received AR-001",
  "lines": [
    { "account": "1200", "side": "debit",  "money": { "amount": "1190.00", "currency": "EUR" } },
    { "account": "1400", "side": "credit", "money": { "amount": "1190.00", "currency": "EUR" } }
  ]
}'
```
```json
{"id":"01a001ba-b131-…","sequenceNumber":2,"status":"entered","periodRef":{"fiscalYear":2026,"period":3}, …}
```

The books are now correct, but the *open item* is not: nothing has told summae
which receivable this money pays. That link is explicit — `settle` allocates a
payment posting to one or more open items:

```bash
summae op settle --input '{
  "entryId": "01a001ba-b131-…",
  "allocations": [ { "openItemId": "01a001ba-2b4f-…", "money": { "amount": "1190.00", "currency": "EUR" } } ]
}'
```
```json
{"openItems":[{"id":"01a001ba-2b4f-…","kind":"receivable","money":{"amount":"1190.00","currency":"EUR"},
 "remaining":{"amount":"0.00","currency":"EUR"},"status":"settled",
 "settlements":[{"entryId":"01a001ba-b131-…","money":{"amount":"1190.00","currency":"EUR"},"settledAt":"2026-03-05","difference":null}]}]}
```

```bash
summae report openItems --params '{}'   # → {"items":[]}
```

**Why the extra step matters.** Under cash-basis taxation the VAT return is
driven by settlements, not by postings — an invoice you never allocated stays
invisible to the return no matter how the bank account looks. Partial payments
and cash discounts also go here (`difference: {money, kind}` with kind
`discount` / `bad_debt` / `minor`).

---

## 7. Post an incoming invoice and reverse it

Same call, mirrored: `direction: "input"`, an input tax code, expense account,
payables as contra.

```bash
summae op postVoucher --input '{
  "voucher": { "voucherNumber": "ER-001", "voucherDate": "2026-03-12" },
  "entryDate": "2026-03-12", "text": "Office supplies",
  "taxCode": "VSt19", "direction": "input",
  "netLines": [ { "account": "6000", "money": { "amount": "200.00", "currency": "EUR" } } ],
  "counterAccount": "3000"
}'
```
```json
{"entry":{"id":"01a001ba-e7e3-…", …},"grossTotal":{"amount":"238.00","currency":"EUR"}, …}
```

Say it was booked in error. **The journal is append-only — nothing is deleted
or edited.** A reversal is a new posting that mirrors the original with negated
amounts and a back-reference:

```bash
summae op reverse --input '{
  "entryId": "01a001ba-e7e3-…", "entryDate": "2026-03-20", "text": "Reversal office supplies"
}'
```
```json
{"id":"01a001ba-e943-…","sequenceNumber":4,"reverses":"01a001ba-e7e3-…",
 "lines":[{"account":"3000","side":"credit","money":{"amount":"-238.00","currency":"EUR"}},
          {"account":"6000","side":"debit","money":{"amount":"-200.00","currency":"EUR"},"taxTag":{"code":"VSt19", …}},
          {"account":"1500","side":"debit","money":{"amount":"-38.00","currency":"EUR"},"taxTag":{"code":"VSt19", …}}]}
```

Note: **same side, negative amount** — not the opposite side. Debit and credit
totals per account therefore stay intact for the audit trail, and the original
entry gets `reversedBy` set. A second reversal of the same entry is
`E_ENTRY_ALREADY_REVERSED`.

Use `reverse` when the posting is wrong and already final; use `correct` to
change text or lines of an entry still in status `entered`.

---

## 8. Read the books

Every projection recomputes from the journal. There is no cache to invalidate
and no "rebuild balances" step.

```bash
summae report trialBalance --params '{"fiscalYear":2026}'
```
```json
{"rows":[{"account":"1200","openingBalance":"0.00","debitTotal":"1190.00","creditTotal":"0.00","balance":"1190.00"},
         {"account":"1400","openingBalance":"0.00","debitTotal":"1190.00","creditTotal":"1190.00","balance":"0.00"},
         {"account":"3100","openingBalance":"0.00","debitTotal":"0.00","creditTotal":"190.00","balance":"-190.00"},
         {"account":"4000","openingBalance":"0.00","debitTotal":"0.00","creditTotal":"1000.00","balance":"-1000.00"}, …]}
```

An account ledger for one account:

```bash
summae report accountSheet --params '{"account":"1200","fiscalYear":2026}'
```
```json
{"account":"1200","name":"Bank","openingBalance":"0.00",
 "lines":[{"sequenceNumber":2,"entryDate":"2026-03-05","text":"Payment received AR-001","side":"debit",
           "money":{"amount":"1190.00","currency":"EUR"},"runningBalance":"1190.00"}],
 "closingBalance":"1190.00"}
```

**Statement-style projections need a mapping** — the pack ships one per report
shape (`de-guv`, `de-bilanz`, `de-euer`). Omitting it is an error, not a
default:

```bash
summae report incomeStatement --params '{"fiscalYear":2026,"mapping":"de-guv"}'
summae report balanceSheet    --params '{"fiscalYear":2026,"mapping":"de-bilanz"}'
```
```json
{"positions":[{"key":"1","label":"Umsatzerlöse","amount":"1000.00"}, …],"netIncome":"1000.00"}
{"assets":[{"key":"A.III","label":"Kassenbestand und Guthaben bei Kreditinstituten","amount":"1190.00"}, …],
 "assetsTotal":"1190.00",
 "liabilitiesAndEquity":[{"key":"P.A2","label":"Jahresergebnis","amount":"1000.00"},
                         {"key":"P.C","label":"Verbindlichkeiten","amount":"190.00"}],
 "liabilitiesAndEquityTotal":"1190.00"}
```

The VAT return takes **`year` and `quarter`** (not `fiscalYear`/`period` — see
§ 12):

```bash
summae report vatReturn --params '{"year":2026,"quarter":1}'
```
```json
{"keys":{"66":{"base":"-200.00","tax":"-38.00"},"81":{"base":"1000.00","tax":"190.00"}},
 "payload":{"amount":"228.00","currency":"EUR"}}
```

Key `81` carries the settled invoice — cash-basis, so it appears in the quarter
of the *payment* (March), not of the invoice (February). Bases are floored to
full units, tax is exact to the cent, and `payload` nets output against input
tax.

The cash-basis report takes **`year`**:

```bash
summae report cashBasisReport --params '{"year":2026}'
```
```json
{"income":[{"category":"Erlöse Regelsatz","amount":"1000.00"}],"expenses":[]}
```

---

## 9. Close periods and the fiscal year

Closing has a fixed order, and each step has a precondition. Skipping one
gives you a specific error rather than a silent partial close.

**Finalize** first — postings must leave status `entered`:

```bash
summae op finalize --input '{"finalizeUntil":"2026-12-31"}'   # → {"finalizedCount":4}
```

**Close the periods**, in order, oldest first (`E_PERIOD_OUT_OF_ORDER`
otherwise):

```bash
for p in $(seq 1 12); do
  summae op closePeriod --input "{\"fiscalYear\":2026,\"period\":$p}"
done
```
```json
{"fiscalYear":2026,"period":1,"status":"closed"}
…
{"fiscalYear":2026,"period":12,"status":"closed"}
```

**Close the year** — a pure status change; summae writes **no** closing
entries, because which closing entries are correct is a jurisdiction question
and belongs to the embedding application:

```bash
summae op closeFiscalYear --input '{"fiscalYear":2026}'
```
```json
{"fiscalYear":2026,"status":"closed"}
```

The lock is real:

```bash
summae op post --input '{"voucherId":"…","entryDate":"2026-04-01","lines":[…]}'
```
```json
{"error":"E_PERIOD_CLOSED","message":"Period 2026/4 is closed","details":{"fiscalYear":2026,"period":4}}
```
exit code `18`.

`reopenPeriod` exists for the case where you closed too early.

---

## 10. Export

Three export formats, each for a different audience. They are projections, so
they never mutate anything and can be re-run at will.

```bash
summae report journalExport   --params '{"fiscalYear":2026}'   # GoBD Z3 (German tax audit)
summae report datevExport     --params '{"fiscalYear":2026}'   # DATEV (German tax advisors)
summae report auditDataExport --params '{"fiscalYear":2026}'   # AICPA Audit Data Standard (US)
summae report auditLog        --params '{}'                    # change history
```

`journalExport` returns a manifest with a **SHA-256 content hash per stream**,
plus a field catalogue describing every column — that is what makes the export
self-describing for an auditor:

```json
{"manifest":{"formatVersion":"0.4","tenantId":"…","tenantName":"Mustermann Consulting","baseCurrency":"EUR",
  "exportedAt":"…","hashAlgorithm":"sha256","streams":["journal","accounts","vouchers","auditLog"],
  "contentHashes":{"journal":"ad3bb48f…","accounts":"17d2706c…","vouchers":"55234373…","auditLog":"c9a321c2…"}},
 "fieldCatalogIncluded":true,"fieldCatalog":{"journal":[{"name":"id","type":"uuid","meaning":"Eindeutige Buchungs-ID"}, …]}}
```

> **Language note.** `journalExport` and `datevExport` emit **German** field
> descriptions on purpose — they are German standards read by German auditors.
> `auditDataExport` is the US counterpart with AICPA field names and **signed
> amounts** (debit positive, credit negative). This is deliberate, not an
> untranslated leftover.

---

## 11. Errors and exit codes

Domain errors are data, not stack traces:

```json
{"error":"E_PERIOD_CLOSED","message":"Period 2026/4 is closed","details":{"fiscalYear":2026,"period":4}}
```

- **stdout** stays parseable JSON.
- **the exit code** is a stable number per error code: `0` success, `1`
  unknown, otherwise position in the error catalogue `+ 10`
  (`E_ENTRY_UNBALANCED` = 10, `E_PERIOD_CLOSED` = 18, …). The order is
  **append-only** — new codes get appended, existing numbers never move.
- **only the first error** is reported when posting, in a fixed check order:
  structure → references → balance → time. Fix and re-run.

For an agent this means: branch on `error`, not on the message text, and treat
a **non-JSON stdout as a bug report**, not as an error you should retry.

The full catalogue of 35 codes is in handbook § 9.

---

## 12. Parameter cheat sheet

Projection parameter names are **not** uniform, and getting one wrong is the
most likely way to see an empty or wrong-looking report. The authoritative
names:

| Projection | Period parameters |
|---|---|
| `trialBalance`, `accountSheet`, `incomeStatement`, `balanceSheet`, `journalExport`, `datevExport`, `auditDataExport` | `fiscalYear` |
| `vatReturn` | **`year`** + **`quarter`** (optional `asOf`) |
| `cashBasisReport` | **`year`** |
| `incomeStatement`, `balanceSheet` | additionally **`mapping`** (required) |
| `accountSheet` | additionally `account` |

Three traps worth stating plainly:

- **`vatReturn` with `fiscalYear`/`period` is not an error** — the parameters
  are simply ignored, `quarter` defaults to 0 = whole year, and you get a
  plausible-looking annual figure where you expected a quarter. Always pass
  `year` + `quarter`.
- **A wrong or missing `fiscalYear` yields `{"rows":[]}`**, not an error — the
  same shape an empty ledger produces. Empty output is not proof that the books
  are empty; check the parameter first.
- **`cashBasisReport` without `year`** currently raises an uncaught
  `InvalidValue` rather than a domain error (see SPEC-FINDINGS). Pass `year`.

---

## Where to go next

- **Reference for every field and error** — [handbook](README.md) § 6–9.
- **Embedding in an application** (Laravel / Knex adapters, in-memory for
  tests) — handbook § 3.
- **Building or adapting a pack** — [`pack-library/README.md`](../../pack-library/README.md).
- **Why the core knows no law** — [`docs/architektur.md`](../architektur.md).
