<?php

declare(strict_types=1);

namespace Summae\Core\Policies\Expansion\Inventory;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Summae\Core\DomainError;
use Summae\Core\Ledger\AuditWriter;
use Summae\Core\Ledger\Ledger;
use Summae\Core\Port\AccountRepository;
use Summae\Core\Policies\Expansion\Costing\CostingRun;
use Summae\Core\Port\CostingRunRepository;
use Summae\Core\Port\InventoryValuationRepository;
use Summae\Core\Port\JournalRepository;
use Summae\Core\Port\VoucherRepository;
use Summae\Core\Records\Voucher;
use Summae\Core\Substrate\AccountNumber;
use Summae\Core\Substrate\AccountSubtype;
use Summae\Core\Substrate\CalendarDate;
use Summae\Core\Substrate\Currency;
use Summae\Core\Substrate\Exception\InvalidValue;
use Summae\Core\Substrate\IdGenerator;
use Summae\Core\Substrate\Money;
use Summae\Core\Substrate\PeriodRef;
use Summae\Core\Substrate\Side;
use Summae\Core\Substrate\Uuid;

/**
 * Stock: measurement and posting (F-CORE-050).
 *
 * **The link that was missing.** Cost accounting justifies itself through a chain — cost centres →
 * allocation → overhead rates → production cost → *inventory valuation* → balance sheet — and only
 * the last link makes it bookkeeping rather than controlling. The middle was built and good; both
 * ends were missing. This is the outer one: the production cost the costing run computes now
 * reaches an account, and that account now reaches a balance-sheet position.
 *
 * **Socket and plug, as everywhere else.** The core knows *that* stock is carried at the lower of
 * its cost and a value the caller supplies, that the difference against what the books already
 * carry is booked, and that the counter-account is whatever the pack names for that category. It
 * knows nothing about *which* accounts, which categories a jurisdiction distinguishes, or whether
 * a change in raw materials belongs with material expense while a change in finished goods is its
 * own line — all of that is the `inventory` module.
 *
 * **The division the costing service refuses to do.** `computeProductionCost` deliberately does not
 * divide by a quantity, on the ground that the core carries no quantities. That is still true: it
 * carries none. Here a quantity is *handed in*, together with the produced quantity the run's total
 * relates to, and the division happens where both are declared inputs of one call. Nothing is
 * stored as a stock and nothing is carried forward.
 *
 * **Lower of cost or market, and why the mechanism is jurisdiction-free while the duty is not.**
 * Given a `marketValue` per unit, the lower of it and the unit cost is used, and the row says so.
 * Whether comparing is a duty, an option or forbidden is the pack's business; the arithmetic of
 * "take the lower of two numbers and say which one you took" is not. A row that only showed its
 * result would be unauditable, exactly as a production cost showing only its total would be.
 */
final class InventoryService
{
    /** @param array<string, mixed> $ruleModule the resolved bundle; `inventory` is read here */
    public function __construct(
        private readonly Currency $baseCurrency,
        private readonly AccountRepository $accounts,
        private readonly JournalRepository $journal,
        private readonly VoucherRepository $vouchers,
        private readonly CostingRunRepository $runs,
        private readonly InventoryValuationRepository $valuations,
        private readonly Ledger $ledger,
        private readonly IdGenerator $ids,
        private array $ruleModule = [],
        private readonly ?Uuid $tenantId = null,
        private readonly ?AuditWriter $audit = null,
    ) {
    }

    /** @param array<string, mixed> $ruleModule */
    public function setRuleModule(array $ruleModule): void
    {
        $this->ruleModule = $ruleModule;
    }

    /**
     * Value the stock of one period and book the change (`valuateInventory`).
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function valuate(array $input): array
    {
        $fiscalYear = is_int($input['fiscalYear'] ?? null) ? $input['fiscalYear'] : null;
        $period = is_int($input['period'] ?? null) ? $input['period'] : null;

        if ($fiscalYear === null || $period === null) {
            throw new DomainError(
                'E_INPUT_INVALID',
                'valuateInventory: fiscalYear and period are required',
                ['field' => 'fiscalYear'],
            );
        }

        $periodRef = new PeriodRef($fiscalYear, $period);
        $valuationDate = $this->requireDate($input['valuationDate'] ?? null);
        $categoryAccounts = $this->packCategories();
        $run = $this->releasedRun($input['runId'] ?? null);
        $producedQuantity = $this->optionalDecimal($input['producedQuantity'] ?? null, 'producedQuantity');

        $rows = [];
        $lines = [];
        $closingTotal = Money::zero($this->baseCurrency);
        $change = Money::zero($this->baseCurrency);

        foreach ($this->requireCategories($input['categories'] ?? null) as $index => $raw) {
            $row = $this->valuateCategory($raw, $index, $categoryAccounts, $run, $producedQuantity, $periodRef);
            $rows[] = $row;

            $closingTotal = $closingTotal->add(Money::of($row['closingValue'], $this->baseCurrency));
            $rowChange = Money::of($row['change'], $this->baseCurrency);
            $change = $change->add($rowChange);

            if ($rowChange->isZero()) {
                continue;
            }

            // An increase debits the stock account and credits the account the pack names; a
            // decrease does the reverse. One entry for the whole valuation, because it is one act:
            // splitting it per category would produce a set of entries an auditor has to recognise
            // as belonging together from their dates.
            $stockSide = $rowChange->isPositive() ? 'debit' : 'credit';
            $counterSide = $rowChange->isPositive() ? 'credit' : 'debit';
            $absolute = $rowChange->abs()->jsonSerialize();

            $lines[] = ['account' => $row['account'], 'side' => $stockSide, 'money' => $absolute];
            $lines[] = ['account' => $row['changeAccount'], 'side' => $counterSide, 'money' => $absolute];
        }

        $entryId = $lines === []
            ? null
            : $this->postMachineEntry($valuationDate, $periodRef, $lines);

        $valuation = new InventoryValuation(
            $this->ids->next(),
            $periodRef,
            $this->nextVersion($periodRef),
            $valuationDate,
            $run?->id,
            $rows,
            $closingTotal,
            $change,
            $entryId,
        );
        $this->valuations->add($valuation);

        if ($this->audit !== null && $this->tenantId !== null) {
            $this->audit->record(
                $this->audit->actorOf($input),
                'inventoryValuation',
                $valuation->id,
                'valued',
                [
                    'fiscalYear' => ['from' => null, 'to' => $fiscalYear],
                    'period' => ['from' => null, 'to' => $period],
                    'closingTotal' => ['from' => null, 'to' => $closingTotal->amountAsString()],
                    'change' => ['from' => null, 'to' => $change->amountAsString()],
                ],
            );
        }

        return [
            'valuationId' => $valuation->id->value,
            'version' => $valuation->version,
            'closingTotal' => $closingTotal->jsonSerialize(),
            'change' => $change->jsonSerialize(),
            // `false` is an answer, not a failure: a second valuation of an unchanged period books
            // nothing, which is what makes repeating one harmless.
            'posted' => $entryId !== null,
            'entryId' => $entryId?->value,
        ];
    }

    /**
     * What was valued, how, and out of what (`inventoryValuation`).
     *
     * @param array<string, mixed> $params
     *
     * @return array{valuations: list<array<string, mixed>>}
     */
    public function valuationReport(array $params): array
    {
        $fiscalYear = is_int($params['fiscalYear'] ?? null) ? $params['fiscalYear'] : null;
        $period = is_int($params['period'] ?? null) ? $params['period'] : null;

        $rows = [];
        foreach ($this->valuations->all() as $valuation) {
            if ($fiscalYear !== null && $valuation->period->fiscalYear !== $fiscalYear) {
                continue;
            }
            if ($period !== null && $valuation->period->period !== $period) {
                continue;
            }

            $rows[] = [
                'valuationId' => $valuation->id->value,
                'fiscalYear' => $valuation->period->fiscalYear,
                'period' => $valuation->period->period,
                'version' => $valuation->version,
                'valuationDate' => $valuation->valuationDate->iso,
                'runId' => $valuation->runId?->value,
                'categories' => $valuation->categories,
                'closingTotal' => $valuation->closingTotal->amountAsString(),
                'change' => $valuation->change->amountAsString(),
                'entryId' => $valuation->entryId?->value,
            ];
        }

        return ['valuations' => $rows];
    }

    /**
     * @param array<string, mixed> $raw
     * @param array<string, string> $categoryAccounts stock account -> change account
     *
     * @return array{
     *     account: string,
     *     quantity: string,
     *     unitCost: string,
     *     marketValue: string|null,
     *     unitValue: string,
     *     source: string,
     *     openingValue: string,
     *     closingValue: string,
     *     change: string,
     *     changeAccount: string,
     *     writtenDownToMarket: bool
     * }
     */
    private function valuateCategory(
        array $raw,
        int $index,
        array $categoryAccounts,
        ?CostingRun $run,
        ?BigDecimal $producedQuantity,
        PeriodRef $periodRef,
    ): array {
        $number = is_string($raw['account'] ?? null) ? $raw['account'] : '';
        if ($number === '') {
            throw new DomainError('E_INPUT_INVALID', sprintf(
                'valuateInventory: categories[%d] requires "account"',
                $index,
            ), ['field' => 'categories']);
        }

        $account = $this->accounts->byNumber(AccountNumber::of($number));
        if ($account === null) {
            throw new DomainError('E_ACCOUNT_UNKNOWN', sprintf(
                'valuateInventory: account %s does not exist',
                $number,
            ), ['account' => $number]);
        }

        // The reader that earns `inventory` its place in the closed subtype repertoire. Valuing
        // stock onto an account that is not a stock account would balance, satisfy every invariant
        // and put the figure in the wrong balance-sheet position — the inert-annotation defect the
        // repertoire was closed for, with a wrong number instead of a missing one.
        if ($account->subtype !== AccountSubtype::Inventory->value) {
            throw new DomainError('E_INVENTORY_ACCOUNT_INVALID', sprintf(
                'valuateInventory: account %s is not a stock account (subtype "inventory")',
                $number,
            ), ['account' => $number, 'subtype' => $account->subtype]);
        }

        $changeAccount = $categoryAccounts[$number] ?? null;
        if ($changeAccount === null) {
            throw new DomainError('E_PACK_INCOHERENT', sprintf(
                'the pack declares no change account for stock account %s',
                $number,
            ), ['account' => $number]);
        }

        $quantity = $this->requireDecimal($raw['quantity'] ?? null, sprintf('categories[%d].quantity', $index));
        if ($quantity->isNegative()) {
            throw new DomainError('E_INPUT_INVALID', sprintf(
                'valuateInventory: categories[%d] has a negative quantity',
                $index,
            ), ['field' => 'categories', 'account' => $number]);
        }

        [$unitCost, $source] = $this->unitCost($raw, $index, $run, $producedQuantity);
        $marketValue = $this->optionalDecimal($raw['marketValue'] ?? null, sprintf('categories[%d].marketValue', $index));

        $writtenDown = $marketValue !== null && $marketValue->isLessThan($unitCost);
        $unitValue = $writtenDown ? $marketValue : $unitCost;

        // One rounding, on the product — not a rounded unit value multiplied by a quantity. With
        // 3,000 units the two differ by up to fifteen euros, and the difference lands in the
        // balance sheet.
        $closingValue = Money::fromCalculation($quantity->multipliedBy($unitValue), $this->baseCurrency);
        $openingValue = $this->carryingAmount($number, $periodRef);

        return [
            'account' => $number,
            'quantity' => (string) $quantity,
            'unitCost' => (string) $unitCost,
            'marketValue' => $marketValue === null ? null : (string) $marketValue,
            'unitValue' => (string) $unitValue,
            'source' => $source,
            'openingValue' => $openingValue->amountAsString(),
            'closingValue' => $closingValue->amountAsString(),
            'change' => $closingValue->subtract($openingValue)->amountAsString(),
            'changeAccount' => $changeAccount,
            'writtenDownToMarket' => $writtenDown,
        ];
    }

    /**
     * Where a unit value comes from, and it is never guessed.
     *
     * @param array<string, mixed> $raw
     *
     * @return array{0: BigDecimal, 1: string}
     */
    private function unitCost(array $raw, int $index, ?CostingRun $run, ?BigDecimal $producedQuantity): array
    {
        $explicit = $this->optionalDecimal($raw['unitCost'] ?? null, sprintf('categories[%d].unitCost', $index));
        if ($explicit !== null) {
            return [$explicit, 'input'];
        }

        if ($run === null) {
            throw new DomainError('E_INPUT_INVALID', sprintf(
                'valuateInventory: categories[%d] has no unitCost and no released costing run to derive one from',
                $index,
            ), ['field' => 'categories']);
        }

        if ($run->productionCost === null) {
            throw new DomainError('E_INPUT_INVALID', sprintf(
                'costing run %s carries no production cost — declare the components in setAllocationScheme before the run',
                $run->id->value,
            ), ['runId' => $run->id->value]);
        }

        if ($producedQuantity === null || $producedQuantity->isZero() || $producedQuantity->isNegative()) {
            throw new DomainError(
                'E_INPUT_INVALID',
                'valuateInventory: deriving a unit cost from a costing run needs a positive producedQuantity',
                ['field' => 'producedQuantity'],
            );
        }

        // Scale six, then the product is rounded once to the currency scale. A unit cost is not
        // Money — it is an intermediate — so rounding it to cents here would be the very error the
        // comment on `closingValue` avoids, moved one line up.
        try {
            $unit = BigDecimal::of($run->productionCost['total'])->dividedBy($producedQuantity, 6, RoundingMode::HalfUp);
        } catch (MathException) {
            throw new DomainError(
                'E_INPUT_INVALID',
                'valuateInventory: the production cost cannot be divided by the produced quantity',
                ['field' => 'producedQuantity'],
            );
        }

        return [$unit, 'productionCost'];
    }

    /**
     * What the books already carry on this account, up to and including the valuation period.
     *
     * Cumulative across fiscal years, because a stock account is a balance-carrying account and
     * carries forward implicitly (F-CORE-021) — there is no opening entry to read instead.
     */
    private function carryingAmount(string $number, PeriodRef $periodRef): Money
    {
        $total = Money::zero($this->baseCurrency);

        foreach ($this->journal->all() as $entry) {
            $entryYear = $entry->periodRef->fiscalYear;
            if ($entryYear > $periodRef->fiscalYear) {
                continue;
            }
            if ($entryYear === $periodRef->fiscalYear && $entry->periodRef->period > $periodRef->period) {
                continue;
            }

            foreach ($entry->lines() as $line) {
                $account = $this->accounts->byId($line->accountId);
                if ($account === null || $account->number->value !== $number) {
                    continue;
                }

                $total = $line->side === Side::Debit
                    ? $total->add($line->money)
                    : $total->subtract($line->money);
            }
        }

        return $total;
    }

    /** @return array<string, string> stock account -> change account */
    private function packCategories(): array
    {
        $module = is_array($this->ruleModule['inventory'] ?? null) ? $this->ruleModule['inventory'] : null;
        if ($module === null) {
            throw new DomainError(
                'E_PACK_INCOHERENT',
                'inventory was valued, but the pack declares no inventory categories',
                ['field' => 'inventory'],
            );
        }

        $categories = is_array($module['categories'] ?? null) ? $module['categories'] : [];
        $map = [];
        foreach ($categories as $category) {
            if (!is_array($category)) {
                continue;
            }
            $account = $category['account'] ?? null;
            $changeAccount = $category['changeAccount'] ?? null;
            if (is_string($account) && is_string($changeAccount)) {
                $map[$account] = $changeAccount;
            }
        }

        return $map;
    }

    private function releasedRun(mixed $runId): ?CostingRun
    {
        if (!is_string($runId) || $runId === '') {
            return null;
        }

        try {
            $run = $this->runs->byId(Uuid::fromString($runId));
        } catch (InvalidValue) {
            $run = null;
        }

        if ($run === null) {
            throw new DomainError('E_COSTING_RUN_UNKNOWN', sprintf(
                'costing run %s does not exist',
                $runId,
            ), ['runId' => $runId]);
        }

        // A draft is a working figure. Valuing the balance sheet out of one would put a number in
        // the books that its own producer has not stood behind, and the two conditions need
        // opposite corrections — release it, or name a different run — which is why this is not
        // E_COSTING_RUN_UNKNOWN.
        if ($run->status() !== 'released') {
            throw new DomainError('E_COSTING_RUN_NOT_RELEASED', sprintf(
                'costing run %s is a draft — release it before valuing stock from it',
                $runId,
            ), ['runId' => $runId, 'status' => $run->status()]);
        }

        return $run;
    }

    /** @return list<array<string, mixed>> */
    private function requireCategories(mixed $raw): array
    {
        if (!is_array($raw) || $raw === []) {
            throw new DomainError(
                'E_INPUT_INVALID',
                'valuateInventory: at least one category is required — a valuation of nothing is not a valuation of zero',
                ['field' => 'categories'],
            );
        }

        $out = [];
        foreach (array_values($raw) as $entry) {
            if (!is_array($entry)) {
                throw new DomainError(
                    'E_INPUT_INVALID',
                    'valuateInventory: every category must be an object',
                    ['field' => 'categories'],
                );
            }
            /** @var array<string, mixed> $entry */
            $out[] = $entry;
        }

        return $out;
    }

    private function requireDate(mixed $raw): CalendarDate
    {
        if (!is_string($raw) || $raw === '') {
            throw new DomainError(
                'E_INPUT_INVALID',
                'valuateInventory: valuationDate is required',
                ['field' => 'valuationDate'],
            );
        }

        try {
            return CalendarDate::of($raw);
        } catch (InvalidValue) {
            throw new DomainError(
                'E_INPUT_INVALID',
                'valuateInventory: valuationDate is not a calendar date',
                ['field' => 'valuationDate'],
            );
        }
    }

    private function requireDecimal(mixed $raw, string $field): BigDecimal
    {
        $value = $this->optionalDecimal($raw, $field);

        return $value ?? throw new DomainError('E_INPUT_INVALID', sprintf(
            'valuateInventory: %s is required',
            $field,
        ), ['field' => $field]);
    }

    private function optionalDecimal(mixed $raw, string $field): ?BigDecimal
    {
        if ($raw === null) {
            return null;
        }

        // Strings only, exactly as Money takes strings only. A JSON number has already lost the
        // argument before it arrives: 0.1 is not 0.1, and a quantity that reads back differently
        // in the two languages breaks byte parity at the first export.
        if (!is_string($raw) || $raw === '') {
            throw new DomainError('E_INPUT_INVALID', sprintf(
                'valuateInventory: %s must be a decimal string',
                $field,
            ), ['field' => $field]);
        }

        try {
            return BigDecimal::of($raw);
        } catch (MathException) {
            throw new DomainError('E_INPUT_INVALID', sprintf(
                'valuateInventory: %s is not a decimal number',
                $field,
            ), ['field' => $field]);
        }
    }

    private function nextVersion(PeriodRef $periodRef): int
    {
        $version = 0;
        foreach ($this->valuations->all() as $valuation) {
            if (
                $valuation->period->fiscalYear === $periodRef->fiscalYear
                && $valuation->period->period === $periodRef->period
            ) {
                $version = max($version, $valuation->version);
            }
        }

        return $version + 1;
    }

    /**
     * @param list<array<string, mixed>> $lines
     */
    private function postMachineEntry(CalendarDate $date, PeriodRef $periodRef, array $lines): Uuid
    {
        $voucher = new Voucher(
            $this->ids->next(),
            sprintf('INV-%d-%02d', $periodRef->fiscalYear, $periodRef->period),
            $date,
            kind: 'internal',
        );
        $this->vouchers->add($voucher);

        $result = $this->ledger->post([
            'entryDate' => $date->iso,
            'voucherId' => $voucher->id->value,
            'text' => sprintf('Inventory valuation %d/%02d', $periodRef->fiscalYear, $periodRef->period),
            'lines' => $lines,
        ]);

        // Machine-generated, like a depreciation run: finalized immediately, because a hand
        // correction of a valuation posting would leave the record and the books disagreeing.
        $this->ledger->finalize(['entryId' => $result->entry->id->value]);

        return $result->entry->id;
    }
}
