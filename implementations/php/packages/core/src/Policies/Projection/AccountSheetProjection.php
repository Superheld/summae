<?php

declare(strict_types=1);

namespace Summae\Core\Policies\Projection;

use Summae\Core\DomainError;
use Summae\Core\Substrate\Side;
use Summae\Core\Port\AccountRepository;
use Summae\Core\Port\JournalRepository;
use Summae\Core\Substrate\AccountNumber;
use Summae\Core\Substrate\Currency;
use Summae\Core\Substrate\JournalEntry;
use Summae\Core\Substrate\Money;

/**
 * Account sheet: all movements of an account in the fiscal year with a
 * running balance. Opening balance = cumulative prior years for
 * balance-carrying accounts, null for income accounts (api.md period semantics).
 * Order: sequenceNumber — the only authoritative one (determinismus.md §3).
 *
 * Each line carries the **identity of its entry** and the accounts on the other side of it
 * (SPEC-021, reported by an embedding app as its F-31). Without the first, a screen that shows a
 * sheet and lets the reader open a line had to go looking: `journal` with `fromDate` and `toDate`
 * set to the same day, then filter the day's entries by `sequenceNumber` — a search where a lookup
 * belongs, for an entry whose identity the caller had two fields ago. Without the second, a
 * T-account cannot answer the question it raises on every line: *6000 in debit, against what?*
 *
 * `contraAccounts` is a **list** on purpose. For a plain entry it holds one account; as soon as a
 * tax code is involved it holds two or more, and a field called "the counter account" would have to
 * pick one and thereby invent a fact.
 */
final readonly class AccountSheetProjection
{
    public function __construct(
        private Currency $baseCurrency,
        private AccountRepository $accounts,
        private JournalRepository $journal,
    ) {
    }

    /**
     * @param array<string, mixed> $params account, fiscalYear, throughPeriod?
     *
     * @return array<string, mixed>
     */
    public function compute(array $params): array
    {
        // Both are documented as required. Defaulting them produced an authoritative-looking
        // empty ledger — the resolved account name next to "0.00" reads as a verified statement
        // about the account, not as "you forgot a parameter".
        if (!is_string($params['account'] ?? null) || $params['account'] === '') {
            throw new DomainError(
                'E_INPUT_INVALID',
                'accountSheet requires the parameter "account"',
                ['account' => DomainError::rejectedValue($params['account'] ?? null)],
            );
        }
        if (Parameters::asInteger($params['fiscalYear'] ?? null) === null) {
            throw new DomainError(
                'E_INPUT_INVALID',
                'accountSheet requires the parameter "fiscalYear"',
                ['fiscalYear' => DomainError::rejectedValue($params['fiscalYear'] ?? null)],
            );
        }
        $number = $params['account'];
        $fiscalYear = Parameters::integerOr($params['fiscalYear'], 0);
        $throughPeriod = Parameters::integerOr($params['throughPeriod'] ?? null, PHP_INT_MAX);

        $account = $this->accounts->byNumber(AccountNumber::of($number));
        if ($account === null) {
            throw new DomainError('E_ACCOUNT_UNKNOWN', sprintf('Account %s does not exist', $number));
        }

        $opening = Money::zero($this->baseCurrency);

        if ($account->type->isBalanceCarrying()) {
            foreach ($this->journal->all() as $entry) {
                if ($entry->periodRef->fiscalYear >= $fiscalYear) {
                    continue;
                }

                foreach ($entry->lines() as $line) {
                    if (!$line->accountId->equals($account->id)) {
                        continue;
                    }

                    $opening = $line->side === Side::Debit
                        ? $opening->add($line->money)
                        : $opening->subtract($line->money);
                }
            }
        }

        $running = $opening;
        $lines = [];
        // See CashJournalProjection: an account sheet that shows a reversal as an ordinary opposite
        // movement leaves the reader unable to tell a correction from a removal.
        $reversals = ReversalIndex::of($this->journal);

        foreach ($this->journal->forFiscalYear($fiscalYear) as $entry) {
            if ($entry->periodRef->period > $throughPeriod) {
                continue;
            }

            foreach ($entry->lines() as $line) {
                if (!$line->accountId->equals($account->id)) {
                    continue;
                }

                $running = $line->side === Side::Debit
                    ? $running->add($line->money)
                    : $running->subtract($line->money);

                $lines[] = [
                    'sequenceNumber' => $entry->sequenceNumber,
                    'entryId' => $entry->id->value,
                    'entryDate' => $entry->entryDate->iso,
                    'text' => $entry->text(),
                    'side' => $line->side->value,
                    'money' => $line->money->jsonSerialize(),
                    'runningBalance' => $running->amountAsString(),
                    'contraAccounts' => $this->contraAccounts($entry, $line->side),
                ] + $reversals->forEntry($entry);
            }
        }

        return [
            'account' => $account->number->value,
            'name' => $account->name,
            'openingBalance' => $opening->amountAsString(),
            'lines' => $lines,
            'closingBalance' => $running->amountAsString(),
        ];
    }

    /**
     * The accounts on the other side of one entry, by number, deduplicated and sorted.
     *
     * "Other side" is decided per line, not per sheet: on a debit line the credit accounts answer
     * the question, and the other way round. An entry that touches the same account on both sides —
     * a correction within one account — therefore names it here too, which is the honest answer
     * rather than an empty list.
     *
     * @return list<array{account: string, name: string}>
     */
    private function contraAccounts(JournalEntry $entry, Side $side): array
    {
        $seen = [];
        foreach ($entry->lines() as $line) {
            if ($line->side === $side) {
                continue;
            }
            $account = $this->accounts->byId($line->accountId);
            if ($account === null) {
                continue;
            }
            $seen[$account->number->value] = $account->name;
        }
        ksort($seen, SORT_STRING);

        $out = [];
        foreach ($seen as $number => $name) {
            $out[] = ['account' => (string) $number, 'name' => $name];
        }

        return $out;
    }
}
