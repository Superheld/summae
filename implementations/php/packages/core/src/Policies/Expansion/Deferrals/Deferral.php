<?php

declare(strict_types=1);

namespace Summae\Core\Policies\Expansion\Deferrals;

use Summae\Core\Substrate\AccountNumber;
use Summae\Core\Substrate\CalendarDate;
use Summae\Core\Substrate\Money;
use Summae\Core\Substrate\PeriodRef;
use Summae\Core\Substrate\Uuid;

/**
 * A prepaid or deferred item and its release plan (F-CORE-053).
 *
 * **What was missing was never the accounts.** Both of them have existed in the shipped German
 * chart from the beginning, and both have had a balance-sheet position. What was missing is the
 * *plan*: an insurance premium paid in December for the following year could be deferred and then
 * had to be released by hand, month after month, from memory. That is precisely the failure mode
 * `runDepreciation` exists to prevent for arithmetic that is identical — an amount spread evenly
 * over a known number of periods — and the two ought not to differ in whether the machine
 * remembers.
 *
 * **The plan is fixed at recognition and never recomputed.** `allocate` distributes the amount over
 * the periods with largest-remainder, so the instalments sum to the amount exactly and the last one
 * carries no drift. Storing the plan rather than re-deriving it is the same decision the asset
 * schedule makes, for the same reason: a plan that is recomputed on read answers differently after
 * a rounding rule moves.
 *
 * **Two kinds, and they are opposites rather than variants.** A *prepaid expense* is money already
 * paid for a service still to come — an asset. A *deferred income* is money already received for a
 * service still to be rendered — a liability. Both defer, in opposite directions, and every posting
 * this class produces flips with the kind.
 */
final class Deferral implements \JsonSerializable
{
    public const string PREPAID_EXPENSE = 'prepaidExpense';
    public const string DEFERRED_INCOME = 'deferredIncome';

    /** @var array<string, array{fiscalYear: int, period: int, amount: Money, date: CalendarDate, entryId: Uuid}> */
    private array $released = [];

    /**
     * @param list<array{fiscalYear: int, period: int, amount: Money}> $plan the instalments, in order
     */
    public function __construct(
        public readonly Uuid $id,
        public readonly string $kind,
        public readonly string $reason,
        public readonly AccountNumber $account,
        public readonly AccountNumber $counterAccount,
        public readonly CalendarDate $recognizedOn,
        public readonly Money $amount,
        public readonly array $plan,
        public readonly ?Uuid $recognitionEntryId = null,
    ) {
    }

    /** @return list<string> the closed vocabulary — a third kind would be a third direction of posting */
    public static function kinds(): array
    {
        return [self::PREPAID_EXPENSE, self::DEFERRED_INCOME];
    }

    public function isReleased(int $fiscalYear, int $period): bool
    {
        return isset($this->released[self::key($fiscalYear, $period)]);
    }

    public function recordRelease(int $fiscalYear, int $period, Money $amount, CalendarDate $date, Uuid $entryId): void
    {
        $this->released[self::key($fiscalYear, $period)] = [
            'fiscalYear' => $fiscalYear,
            'period' => $period,
            'amount' => $amount,
            'date' => $date,
            'entryId' => $entryId,
        ];
    }

    public function releasedTotal(): Money
    {
        $sum = $this->amount->subtract($this->amount);

        foreach ($this->released as $release) {
            $sum = $sum->add($release['amount']);
        }

        return $sum;
    }

    public function outstanding(): Money
    {
        return $this->amount->subtract($this->releasedTotal());
    }

    public function isSettled(): bool
    {
        return $this->outstanding()->isZero();
    }

    /** The instalment due for a period, or null where the plan has none. */
    public function instalmentFor(int $fiscalYear, int $period): ?Money
    {
        foreach ($this->plan as $entry) {
            if ($entry['fiscalYear'] === $fiscalYear && $entry['period'] === $period) {
                return $entry['amount'];
            }
        }

        return null;
    }

    /** @return list<array{fiscalYear: int, period: int, amount: Money, date: CalendarDate, entryId: Uuid}> */
    public function releases(): array
    {
        // Sorted, because a map keyed by period has whatever order the releases happened in and the
        // register must read the same after a restart as before one.
        $releases = array_values($this->released);
        usort($releases, static function (array $a, array $b): int {
            $byYear = $a['fiscalYear'] <=> $b['fiscalYear'];

            return $byYear !== 0 ? $byYear : $a['period'] <=> $b['period'];
        });

        return $releases;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id->value,
            'kind' => $this->kind,
            'reason' => $this->reason,
            'account' => $this->account->value,
            'counterAccount' => $this->counterAccount->value,
            'recognizedOn' => $this->recognizedOn->iso,
            'amount' => $this->amount->jsonSerialize(),
            'recognitionEntryId' => $this->recognitionEntryId?->value,
            'plan' => array_map(static fn (array $entry): array => [
                'fiscalYear' => $entry['fiscalYear'],
                'period' => $entry['period'],
                'amount' => $entry['amount']->jsonSerialize(),
            ], $this->plan),
            'released' => array_map(static fn (array $release): array => [
                'fiscalYear' => $release['fiscalYear'],
                'period' => $release['period'],
                'amount' => $release['amount']->jsonSerialize(),
                'date' => $release['date']->iso,
                'entryId' => $release['entryId']->value,
            ], $this->releases()),
        ];
    }

    /**
     * @param list<array{fiscalYear: int, period: int, amount: Money}> $plan
     * @param list<array{fiscalYear: int, period: int, amount: Money, date: CalendarDate, entryId: Uuid}> $released
     */
    public static function restore(
        Uuid $id,
        string $kind,
        string $reason,
        AccountNumber $account,
        AccountNumber $counterAccount,
        CalendarDate $recognizedOn,
        Money $amount,
        array $plan,
        array $released,
        ?Uuid $recognitionEntryId,
    ): self {
        $deferral = new self($id, $kind, $reason, $account, $counterAccount, $recognizedOn, $amount, $plan, $recognitionEntryId);

        foreach ($released as $release) {
            $deferral->released[self::key($release['fiscalYear'], $release['period'])] = $release;
        }

        return $deferral;
    }

    public static function periodOf(PeriodRef $period): string
    {
        return self::key($period->fiscalYear, $period->period);
    }

    private static function key(int $fiscalYear, int $period): string
    {
        return sprintf('%04d-%02d', $fiscalYear, $period);
    }
}
