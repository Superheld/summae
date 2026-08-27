<?php

declare(strict_types=1);

namespace Summae\Core\Policies\Projection;

use Summae\Core\Port\AccountRepository;
use Summae\Core\Port\JournalRepository;
use Summae\Core\Substrate\Currency;
use Summae\Core\Substrate\Money;
use Summae\Core\Substrate\Side;

/**
 * The result not yet appropriated (F-CORE-024/SF-25) — the read side of `appropriateResult`.
 *
 * Until this projection the figure existed but could not be *asked for*. It was computed on every
 * `appropriateResult` call and left the library only as the `available` detail of a refusal, so an
 * application that wanted to pre-fill a resolution dialog had two ways to learn it: provoke
 * `E_APPROPRIATION_EXCEEDS_RESULT` on purpose, or read the balance-sheet position carrying
 * `includesNetIncome` — which presupposes a mapping and knowledge of which position that is. A
 * number you can only obtain by doing it wrong is not published.
 *
 * **One pot, not one per year.** `result_allocation` accounts carry what has been appropriated, and
 * nothing in them says which year's profit they consumed. So the top-level figures describe the pot
 * as a whole and `byFiscalYear` describes where it came from. Only `available` is per year, and it
 * is exactly what `appropriateResult` would permit for a resolution naming that year — the same
 * function, not a second implementation of it, so the number a user reads and the number the
 * operation refuses against cannot drift apart.
 *
 * Sign convention follows the books, as everywhere else: positive is a profit, negative a loss.
 * Years come from the journal, in ascending order.
 *
 * The SAME shape lives in the Node unappropriated-result.ts.
 */
final readonly class UnappropriatedResult
{
    public function __construct(
        private Currency $baseCurrency,
        private AccountRepository $accounts,
        private JournalRepository $journal,
    ) {
    }

    /**
     * The pot and its composition, in one pass: every year's own result in ascending order, the
     * cumulative result through each, and what a resolution naming each year may still take.
     *
     * @return array{
     *   cumulativeResult: Money,
     *   allocated: Money,
     *   byFiscalYear: list<array{fiscalYear: int, result: Money, cumulativeResult: Money, available: Money}>
     * }
     */
    public function figures(): array
    {
        ['perYear' => $perYear, 'result' => $result, 'allocated' => $allocated] = $this->scan();

        $years = array_keys($perYear);
        sort($years, SORT_NUMERIC);

        $cumulative = Money::zero($this->baseCurrency);
        $rows = [];
        foreach ($years as $year) {
            $cumulative = $cumulative->add($perYear[$year]);
            $rows[] = [
                'fiscalYear' => $year,
                'result' => $perYear[$year],
                'cumulativeResult' => $cumulative,
                'available' => $this->availableFrom($cumulative, $result, $allocated),
            ];
        }

        return ['cumulativeResult' => $result, 'allocated' => $allocated, 'byFiscalYear' => $rows];
    }

    /**
     * What `appropriateResult` may book for a resolution naming `$fiscalYear`. Public because the
     * expansion service asks it rather than computing its own.
     */
    public function available(int $fiscalYear): Money
    {
        ['perYear' => $perYear, 'result' => $result, 'allocated' => $allocated] = $this->scan();

        $cumulative = Money::zero($this->baseCurrency);
        foreach ($perYear as $year => $yearResult) {
            if ($year <= $fiscalYear) {
                $cumulative = $cumulative->add($yearResult);
            }
        }

        return $this->availableFrom($cumulative, $result, $allocated);
    }

    /**
     * The pot decides the direction, the named year caps the size (IMPL-033).
     *
     * The figure itself is unchanged and stays the obvious one: what was earned through year Y, minus
     * everything the allocation accounts already carry. Allocations are deliberately not cut at the
     * year boundary — a resolution is dated *after* the year it appropriates, so cutting them would
     * make every past appropriation invisible and let the same profit be appropriated twice.
     *
     * What that figure could not do alone is notice when it has gone past the end. Appropriate 1200 of
     * a 1400 profit naming 2027, and 2026's figure comes out at 900 − 1200 = −300; the operation read
     * that as an unappropriated *loss* of 300 and would book it, charging the books against a pot that
     * held 200. So the pot — everything earned minus everything appropriated — decides the direction
     * and the ceiling: a year figure pointing the other way is nothing to appropriate rather than a
     * loss, and none of them may exceed what is actually left. Where the year figure was already
     * right, which is every case that does not run past the pot, this changes nothing.
     */
    private function availableFrom(Money $cumulativeThroughYear, Money $totalResult, Money $allocated): Money
    {
        $pot = $totalResult->subtract($allocated);
        if ($pot->isZero()) {
            return $pot;
        }

        $year = $cumulativeThroughYear->subtract($allocated);
        $towardsPot = $pot->isNegative() ? $year->negate() : $year;
        if (!$towardsPot->isPositive()) {
            return Money::zero($this->baseCurrency);
        }

        $capped = $towardsPot->compareTo($pot->abs()) > 0 ? $pot->abs() : $towardsPot;

        return $pot->isNegative() ? $capped->negate() : $capped;
    }

    /**
     * One pass over the journal: the result of each fiscal year, and what the `result_allocation`
     * accounts carry over the whole journal (see `availableFrom` for why the whole journal).
     *
     * @return array{perYear: array<int, Money>, result: Money, allocated: Money}
     */
    private function scan(): array
    {
        /** @var array<int, Money> $perYear */
        $perYear = [];
        $result = Money::zero($this->baseCurrency);
        $allocated = Money::zero($this->baseCurrency);

        foreach ($this->journal->all() as $entry) {
            $year = $entry->periodRef->fiscalYear;
            if (!isset($perYear[$year])) {
                $perYear[$year] = Money::zero($this->baseCurrency);
            }

            foreach ($entry->lines() as $line) {
                $account = $this->accounts->byId($line->accountId);
                if ($account === null) {
                    continue;
                }

                if (!$account->type->isBalanceCarrying()) {
                    $signed = $line->side === Side::Credit ? $line->money : $line->money->negate();
                    $perYear[$year] = $perYear[$year]->add($signed);
                    $result = $result->add($signed);
                    continue;
                }
                if ($account->subtype === 'result_allocation') {
                    $allocated = $line->side === Side::Debit
                        ? $allocated->add($line->money)
                        : $allocated->subtract($line->money);
                }
            }
        }

        return ['perYear' => $perYear, 'result' => $result, 'allocated' => $allocated];
    }
}
