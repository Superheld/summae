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
    /** @var list<array{planMonth: int, date: CalendarDate, amount: Money, entryId: Uuid, kind: string}> */
    private array $depreciations = [];

    /**
     * True once an unplanned write-down has rewritten the remaining plan. From then on the schedule
     * IS the plan and may not be re-derived from the acquisition cost — the whole point of the
     * write-down is that the cost is no longer the basis.
     */
    private bool $scheduleRevised = false;

    private int $reportedUnits = 0;

    /**
     * The schedule as it stood before any write-down rebased it — the *shadow* plan.
     *
     * It exists for one purpose: the write-up ceiling. A write-down does not only
     * reduce the book value, it lowers every remaining planned instalment, so the book value drifts
     * *above* what it would have been without the write-down as the plan runs on. Reversing the
     * write-down in full would therefore carry the asset higher than its amortised acquisition cost,
     * which no write-up may do. The ceiling is `cost − Σ original shares of the booked months`, and
     * the original shares are recoverable from nothing else once a rebase has happened: for a
     * declining-balance or units-of-production plan they were never a flat allocate to begin with.
     *
     * @var list<Money>
     */
    private array $originalSchedule;

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
        public array $monthlySchedule,
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
        /**
         * An additional allowance running ALONGSIDE the plan, not instead of it.
         *
         * Some jurisdictions let a business deduct an extra share of the cost within the first few
         * years, freely distributed over them. It is not a depreciation method — the ordinary plan
         * carries on untouched on the original basis while the window is open — so it cannot be
         * expressed as a different schedule. It is a budget: this much may still be taken, until this
         * fiscal year. Both numbers come from the pack; the asset only remembers them.
         *
         * Null means no such allowance was elected, which is every asset written before this existed.
         */
        public readonly ?Money $specialDepreciationBudget = null,
        public readonly ?int $specialDepreciationWindowEnd = null,
        /**
         * Total expected output of the asset, where depreciation follows use rather than time —
         * kilometres for a lorry, operating hours for a press, copies for a machine.
         *
         * It changes what "a year" means. Time-based depreciation knows at acquisition what every
         * future period will take; output-based depreciation cannot, because the number comes from
         * outside the books. So there is no schedule to build and the yearly run has nothing to do
         * here: usage is reported as it happens, and each report books the difference between what
         * the asset has now given and what has already been written off.
         */
        public readonly ?int $totalUnits = null,
    ) {
        // The shadow plan starts as the plan. It only diverges when a write-down rebases the live
        // one, which is exactly when the write-up ceiling starts to need it.
        $this->originalSchedule = $monthlySchedule;
    }

    /**
     * What the book value would be if no write-down had ever happened — the ceiling a write-up may
     * not cross.
     *
     * Every month the live plan has booked is charged at its **original** share instead of the
     * reduced one. An asset that was never written down has an identical shadow, so this equals the
     * ordinary book value and the ceiling binds nothing.
     */
    public function amortisedCostCeiling(): Money
    {
        $shadowAccumulated = $this->acquisitionCost->subtract($this->acquisitionCost);

        foreach ($this->depreciations as $booking) {
            $planMonth = $booking['planMonth'];
            if ($planMonth < 1) {
                // A write-down or a usage report is not part of any plan — the shadow ignores it,
                // which is the whole point: the shadow is the plan that never saw the write-down.
                continue;
            }
            $share = $this->originalSchedule[$planMonth - 1] ?? null;
            if ($share !== null) {
                $shadowAccumulated = $shadowAccumulated->add($share);
            }
        }

        return $this->acquisitionCost->subtract($shadowAccumulated);
    }

    /** @return list<Money> */
    public function originalSchedule(): array
    {
        return $this->originalSchedule;
    }

    /**
     * A write-up reverses part of an earlier write-down. It is recorded as a
     * negative "unplanned" booking, so every existing reader — accumulated depreciation, the book
     * value, the register — picks it up without a special case, and the plan is rebased upward the
     * same way a write-down rebases it downward.
     *
     * @param list<int> $openPlanMonths
     */
    public function recordWriteUp(CalendarDate $date, Money $amount, Uuid $entryId, array $openPlanMonths): void
    {
        $this->depreciations[] = [
            'planMonth' => 0,
            'date' => $date,
            'amount' => $amount->negate(),
            'entryId' => $entryId,
            'kind' => 'writeUp',
        ];

        $this->rebaseRemainingPlan($openPlanMonths);
    }

    /** What has been written down and not yet written back — nothing may be reversed twice. */
    public function unreversedWriteDowns(): Money
    {
        $sum = $this->acquisitionCost->subtract($this->acquisitionCost);

        foreach ($this->depreciations as $booking) {
            if ($booking['kind'] === 'unplanned') {
                $sum = $sum->add($booking['amount']);
            }
            if ($booking['kind'] === 'writeUp') {
                // Already negative, so adding it subtracts.
                $sum = $sum->add($booking['amount']);
            }
        }

        return $sum;
    }

    /** Units reported so far — never more than `totalUnits`, which is what caps the last booking. */
    public function reportedUnits(): int
    {
        return $this->reportedUnits;
    }

    public function recordUsage(CalendarDate $date, Money $amount, Uuid $entryId, int $unitsAfter): void
    {
        $this->reportedUnits = $unitsAfter;
        $this->depreciations[] = [
            'planMonth' => 0,
            'date' => $date,
            'amount' => $amount,
            'entryId' => $entryId,
            'kind' => 'usage',
        ];
    }

    /** What is left of the additional allowance. */
    public function specialDepreciationRemaining(): ?Money
    {
        if ($this->specialDepreciationBudget === null) {
            return null;
        }

        $used = $this->specialDepreciationBudget->subtract($this->specialDepreciationBudget);
        foreach ($this->depreciations as $booking) {
            if ($booking['kind'] === 'special') {
                $used = $used->add($booking['amount']);
            }
        }

        return $this->specialDepreciationBudget->subtract($used);
    }

    public function hasSpecialDepreciation(): bool
    {
        if ($this->specialDepreciationBudget === null) {
            return false;
        }

        foreach ($this->depreciations as $booking) {
            if ($booking['kind'] === 'special') {
                return true;
            }
        }

        return false;
    }

    public function recordSpecialDepreciation(CalendarDate $date, Money $amount, Uuid $entryId): void
    {
        // No re-spreading here, unlike a write-down. While the window is open the ordinary plan runs
        // on the original basis — that is what "alongside" means, and lowering it now would quietly
        // take back part of the allowance the same year it was granted. The plan is re-based once,
        // after the window closes.
        $this->depreciations[] = [
            'planMonth' => 0,
            'date' => $date,
            'amount' => $amount,
            'entryId' => $entryId,
            'kind' => 'special',
        ];
    }

    /**
     * Spreads whatever book value is left over the plan months not yet booked.
     *
     * Two occasions need exactly this: an unplanned write-down, where the basis fell; and the end of
     * an additional allowance's window, where part of the cost has already been deducted outside the
     * plan and the rest has to last for the remaining life. Same arithmetic, and it should stay the
     * same arithmetic — two spreadings that drifted apart would be two different answers to one
     * question.
     *
     * @param list<int> $openPlanMonths plan months not yet booked, ascending
     */
    public function rebaseRemainingPlan(array $openPlanMonths): void
    {
        $this->scheduleRevised = true;

        if ($openPlanMonths === []) {
            return;
        }

        $remaining = $this->acquisitionCost->subtract($this->accumulatedDepreciationAt(null));
        $shares = $remaining->allocate(...array_fill(0, count($openPlanMonths), 1));

        $schedule = $this->monthlySchedule;
        foreach ($openPlanMonths as $index => $planMonth) {
            $schedule[$planMonth - 1] = $shares[$index];
        }
        $this->monthlySchedule = array_values($schedule);
    }

    /** Straight line unless the pack offered, and the caller chose, something else. */
    public function method(): string
    {
        return $this->depreciationMethod ?? 'straight_line';
    }

    /** A schedule that cannot be re-derived from month counts and must be read as it stands. */
    public function scheduleIsAuthoritative(): bool
    {
        return $this->method() !== 'straight_line' || $this->scheduleRevised;
    }

    public function scheduleWasRevised(): bool
    {
        return $this->scheduleRevised;
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
     * @param list<Money>|null $originalSchedule
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
        bool $scheduleRevised = false,
        ?Money $specialDepreciationBudget = null,
        ?int $specialDepreciationWindowEnd = null,
        ?int $totalUnits = null,
        int $reportedUnits = 0,
        // Null for an asset written before the shadow plan existed — and that is the right answer
        // rather than a fallback: such an asset either has no write-down (so the shadow IS the live
        // plan) or one booked before a write-up was possible at all.
        ?array $originalSchedule = null,
    ): self {
        $asset = new self($id, $name, $assetClass, $assetAccount, $acquisitionCost, $acquiredOn, $route, $usefulLifeMonths, $monthlySchedule, $voucherId, $dimensions, $depreciationStart, $depreciationMethod, $specialDepreciationBudget, $specialDepreciationWindowEnd, $totalUnits);
        $asset->reportedUnits = $reportedUnits;
        // A booking written before write-downs existed is a planned one — that is what it was.
        $asset->depreciations = array_map(
            static fn (array $booking): array => $booking + ['kind' => 'planned'],
            $depreciations,
        );
        $asset->disposed = $disposed;
        $asset->disposedOn = $disposedOn;
        $asset->scheduleRevised = $scheduleRevised;
        $asset->originalSchedule = $originalSchedule ?? $monthlySchedule;

        return $asset;
    }

    public function isDisposed(): bool
    {
        return $this->disposed;
    }

    /** When it left, or null while it is still there — read by the movement schedule (F-CORE-055). */
    public function disposedOn(): ?CalendarDate
    {
        return $this->disposedOn;
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

    public function recordDepreciation(int $planMonth, CalendarDate $date, Money $amount, Uuid $entryId, string $kind = 'planned'): void
    {
        $this->depreciations[] = [
            'planMonth' => $planMonth,
            'date' => $date,
            'amount' => $amount,
            'entryId' => $entryId,
            'kind' => $kind,
        ];
    }

    /**
     * An unplanned write-down: the amount lowers the book value at once, and what is left is spread
     * over the plan months that have not been booked yet.
     *
     * Re-spreading is the part that is easy to leave out and wrong to leave out. Continuing the old
     * plan after a write-down would depreciate more than the asset is still worth — the invariant
     * says the book value never goes below zero — and stopping early instead would finish the asset
     * before its life is over. Neither is what a lasting impairment means: the reduced value is
     * carried on over the REMAINING life, which is exactly what this does.
     *
     * `planMonth: 0` marks it as belonging to no plan month; plan months are 1-based, so nothing
     * reads it as one, while the accumulated depreciation picks it up like any other booking.
     *
     * @param list<int> $openPlanMonths plan months not yet booked, ascending
     */
    public function recordWriteDown(CalendarDate $date, Money $amount, Uuid $entryId, array $openPlanMonths): void
    {
        $this->depreciations[] = [
            'planMonth' => 0,
            'date' => $date,
            'amount' => $amount,
            'entryId' => $entryId,
            'kind' => 'unplanned',
        ];

        $this->rebaseRemainingPlan($openPlanMonths);
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
            'kind' => $booking['kind'],
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
