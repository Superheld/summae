<?php

declare(strict_types=1);

namespace Summae\Core\Policies\Expansion\Assets;

use Summae\Core\DomainError;
use Summae\Core\Substrate\AccountNumber;
use Summae\Core\Substrate\CalendarDate;
use Summae\Core\Substrate\Money;
use Summae\Core\Substrate\Uuid;

/**
 * Asset (assets-modell.md): master data + depreciation schedule + history.
 * Invariants: book value = acquisition cost − Σ depreciations, never < 0;
 * no depreciation before acquisition or after disposal; every history step
 * references its journal entry.
 */
final class Asset implements \JsonSerializable
{
    /** @var list<array{planMonth: int, date: CalendarDate, amount: Money, entryId: Uuid}> */
    private array $depreciations = [];

    private bool $disposed = false;

    private ?CalendarDate $disposedOn = null;

    /**
     * @param list<Money> $monthlySchedule flat allocate over the useful life (determinismus.md §2)
     */
    public function __construct(
        public readonly Uuid $id,
        public readonly string $name,
        public readonly string $assetClass,
        public readonly AccountNumber $assetAccount,
        public readonly Money $acquisitionCost,
        public readonly CalendarDate $acquiredOn,
        public readonly AssetRoute $route,
        public readonly ?int $usefulLifeMonths,
        public readonly array $monthlySchedule,
        public readonly Uuid $voucherId,
        /**
         * Cost centre and friends, carried by the asset itself (IMPL-023). Depreciation is booked by
         * the machine, month after month, for years — nobody is there to name a dimension at that
         * moment, and a mandatory one on the depreciation account would otherwise make the run
         * impossible. The master record answering it once is also how it works in practice: an
         * asset belongs to a cost centre, and its depreciation belongs there with it.
         *
         * @var list<array{type: string, code: string}>
         */
        public readonly array $dimensions = [],
        /**
         * First month of the depreciation plan. Normally the month of acquisition — pro rata
         * temporis, which is what linear depreciation asks for in most jurisdictions.
         *
         * A pooled asset can be different: where a jurisdiction dissolves its pool in equal
         * *fiscal-year* fractions, the first year is not shortened by the acquisition month, so
         * the plan starts at the beginning of the fiscal year the asset was acquired in. Which of
         * the two applies is pack data (`poolProRataInFirstYear`), never a decision of this class —
         * the asset is simply told where its plan begins.
         *
         * Null means "same as acquisition", so persisted assets written before this field existed
         * rehydrate to exactly the behaviour they had.
         */
        public readonly ?CalendarDate $depreciationStart = null,
        /**
         * How the schedule was built. Straight line spreads the cost flat, so the yearly run can
         * re-derive a year's share from the month counts; declining balance cannot, because each
         * year depends on what is left after the one before. The schedule then IS the plan and has
         * to be read rather than recomputed — that is the only thing this field decides.
         *
         * Null means straight line, so assets written before the field existed rehydrate unchanged.
         */
        public readonly ?string $depreciationMethod = null,
    ) {
    }

    /** Straight line unless the pack offered, and the caller chose, something else. */
    public function method(): string
    {
        return $this->depreciationMethod ?? 'straight_line';
    }

    /** A schedule that cannot be re-derived from month counts and must be read as it stands. */
    public function scheduleIsAuthoritative(): bool
    {
        return $this->method() !== 'straight_line';
    }

    /** Where the depreciation plan begins — the acquisition month unless the pack moved it. */
    public function planStart(): CalendarDate
    {
        return $this->depreciationStart ?? $this->acquiredOn;
    }

    /**
     * Rehydration from persistence (adapter).
     *
     * @param list<array{type: string, code: string}> $dimensions
     *
     * @param list<Money> $monthlySchedule
     * @param list<array{planMonth: int, date: CalendarDate, amount: Money, entryId: Uuid}> $depreciations
     */
    public static function restore(
        Uuid $id,
        string $name,
        string $assetClass,
        AccountNumber $assetAccount,
        Money $acquisitionCost,
        CalendarDate $acquiredOn,
        AssetRoute $route,
        ?int $usefulLifeMonths,
        array $monthlySchedule,
        Uuid $voucherId,
        array $depreciations,
        bool $disposed,
        ?CalendarDate $disposedOn,
        array $dimensions = [],
        ?CalendarDate $depreciationStart = null,
        ?string $depreciationMethod = null,
    ): self {
        $asset = new self($id, $name, $assetClass, $assetAccount, $acquisitionCost, $acquiredOn, $route, $usefulLifeMonths, $monthlySchedule, $voucherId, $dimensions, $depreciationStart, $depreciationMethod);
        $asset->depreciations = $depreciations;
        $asset->disposed = $disposed;
        $asset->disposedOn = $disposedOn;

        return $asset;
    }

    public function isDisposed(): bool
    {
        return $this->disposed;
    }

    public function assertActive(): void
    {
        if ($this->disposed) {
            throw new DomainError('E_ASSET_DISPOSED', sprintf(
                'asset %s is already disposed (%s)',
                $this->id->value,
                $this->disposedOn->iso ?? '?',
            ), ['assetId' => $this->id->value]);
        }
    }

    public function dispose(CalendarDate $disposedOn): void
    {
        $this->assertActive();
        $this->disposed = true;
        $this->disposedOn = $disposedOn;
    }

    /** Calendar year+month of the plan month (1-based). */
    public function planMonthDate(int $planMonth): CalendarDate
    {
        $start = new \DateTimeImmutable($this->planStart()->iso);
        $month = $start->modify(sprintf('first day of +%d months', $planMonth - 1));

        return CalendarDate::of($month->modify('last day of this month')->format('Y-m-d'));
    }

    public function isMonthBooked(int $planMonth): bool
    {
        foreach ($this->depreciations as $booking) {
            if ($booking['planMonth'] === $planMonth) {
                return true;
            }
        }

        return false;
    }

    public function recordDepreciation(int $planMonth, CalendarDate $date, Money $amount, Uuid $entryId): void
    {
        $this->depreciations[] = [
            'planMonth' => $planMonth,
            'date' => $date,
            'amount' => $amount,
            'entryId' => $entryId,
        ];
    }

    /**
     * History in persistence form (adapter).
     *
     * @return list<array<string, mixed>>
     */
    public function depreciationsForPersistence(): array
    {
        return array_map(static fn (array $booking): array => [
            'planMonth' => $booking['planMonth'],
            'date' => $booking['date']->iso,
            'amount' => $booking['amount']->jsonSerialize(),
            'entryId' => $booking['entryId']->value,
        ], $this->depreciations);
    }

    public function accumulatedDepreciationAt(?CalendarDate $asOf): Money
    {
        $sum = $this->acquisitionCost->subtract($this->acquisitionCost); // 0 in tenant currency

        foreach ($this->depreciations as $booking) {
            if ($asOf !== null && $booking['date']->isAfter($asOf)) {
                continue;
            }

            $sum = $sum->add($booking['amount']);
        }

        return $sum;
    }

    /**
     * Carrying amount = cost less what has been depreciated (IMPL-024).
     *
     * Only an immediately expensed asset has no carrying amount — it was never capitalised. A
     * *pooled* one was: it sits on the pool account and is written down over the pack's term, so
     * reporting zero for it made the fixed-asset schedule (F-AST-005) understate the balance sheet
     * it is supposed to explain. The old shortcut was invisible while nothing consumed the value
     * for pooled assets; the disposal write-off does now.
     */
    public function bookValueAt(?CalendarDate $asOf): Money
    {
        if ($this->route === AssetRoute::ImmediateExpense) {
            return $this->acquisitionCost->subtract($this->acquisitionCost);
        }

        return $this->acquisitionCost->subtract($this->accumulatedDepreciationAt($asOf));
    }

    /**
     * Depreciation schedule as a verifiable summary: consecutive months
     * of equal rate grouped ("months1to28" etc.) plus total.
     *
     * @return array<string, string>
     */
    public function scheduleSummary(): array
    {
        if ($this->monthlySchedule === []) {
            return [];
        }

        $summary = [];
        $total = $this->acquisitionCost->subtract($this->acquisitionCost);
        $runStart = 1;

        foreach ($this->monthlySchedule as $index => $amount) {
            $total = $total->add($amount);
            $isLast = $index === count($this->monthlySchedule) - 1;
            $next = $isLast ? null : $this->monthlySchedule[$index + 1];

            if ($next !== null && $next->equals($amount)) {
                continue;
            }

            $summary[sprintf('months%dto%d', $runStart, $index + 1)] = $amount->amountAsString();
            $runStart = $index + 2;
        }

        $summary['total'] = $total->amountAsString();

        return $summary;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id->value,
            'name' => $this->name,
            'assetClass' => $this->assetClass,
            'assetAccount' => $this->assetAccount->value,
            'route' => $this->route->value,
            'acquisitionCost' => $this->acquisitionCost->jsonSerialize(),
            'acquiredOn' => $this->acquiredOn->iso,
            'usefulLifeMonths' => $this->usefulLifeMonths,
            'status' => $this->disposed ? 'disposed' : 'active',
            'disposedOn' => $this->disposedOn?->iso,
            'voucherId' => $this->voucherId->value,
            'dimensions' => $this->dimensions,
            'depreciationMethod' => $this->method(),
        ];
    }
}
