<?php

declare(strict_types=1);

namespace Summae\Core\Policies\Projection;

use Summae\Core\DomainError;
use Summae\Core\Port\AccountRepository;
use Summae\Core\Port\JournalRepository;
use Summae\Core\Substrate\Currency;
use Summae\Core\Substrate\Money;
use Summae\Core\Substrate\Side;

/**
 * Cash journal — the separation of cash from non-cash transactions, and the cash-count check.
 *
 * GoBD Rz. 57 ff. asks for cash and non-cash transactions to be kept apart. In double-entry
 * that separation is already structural rather than a flag: a transaction *is* a cash
 * transaction exactly when it touches an account of subtype `cash`. So nothing has to be
 * marked and no field has to be added — what was missing is the view that presents the
 * separation, which is the form an auditor asks for (the cash account's sheet *is* the
 * Kassenbuch).
 *
 * The second half is the part that finds real defects: **a cash balance can never be
 * negative.** You cannot hold less than no cash — that is physics, not jurisdiction, which
 * is why it belongs in the substrate and not in a pack. A cash account that goes below zero
 * at any point means the books do not describe what was in the drawer, and it is one of the
 * first things a tax auditor looks for. The running balance is therefore checked at every
 * movement, not only at the end: a day that dips negative and recovers is exactly the case
 * a closing balance hides.
 *
 * Reports, never blocks. Whether a negative balance stops a workflow is the embedding
 * application's decision; the library supplies the finding.
 */
final readonly class CashJournalProjection
{
    public function __construct(
        private Currency $baseCurrency,
        private AccountRepository $accounts,
        private JournalRepository $journal,
    ) {
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public function compute(array $params): array
    {
        $fiscalYear = $params['fiscalYear'] ?? null;
        if (!is_int($fiscalYear)) {
            throw new DomainError('E_INPUT_INVALID', 'cashJournal requires the parameter "fiscalYear"', [
                'fiscalYear' => DomainError::rejectedValue($fiscalYear),
            ]);
        }

        // Ordered by account number so the result does not depend on repository order.
        $cashAccounts = array_values(array_filter(
            $this->accounts->all(),
            static fn ($account): bool => $account->subtype === 'cash',
        ));
        usort($cashAccounts, static fn ($a, $b): int => strcmp($a->number->value, $b->number->value));

        $accounts = [];
        $negativeBalances = [];

        foreach ($cashAccounts as $account) {
            // Cash is balance-carrying: last year's drawer is this year's opening.
            $opening = Money::zero($this->baseCurrency);
            foreach ($this->journal->all() as $entry) {
                if ($entry->periodRef->fiscalYear >= $fiscalYear) {
                    continue;
                }
                foreach ($entry->lines() as $line) {
                    if (!$line->accountId->equals($account->id)) {
                        continue;
                    }
                    $opening = $line->side === Side::Debit ? $opening->add($line->money) : $opening->subtract($line->money);
                }
            }

            $running = $opening;
            $movements = [];
            foreach ($this->journal->forFiscalYear($fiscalYear) as $entry) {
                foreach ($entry->lines() as $line) {
                    if (!$line->accountId->equals($account->id)) {
                        continue;
                    }
                    $running = $line->side === Side::Debit ? $running->add($line->money) : $running->subtract($line->money);
                    $movements[] = [
                        'sequenceNumber' => $entry->sequenceNumber,
                        'entryDate' => $entry->entryDate->iso,
                        'voucherId' => $entry->voucherId->value,
                        'text' => $entry->text(),
                        'side' => $line->side->value,
                        'money' => $line->money->jsonSerialize(),
                        'runningBalance' => $running->amountAsString(),
                    ];

                    if ($running->isNegative()) {
                        $negativeBalances[] = [
                            'account' => $account->number->value,
                            'sequenceNumber' => $entry->sequenceNumber,
                            'entryDate' => $entry->entryDate->iso,
                            'runningBalance' => $running->amountAsString(),
                        ];
                    }
                }
            }

            $accounts[] = [
                'account' => $account->number->value,
                'name' => $account->name,
                'openingBalance' => $opening->amountAsString(),
                'movements' => $movements,
                'closingBalance' => $running->amountAsString(),
            ];
        }

        return [
            'fiscalYear' => $fiscalYear,
            'accounts' => $accounts,
            // Named as a finding, not as a boolean flag: an empty list is the statement "no
            // cash balance ever went below zero in this year", which is what the check is for.
            'negativeBalances' => $negativeBalances,
            'cashCountable' => $negativeBalances === [],
        ];
    }
}
