#!/usr/bin/env bash
#
# Runnable companion to docs/handbuch/cli-walkthrough.md — the full lifecycle
# in one script: init → outgoing invoice → payment → settle → incoming invoice
# → reversal → reports → close → export.
#
# Every command below is exactly what the walkthrough shows. Run it to verify
# the documentation still matches the engine; the doc is written against this
# script's real output.
#
# Usage:
#   ./cli-walkthrough.sh                 # uses the `summae` on your PATH
#   SUMMAE="npx tsx packages/cli/src/summae.ts" ./cli-walkthrough.sh
#                                        # …from implementations/node
#   SUMMAE=implementations/php/packages/cli/bin/summae ./cli-walkthrough.sh
#
# Requires: jq.

set -euo pipefail

SUMMAE=${SUMMAE:-summae}
WORKDIR=$(mktemp -d)
trap 'rm -rf "$WORKDIR"' EXIT

# One CLI call. Prints the command, then the JSON result.
s() {
  echo "\$ summae $*" >&2
  # shellcheck disable=SC2086
  $SUMMAE "$@" --dir "$WORKDIR"
}

echo "== 1. workspace ==" >&2
s init --name "Mustermann Consulting" --pack de --first-fiscal-year 2026 | jq -c .

echo "== 2. empty trial balance ==" >&2
s report trialBalance --params '{"fiscalYear":2026}' | jq -c .

echo "== 3. outgoing invoice (net + tax expansion in one call) ==" >&2
invoice=$(s op postVoucher --input '{
  "voucher": { "voucherNumber": "AR-001", "voucherDate": "2026-02-10" },
  "entryDate": "2026-02-10", "text": "Consulting February",
  "taxCode": "USt19", "direction": "output",
  "netLines": [ { "account": "4000", "money": { "amount": "1000.00", "currency": "EUR" } } ],
  "counterAccount": "1400" }')
echo "$invoice" | jq -c '{grossTotal, sequenceNumber: .entry.sequenceNumber, openItems: .openItemsCreated | length}'
openItem=$(echo "$invoice" | jq -r '.openItemsCreated[0].id')

echo "== 4. payment: voucher, posting, settlement ==" >&2
paymentVoucher=$(s op createVoucher --input '{"voucher":{"voucherNumber":"BK-001","voucherDate":"2026-03-05"}}' | jq -r .id)
payment=$(s op post --input "{
  \"voucherId\": \"$paymentVoucher\", \"entryDate\": \"2026-03-05\", \"text\": \"Payment received AR-001\",
  \"lines\": [
    { \"account\": \"1200\", \"side\": \"debit\",  \"money\": { \"amount\": \"1190.00\", \"currency\": \"EUR\" } },
    { \"account\": \"1400\", \"side\": \"credit\", \"money\": { \"amount\": \"1190.00\", \"currency\": \"EUR\" } } ] }")
paymentEntry=$(echo "$payment" | jq -r .id)

s op settle --input "{
  \"entryId\": \"$paymentEntry\",
  \"allocations\": [ { \"openItemId\": \"$openItem\", \"money\": { \"amount\": \"1190.00\", \"currency\": \"EUR\" } } ] }" \
  | jq -c '.openItems[0] | {status, remaining}'

s report openItems --params '{}' | jq -c .

echo "== 5. incoming invoice, then reversal ==" >&2
expense=$(s op postVoucher --input '{
  "voucher": { "voucherNumber": "ER-001", "voucherDate": "2026-03-12" },
  "entryDate": "2026-03-12", "text": "Office supplies",
  "taxCode": "VSt19", "direction": "input",
  "netLines": [ { "account": "6000", "money": { "amount": "200.00", "currency": "EUR" } } ],
  "counterAccount": "3000" }')
expenseEntry=$(echo "$expense" | jq -r '.entry.id')

s op reverse --input "{\"entryId\":\"$expenseEntry\",\"entryDate\":\"2026-03-20\",\"text\":\"Reversal office supplies\"}" \
  | jq -c '{sequenceNumber, reverses, lines: [.lines[] | {account, side, amount: .money.amount}]}'

echo "== 6. reports (mind the parameter names) ==" >&2
s report trialBalance     --params '{"fiscalYear":2026}' | jq -c '.rows | map(select(.balance != "0.00"))'
s report accountSheet     --params '{"account":"1200","fiscalYear":2026}' | jq -c '{account, closingBalance}'
s report incomeStatement  --params '{"fiscalYear":2026,"mapping":"de-guv"}' | jq -c '{netIncome}'
s report balanceSheet     --params '{"fiscalYear":2026,"mapping":"de-bilanz"}' | jq -c '{assetsTotal, liabilitiesAndEquityTotal}'
s report vatReturn        --params '{"year":2026,"quarter":1}' | jq -c .          # year + quarter, NOT fiscalYear/period
s report ecSalesList      --params '{"year":2026,"quarter":1}' | jq -c .          # intra-community supplies only
s report cashBasisReport  --params '{"year":2026}' | jq -c .                      # year, NOT fiscalYear

echo "== 7. close: finalize → periods in order → fiscal year ==" >&2
s op finalize --input '{"finalizeUntil":"2026-12-31"}' | jq -c .
for p in $(seq 1 12); do
  s op closePeriod --input "{\"fiscalYear\":2026,\"period\":$p}" > /dev/null
done
echo '{"periods":"1-12 closed"}'
s op closeFiscalYear --input '{"fiscalYear":2026}' | jq -c .

echo "== 8. the lock is real (expect E_PERIOD_CLOSED, exit 18) ==" >&2
lockedVoucher=$(s op createVoucher --input '{"voucher":{"voucherNumber":"X","voucherDate":"2026-04-01"}}' | jq -r .id)
set +e
s op post --input "{
  \"voucherId\": \"$lockedVoucher\", \"entryDate\": \"2026-04-01\",
  \"lines\": [
    { \"account\": \"1200\", \"side\": \"debit\",  \"money\": { \"amount\": \"10.00\", \"currency\": \"EUR\" } },
    { \"account\": \"4000\", \"side\": \"credit\", \"money\": { \"amount\": \"10.00\", \"currency\": \"EUR\" } } ] }" | jq -c .
echo "exit code: $?" >&2
set -e

echo "== 9. exports ==" >&2
s report journalExport   --params '{"fiscalYear":2026}' | jq -c '.manifest | {formatVersion, streams}'
s report datevExport     --params '{"fiscalYear":2026}' | jq -c '{kind, rows: (.rows | length)}'
s report auditDataExport --params '{"fiscalYear":2026}' | jq -c '{standard, journals: (.journals | length)}'
s report auditLog        --params '{}' | jq -c '{records: (.records | length)}'

echo "== 10. what the entity IS, and when a resolution on the result is due ==" >&2
s op setEntityProfile --input '{"legalForm":"gmbh","sizeClass":"small"}' | jq -c .
s report unappropriatedResult --params '{}' | jq -c \
  '{unappropriated, resolutionRequired, resolutionBasis, dueBy: .byFiscalYear[0].resolutionDueBy}'

echo "== done ==" >&2
