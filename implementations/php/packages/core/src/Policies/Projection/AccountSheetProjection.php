<?php

declare(strict_types=1);

namespace Summae\Core\Policies\Projection;

use Summae\Core\DomainError;
use Summae\Core\Substrate\Side;
use Summae\Core\Port\AccountRepository;
use Summae\Core\Port\JournalRepository;
use Summae\Core\Substrate\AccountNumber;
use Summae\Core\Substrate\Currency;
use Summae\Core\Substrate\Money;

/**
 * Account sheet: all movements of an account in the fiscal year with a
 * running balance. Opening balance = cumulative prior years for
 * balance-carrying accounts, null for income accounts (api.md period semantics).
 * Order: sequenceNumber — the only authoritative one (determinismus.md §3).
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
        $number = is_string($params['account'] ?? null) ? $params['account'] : '';
        $fiscalYear = is_int($params['fiscalYear'] ?? null) ? $params['fiscalYear'] : 0;
        $throughPeriod = is_int($params['throughPeriod'] ?? null) ? $params['throughPeriod'] : PHP_INT_MAX;

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
                    'entryDate' => $entry->entryDate->iso,
                    'text' => $entry->text(),
                    'side' => $line->side->value,
                    'money' => $line->money->jsonSerialize(),
                    'runningBalance' => $running->amountAsString(),
                ];
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
}
