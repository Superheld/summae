<?php

declare(strict_types=1);

namespace Summae\Core\Policies\Expansion\Assets;

use Summae\Core\DomainError;
use Summae\Core\Ledger\AuditWriter;
use Summae\Core\Ledger\Ledger;
use Summae\Core\Port\AssetRepository;
use Summae\Core\Port\FiscalYearRepository;
use Summae\Core\Port\VoucherRepository;
use Summae\Core\Records\Voucher;
use Summae\Core\Substrate\AccountNumber;
use Summae\Core\Substrate\CalendarDate;
use Summae\Core\Substrate\Currency;
use Summae\Core\Substrate\Exception\InvalidValue;
use Summae\Core\Substrate\IdGenerator;
use Summae\Core\Substrate\Money;
use Summae\Core\Substrate\Uuid;

/**
 * Asset subledger (assets-modell.md): low-value-asset switch at acquisition,
 * depreciation run idempotent per run target, postings as normal journal
 * entries through the ledger (no special path) — machine-generated entries
 * are finalized immediately (machine entries are not hand-correctable).
 *
 * Depreciation distribution (determinismus.md §2): monthly values = allocate
 * of the acquisition cost over the useful life (flat); yearly values = allocate
 * by months per calendar year — no residual remainder, Σ = acquisition cost exactly.
 *
 * Account resolution (spec gap, see FINDINGS-CLOSED.md): rule-module keys
 * `depreciationExpenseAccount`/`gwgExpenseAccount`/`acquisitionCounterAccount`,
 * otherwise convention: the single bank account as counter account; depreciation
 * account by name prefix "AfA", low-value-asset account by name part "GWG".
 */
final class AssetService
{
    /**
     * @param array<string, mixed> $ruleModule gwgThresholds, usefulLife, account keys
     */
    public function __construct(
        private readonly Currency $baseCurrency,
        private readonly AssetRepository $assets,
        private readonly FiscalYearRepository $fiscalYears,
        private readonly VoucherRepository $vouchers,
        private readonly Ledger $ledger,
        private readonly IdGenerator $ids,
        private array $ruleModule = [],
        // An asset run posts through the ledger, so `journalEntry/created` records exist — but
        // nothing in them says *an asset was acquired*. These records carry the asset event
        // itself (F-CORE-014); the depreciation run has no object of its own, so it names the
        // tenant, like the configuration singletons.
        private readonly ?Uuid $tenantId = null,
        private readonly ?AuditWriter $audit = null,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, array{from: mixed, to: mixed}> $changes
     */
    private function trace(array $input, string $objectType, Uuid $objectId, string $action, array $changes): void
    {
        if ($this->audit === null) {
            return;
        }

        $this->audit->record($this->audit->actorOf($input), $objectType, $objectId, $action, $changes);
    }

    /** @param array<string, mixed> $ruleModule */
    public function setRuleModule(array $ruleModule): void
    {
        $this->ruleModule = $ruleModule;
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function acquire(array $input): array
    {
        $name = is_string($input['name'] ?? null) ? $input['name'] : '';
        $assetClass = is_string($input['assetClass'] ?? null) ? $input['assetClass'] : '';
        $assetAccount = AccountNumber::of(is_string($input['assetAccount'] ?? null) ? $input['assetAccount'] : '0');
        $cost = $this->parseMoney($input['acquisitionCost'] ?? null);
        $acquiredOn = CalendarDate::of(is_string($input['acquiredOn'] ?? null) ? $input['acquiredOn'] : '');
        $voucherId = is_string($input['voucherId'] ?? null) ? Uuid::fromString($input['voucherId']) : throw new InvalidValue('acquireAsset requires voucherId');
        $choice = is_string($input['gwgChoice'] ?? null) ? $input['gwgChoice'] : 'auto';
        $dimensions = self::parseDimensions($input['dimensions'] ?? null);

        $special = ($input['specialDepreciation'] ?? false) === true;
        $totalUnits = self::parseTotalUnits($input['totalUnits'] ?? null);
        $route = $this->resolveRoute($choice, $cost, $acquiredOn);
        $explicitLife = self::parseUsefulLifeMonths($input['usefulLifeMonths'] ?? null);
        $method = self::parseMethod($input['depreciationMethod'] ?? null);

        // Same reasoning as the useful life: a method cannot apply to a route that has no schedule
        // of its own, and accepting it in silence would suggest it took effect.
        if ($method !== 'straight_line' && $route !== AssetRoute::Capitalize) {
            throw new DomainError(
                'E_INPUT_INVALID',
                sprintf('acquireAsset: "depreciationMethod" applies to a capitalised asset, not to route "%s"', $route->value),
                ['depreciationMethod' => $method, 'route' => $route->value],
            );
        }

        // Same again: an additional allowance is a share of a capitalised asset's cost, and there is
        // nothing for it to attach to on a route that expenses the whole cost at once.
        if ($special && $route !== AssetRoute::Capitalize) {
            throw new DomainError(
                'E_INPUT_INVALID',
                sprintf('acquireAsset: "specialDepreciation" applies to a capitalised asset, not to route "%s"', $route->value),
                ['route' => $route->value],
            );
        }

        // Refused, not ignored. A pooled asset takes its term from the pack's poolYears and an
        // immediately expensed one has no schedule at all, so a life given with either route
        // cannot be honoured — and dropping it in silence would let a caller believe a number
        // took effect that never did.
        if ($explicitLife !== null && $route !== AssetRoute::Capitalize) {
            throw new DomainError(
                'E_INPUT_INVALID',
                sprintf('acquireAsset: "usefulLifeMonths" applies to a capitalised asset, not to route "%s"', $route->value),
                ['usefulLifeMonths' => $explicitLife, 'route' => $route->value],
            );
        }

        $usefulLifeMonths = null;
        $schedule = [];
        if ($route === AssetRoute::Capitalize) {
            // The caller's own figure wins over the class average. A table of class averages cannot
            // serve a jurisdiction that lets a taxpayer prove a shorter life for an individual asset,
            // however complete the table is — and without this parameter an asset class missing from
            // the pack was simply unusable.
            $usefulLifeMonths = $explicitLife ?? $this->usefulLifeMonths($assetClass);

            if ($method === 'units_of_production') {
                // No schedule, on purpose. Output-based depreciation cannot know at acquisition what
                // any future period will take — the number comes from outside the books — so there is
                // nothing to plan and the yearly run has nothing to do for this asset.
                $schedule = [];
            } else {
                $schedule = $method === 'declining_balance'
                    ? $this->decliningBalanceSchedule($cost, $usefulLifeMonths, $acquiredOn, $assetClass)
                    : $cost->allocateEvenly($usefulLifeMonths);
            }
        } elseif ($route === AssetRoute::Pool) {
            // Pool period comes from the pack (SPEC-004): a fixed five years used to sit here, which is
            // one jurisdiction's rule, so every other jurisdiction with a pooled de-minimis regime
            // would have inherited it silently. The pack says over how long; the core only spreads it
            // evenly (disposals leave the plan untouched, as before).
            $poolYears = $this->poolYears($acquiredOn);
            $usefulLifeMonths = $poolYears * 12;
            $annual = $cost->allocateEvenly($poolYears);
            foreach ($annual as $yearAmount) {
                $monthly = $yearAmount->allocateEvenly(12);
                foreach ($monthly as $monthAmount) {
                    $schedule[] = $monthAmount;
                }
            }
        }

        if ($method === 'units_of_production' && $totalUnits === null) {
            throw new DomainError(
                'E_INPUT_INVALID',
                'acquireAsset: "units_of_production" needs "totalUnits" — the expected output is what replaces the schedule',
                ['depreciationMethod' => $method],
            );
        }

        if ($totalUnits !== null && $method !== 'units_of_production') {
            throw new DomainError(
                'E_INPUT_INVALID',
                'acquireAsset: "totalUnits" applies to "units_of_production", and would have no effect otherwise',
                ['depreciationMethod' => $method],
            );
        }

        [$specialBudget, $specialWindowEnd] = $special
            ? $this->specialDepreciationTerms($cost, $acquiredOn, $assetClass)
            : [null, null];

        $asset = new Asset(
            $this->ids->next(),
            $name,
            $assetClass,
            $assetAccount,
            $cost,
            $acquiredOn,
            $route,
            $usefulLifeMonths,
            $schedule,
            $voucherId,
            $dimensions,
            $this->planStartFor($route, $acquiredOn),
            $method,
            $specialBudget,
            $specialWindowEnd,
            $totalUnits,
        );

        $this->assets->add($asset);

        // Acquisition entry: capitalization or immediate expense against cash account.
        $targetAccount = $route === AssetRoute::ImmediateExpense
            ? $this->gwgExpenseAccount()
            : $assetAccount->value;

        $this->postMachineEntry(
            $acquiredOn,
            $voucherId,
            sprintf('Asset acquisition %s', $name),
            $this->withDimensions($asset, [
                ['account' => $targetAccount, 'side' => 'debit', 'money' => $cost->jsonSerialize()],
                ['account' => $this->counterAccount(), 'side' => 'credit', 'money' => $cost->jsonSerialize()],
            ]),
        );

        $this->trace($input, 'asset', $asset->id, 'acquired', [
            'name' => ['from' => null, 'to' => $name],
            'assetClass' => ['from' => null, 'to' => $assetClass],
            'acquiredOn' => ['from' => null, 'to' => $acquiredOn->iso],
            'route' => ['from' => null, 'to' => $route->value],
        ]);

        $result = $asset->jsonSerialize();
        $result['route'] = $route->value;
        if ($route === AssetRoute::ImmediateExpense) {
            $result['expenseAccount'] = $targetAccount;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function dispose(array $input): array
    {
        $asset = $this->requireAsset($input['assetId'] ?? null);
        $asset->assertActive();

        $disposedOn = CalendarDate::of(is_string($input['disposedOn'] ?? null) ? $input['disposedOn'] : '');
        $asset->dispose($disposedOn);
        $this->assets->save($asset);

        $proceeds = is_array($input['proceeds'] ?? null) ? $this->parseMoney($input['proceeds']) : null;
        $proceedsAccount = is_string($input['proceedsAccount'] ?? null) ? $input['proceedsAccount'] : null;
        $bankAccount = is_string($input['bankAccount'] ?? null) ? $input['bankAccount'] : $this->counterAccount();
        $voucherId = is_string($input['voucherId'] ?? null)
            ? Uuid::fromString($input['voucherId'])
            : $asset->voucherId;

        // Where the pack keeps a disposed item in the pool (F-AST-006, see runDepreciation), the
        // pool keeps running its term and there is no carrying amount of its own to clear — only
        // the proceeds are booked. Where the pack takes it out, it is written off like any other.
        $staysPooled = $this->staysInPool($asset);
        if (!$staysPooled) {
            $this->catchUpDepreciation($asset, $disposedOn, $voucherId);
        }
        // What is written off is what stands on the account, not what an as-of query says stood
        // there on the disposal date (IMPL-026). The two are not the same: a yearly run books the
        // whole year in one entry dated 31 December, so a disposal on 30 September read the
        // carrying amount, saw that entry as later than itself, ignored it, and wrote off the full
        // acquisition cost — of which the run had already written off a part. The asset account was
        // left at a credit balance that nothing clears, while the entry balanced and every
        // invariant held. Every other reader of the accumulated depreciation in this service
        // already asks the whole ledger; the disposal was the only as-of query, and it is the one
        // place an as-of query cannot be right, because what leaves the account must equal what
        // stands on it (F-AST-004).
        $carrying = $staysPooled ? Money::zero($this->baseCurrency) : $asset->bookValueAt(null);
        $lines = $this->disposalLines($asset, $carrying, $proceeds, $bankAccount, $proceedsAccount);

        if ($lines !== []) {
            $this->postMachineEntry($disposedOn, $voucherId, sprintf('Asset disposal %s', $asset->name), $this->withDimensions($asset, $lines));
        }

        $this->trace($input, 'asset', $asset->id, 'disposed', [
            'status' => ['from' => 'active', 'to' => 'disposed'],
            'disposedOn' => ['from' => null, 'to' => $disposedOn->iso],
        ]);

        return $asset->jsonSerialize();
    }

    /**
     * Reports what the asset has produced, and writes off what that use consumed.
     *
     * Where a jurisdiction allows it, an asset that wears by use rather than by time may be
     * depreciated by output — kilometres, operating hours, copies. There is nothing to plan: the
     * number comes from goods movements, meter readings, job cards, none of which are in the books.
     * So the caller reports the meter and the core does the arithmetic.
     *
     * The arithmetic is cumulative on purpose. Each report splits the acquisition cost between what
     * the asset has now given and what it has not, and books the difference against what is already
     * written off. Computing each period on its own would let rounding drift, and the last report
     * would leave a stray cent behind on an asset that is fully used up. This way the final report,
     * the one that reaches the total output, lands on the cost exactly.
     *
     * More output than expected is not an error — a lorry can outlive its estimate — but there is no
     * more cost to write off, so the booking is capped at the book value and the answer says so.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function reportAssetUsage(array $input): array
    {
        $asset = $this->requireAsset($input['assetId'] ?? null);
        $asset->assertActive();

        if ($asset->totalUnits === null) {
            throw new DomainError('E_INPUT_INVALID', sprintf(
                'asset %s is not depreciated by output — "units_of_production" has to be chosen on acquisition',
                $asset->id->value,
            ), ['assetId' => $asset->id->value]);
        }

        $units = self::parseTotalUnits($input['units'] ?? null);
        if ($units === null) {
            throw new DomainError('E_INPUT_INVALID', 'reportAssetUsage: "units" is required', [
                'assetId' => $asset->id->value,
            ]);
        }

        $fiscalYear = is_int($input['fiscalYear'] ?? null) ? $input['fiscalYear'] : 0;
        $bookValue = $asset->acquisitionCost->subtract($asset->accumulatedDepreciationAt(null));

        if ($bookValue->isZero()) {
            throw new DomainError('E_INPUT_INVALID', sprintf(
                'asset %s is already fully depreciated — further output has nothing left to write off',
                $asset->id->value,
            ), ['assetId' => $asset->id->value]);
        }

        $cumulative = $asset->reportedUnits() + $units;
        $capped = $cumulative > $asset->totalUnits;
        $effective = $capped ? $asset->totalUnits : $cumulative;

        $target = $effective === $asset->totalUnits
            ? $asset->acquisitionCost
            : $asset->acquisitionCost->allocate($effective, $asset->totalUnits - $effective)[0];

        $written = $asset->accumulatedDepreciationAt(null);
        $amount = $target->subtract($written);

        if ($amount->isNegative() || $amount->isZero()) {
            throw new DomainError('E_INPUT_INVALID', sprintf(
                'reported output for asset %s writes off nothing — %d of %d units are already accounted for',
                $asset->id->value,
                $asset->reportedUnits(),
                $asset->totalUnits,
            ), ['assetId' => $asset->id->value]);
        }

        $date = CalendarDate::of(sprintf('%04d-12-31', $fiscalYear));

        $voucherId = is_string($input['voucherId'] ?? null) && $input['voucherId'] !== ''
            ? $this->requireVoucherId($input['voucherId'])
            : $this->usageVoucher($asset, $fiscalYear);

        $entry = $this->postMachineEntry(
            $date,
            $voucherId,
            sprintf('Output depreciation %s %d (%d units)', $asset->name, $fiscalYear, $units),
            $this->withDimensions($asset, [
                ['account' => $this->depreciationExpenseAccount(), 'side' => 'debit', 'money' => $amount->jsonSerialize()],
                ['account' => $asset->assetAccount->value, 'side' => 'credit', 'money' => $amount->jsonSerialize()],
            ]),
        );

        $before = $asset->reportedUnits();
        $asset->recordUsage($date, $amount, $entry, $effective);
        $this->assets->save($asset);

        $this->trace($input, 'asset', $asset->id, 'usageReported', [
            'reportedUnits' => ['from' => (string) $before, 'to' => (string) $effective],
            'bookValue' => [
                'from' => $bookValue->amountAsString(),
                'to' => $asset->acquisitionCost->subtract($asset->accumulatedDepreciationAt(null))->amountAsString(),
            ],
        ]);

        return [
            'assetId' => $asset->id->value,
            'entryId' => $entry->value,
            'amount' => $amount->amountAsString(),
            'reportedUnits' => $effective,
            'totalUnits' => $asset->totalUnits,
            'capped' => $capped,
            'bookValue' => $asset->acquisitionCost->subtract($asset->accumulatedDepreciationAt(null))->amountAsString(),
        ];
    }

    private function usageVoucher(Asset $asset, int $fiscalYear): Uuid
    {
        $voucher = new Voucher(
            $this->ids->next(),
            sprintf('LAFA-%d-%s', $fiscalYear, substr($asset->id->value, -6)),
            CalendarDate::of(sprintf('%04d-12-31', $fiscalYear)),
            kind: 'internal',
        );
        $this->vouchers->add($voucher);

        return $voucher->id;
    }

    /**
     * Books part of an additional allowance (see `Asset::$specialDepreciationBudget`).
     *
     * Two things make this an operation rather than a plan. The amount is the taxpayer's to choose —
     * a jurisdiction that grants "up to 40 % over five years" grants exactly that, and any split is
     * as valid as any other — and the entitlement itself is a question about the business (a profit
     * limit, a share of business use) that summae has no way to check and does not pretend to. So the
     * caller says how much and when, and the core enforces only what it can know: not more than the
     * budget, not outside the window, not on an asset that has none.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function bookSpecialDepreciation(array $input): array
    {
        $asset = $this->requireAsset($input['assetId'] ?? null);
        $asset->assertActive();

        $remaining = $asset->specialDepreciationRemaining();
        if ($remaining === null || $asset->specialDepreciationWindowEnd === null) {
            throw new DomainError('E_INPUT_INVALID', sprintf(
                'asset %s carries no special depreciation — it has to be elected on acquisition',
                $asset->id->value,
            ), ['assetId' => $asset->id->value]);
        }

        $fiscalYear = is_int($input['fiscalYear'] ?? null) ? $input['fiscalYear'] : 0;
        $firstYear = $this->planMonthYear($asset, 1);

        if ($fiscalYear < $firstYear || $fiscalYear > $asset->specialDepreciationWindowEnd) {
            throw new DomainError('E_INPUT_INVALID', sprintf(
                'special depreciation for asset %s is available in %d through %d, not in %d',
                $asset->id->value,
                $firstYear,
                $asset->specialDepreciationWindowEnd,
                $fiscalYear,
            ), ['fiscalYear' => $fiscalYear]);
        }

        $amount = $this->parseMoney($input['amount'] ?? null);
        if ($amount->isNegative() || $amount->isZero()) {
            throw new DomainError('E_INPUT_INVALID', 'bookSpecialDepreciation: "amount" must be greater than zero', [
                'amount' => $amount->amountAsString(),
            ]);
        }

        if ($amount->compareTo($remaining) > 0) {
            throw new DomainError('E_INPUT_INVALID', sprintf(
                'special depreciation of %s exceeds the remaining allowance of %s',
                $amount->amountAsString(),
                $remaining->amountAsString(),
            ), ['amount' => $amount->amountAsString(), 'remaining' => $remaining->amountAsString()]);
        }

        $date = CalendarDate::of(sprintf('%04d-12-31', $fiscalYear));

        $voucherId = is_string($input['voucherId'] ?? null) && $input['voucherId'] !== ''
            ? $this->requireVoucherId($input['voucherId'])
            : $this->specialDepreciationVoucher($asset, $fiscalYear);

        $entry = $this->postMachineEntry(
            $date,
            $voucherId,
            sprintf('Special depreciation %s %d', $asset->name, $fiscalYear),
            $this->withDimensions($asset, [
                ['account' => $this->depreciationExpenseAccount(), 'side' => 'debit', 'money' => $amount->jsonSerialize()],
                ['account' => $asset->assetAccount->value, 'side' => 'credit', 'money' => $amount->jsonSerialize()],
            ]),
        );

        $asset->recordSpecialDepreciation($date, $amount, $entry);
        $this->assets->save($asset);

        $left = $asset->specialDepreciationRemaining();

        $this->trace($input, 'asset', $asset->id, 'specialDepreciationBooked', [
            'remainingAllowance' => ['from' => $remaining->amountAsString(), 'to' => $left?->amountAsString()],
            'fiscalYear' => ['from' => null, 'to' => (string) $fiscalYear],
        ]);

        return [
            'assetId' => $asset->id->value,
            'entryId' => $entry->value,
            'amount' => $amount->amountAsString(),
            'remainingAllowance' => $left?->amountAsString(),
            'bookValue' => $asset->acquisitionCost->subtract($asset->accumulatedDepreciationAt(null))->amountAsString(),
        ];
    }

    private function specialDepreciationVoucher(Asset $asset, int $fiscalYear): Uuid
    {
        $voucher = new Voucher(
            $this->ids->next(),
            sprintf('SAFA-%d-%s', $fiscalYear, substr($asset->id->value, -6)),
            CalendarDate::of(sprintf('%04d-12-31', $fiscalYear)),
            kind: 'internal',
        );
        $this->vouchers->add($voucher);

        return $voucher->id;
    }

    /**
     * Rate and window of the additional allowance, from the pack. Refused rather than defaulted, like
     * every other pack question here: a core that invents "40 % over five years" has one
     * jurisdiction's tax policy in the substrate.
     *
     * @return array{0: Money, 1: int}
     */
    private function specialDepreciationTerms(Money $cost, CalendarDate $acquiredOn, string $assetClass): array
    {
        foreach (is_array($this->ruleModule['specialDepreciation'] ?? null) ? $this->ruleModule['specialDepreciation'] : [] as $raw) {
            if (!is_array($raw) || !is_string($raw['validFrom'] ?? null)) {
                continue;
            }

            $validFrom = CalendarDate::of($raw['validFrom']);
            $validTo = is_string($raw['validTo'] ?? null) ? CalendarDate::of($raw['validTo']) : null;

            if ($acquiredOn->isBefore($validFrom) || ($validTo !== null && $acquiredOn->isAfter($validTo))) {
                continue;
            }

            $rate = is_string($raw['rate'] ?? null) ? $raw['rate'] : null;
            $years = is_int($raw['years'] ?? null) ? $raw['years'] : null;

            if ($rate === null || $years === null || $years < 1) {
                continue;
            }

            $budget = $cost->allocate($rate, (string) round(100 - (float) $rate, 4))[0];

            return [$budget, $acquiredOn->year() + $years - 1];
        }

        throw new DomainError('E_PACK_INCOHERENT', sprintf(
            'special depreciation was elected, but the pack declares no allowance in force on %s',
            $acquiredOn->iso,
        ), ['field' => 'specialDepreciation', 'acquiredOn' => $acquiredOn->iso, 'assetClass' => $assetClass]);
    }

    /**
     * An unplanned write-down — the value fell, and not because time passed.
     *
     * The planned schedule answers wear and tear; it has nothing to say about a machine damaged in
     * March or a building whose neighbourhood lost its factory. Where the loss is expected to last,
     * writing the asset down is not an option a preparer takes but an obligation, and until now the
     * only ways to express it were disposing of the asset (wrong — it still exists) or posting by
     * hand past the asset register (wrong — the register then disagrees with the ledger about what
     * the asset is worth).
     *
     * A reason is required. An unplanned write-down that does not say why is not auditable, and
     * "why" is the whole difference between an impairment and a mistake.
     *
     * The remaining plan is rewritten: what is left after the write-down is spread over the plan
     * months still open. Leaving the plan alone would depreciate past zero; stopping the plan would
     * finish the asset early. Carrying the reduced value over the remaining life is what a lasting
     * impairment means.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function writeDownAsset(array $input): array
    {
        $asset = $this->requireAsset($input['assetId'] ?? null);
        $asset->assertActive();

        $reason = is_string($input['reason'] ?? null) ? trim($input['reason']) : '';
        if ($reason === '') {
            throw new DomainError(
                'E_INPUT_INVALID',
                'writeDownAsset: "reason" is required — an unplanned write-down that does not say why is not auditable',
                ['assetId' => $asset->id->value],
            );
        }

        $amount = $this->parseMoney($input['amount'] ?? null);
        if ($amount->isNegative() || $amount->isZero()) {
            throw new DomainError('E_INPUT_INVALID', 'writeDownAsset: "amount" must be greater than zero', [
                'amount' => $amount->amountAsString(),
            ]);
        }

        $bookValue = $asset->acquisitionCost->subtract($asset->accumulatedDepreciationAt(null));
        if ($amount->compareTo($bookValue) > 0) {
            throw new DomainError('E_INPUT_INVALID', sprintf(
                'writeDownAsset: %s exceeds the book value of %s — an asset cannot be written below zero',
                $amount->amountAsString(),
                $bookValue->amountAsString(),
            ), ['amount' => $amount->amountAsString(), 'bookValue' => $bookValue->amountAsString()]);
        }

        $date = CalendarDate::of(is_string($input['date'] ?? null) ? $input['date'] : '');

        $openPlanMonths = [];
        for ($planMonth = 1; $planMonth <= count($asset->monthlySchedule); $planMonth++) {
            if (!$asset->isMonthBooked($planMonth)) {
                $openPlanMonths[] = $planMonth;
            }
        }

        $voucherId = is_string($input['voucherId'] ?? null) && $input['voucherId'] !== ''
            ? $this->requireVoucherId($input['voucherId'])
            : $this->writeDownVoucher($asset, $date);

        $entry = $this->postMachineEntry(
            $date,
            $voucherId,
            sprintf('Write-down %s: %s', $asset->name, $reason),
            $this->withDimensions($asset, [
                ['account' => $this->impairmentExpenseAccount(), 'side' => 'debit', 'money' => $amount->jsonSerialize()],
                ['account' => $asset->assetAccount->value, 'side' => 'credit', 'money' => $amount->jsonSerialize()],
            ]),
        );

        $asset->recordWriteDown($date, $amount, $entry, $openPlanMonths);
        $this->assets->save($asset);

        $newBookValue = $asset->acquisitionCost->subtract($asset->accumulatedDepreciationAt(null));

        $this->trace($input, 'asset', $asset->id, 'writtenDown', [
            'bookValue' => ['from' => $bookValue->amountAsString(), 'to' => $newBookValue->amountAsString()],
            'reason' => ['from' => null, 'to' => $reason],
        ]);

        return [
            'assetId' => $asset->id->value,
            'entryId' => $entry->value,
            'amount' => $amount->amountAsString(),
            'bookValue' => $newBookValue->amountAsString(),
            'remainingPlanMonths' => count($openPlanMonths),
        ];
    }

    private function rebaseAfterSpecialWindow(Asset $asset, int $fiscalYear): void
    {
        if (
            $asset->specialDepreciationWindowEnd === null
            || $fiscalYear <= $asset->specialDepreciationWindowEnd
            || $asset->scheduleWasRevised()
            || !$asset->hasSpecialDepreciation()
        ) {
            return;
        }

        $openPlanMonths = [];
        for ($planMonth = 1; $planMonth <= count($asset->monthlySchedule); $planMonth++) {
            if (!$asset->isMonthBooked($planMonth)) {
                $openPlanMonths[] = $planMonth;
            }
        }

        $asset->rebaseRemainingPlan($openPlanMonths);
        $this->assets->save($asset);
    }

    private function writeDownVoucher(Asset $asset, CalendarDate $date): Uuid
    {
        $voucher = new Voucher(
            $this->ids->next(),
            sprintf('AFAA-%s-%s', str_replace('-', '', $date->iso), substr($asset->id->value, -6)),
            $date,
            kind: 'internal',
        );
        $this->vouchers->add($voucher);

        return $voucher->id;
    }

    private function requireVoucherId(string $voucherId): Uuid
    {
        $voucher = $this->vouchers->byId(Uuid::fromString($voucherId));

        if ($voucher === null) {
            throw new DomainError('E_VOUCHER_UNKNOWN', sprintf(
                'voucher %s does not exist',
                $voucherId,
            ), ['voucherId' => $voucherId]);
        }

        return $voucher->id;
    }

    /**
     * Where an unplanned write-down is booked. A pack that separates it from ordinary depreciation
     * says so; one that does not gets the depreciation account, which is what it had before this
     * operation existed and is not wrong, only less informative.
     */
    private function impairmentExpenseAccount(): string
    {
        $block = is_array($this->ruleModule['assetAccounts'] ?? null) ? $this->ruleModule['assetAccounts'] : [];
        $value = $block['impairmentExpenseAccount'] ?? null;

        return is_string($value) && $value !== '' ? $value : $this->depreciationExpenseAccount();
    }

    /**
     * Depreciation run: yearly or monthly run, idempotent per run target
     * (repetition: no-op with alreadyRun, api.md).
     *
     * @param array<string, mixed> $input {fiscalYear} | {fiscalYear, period}
     *
     * @return array<string, mixed>
     */
    public function runDepreciation(array $input): array
    {
        $fiscalYear = is_int($input['fiscalYear'] ?? null) ? $input['fiscalYear'] : 0;
        $period = is_int($input['period'] ?? null) ? $input['period'] : null;

        $entriesCreated = 0;
        $total = Money::zero($this->baseCurrency);

        foreach ($this->assets->all() as $asset) {
            if ($asset->route !== AssetRoute::Capitalize && $asset->route !== AssetRoute::Pool) {
                continue;
            }

            // A disposed asset stops depreciating — unless its pack keeps it in the pool. Whether
            // a disposal reduces the pool is declared per jurisdiction (poolReducedOnDisposal);
            // where it does not, the pool runs its fixed term no matter what happened to the
            // individual items (F-AST-006). Stopping unconditionally understated depreciation and
            // overstated profit for every remaining year of the term.
            if ($asset->isDisposed() && !$this->staysInPool($asset)) {
                continue;
            }

            // The window closed, and part of the cost left the plan while it was open. What is left
            // has to last the remaining life — otherwise the plan keeps asking for the original
            // yearly amount and runs the book value below zero. Once, and only once, per asset.
            $this->rebaseAfterSpecialWindow($asset, $fiscalYear);

            [$months, $amount] = $period === null
                ? $this->yearTarget($asset, $fiscalYear)
                : $this->monthTarget($asset, $fiscalYear, $period);

            if ($months === [] || $amount->isZero()) {
                continue;
            }

            $bookingDate = $this->bookingDate($asset, $fiscalYear, $period, $months);

            $entry = $this->postMachineEntry(
                $bookingDate,
                $this->depreciationVoucher($asset, $fiscalYear, $period),
                sprintf('Depreciation %s %d%s', $asset->name, $fiscalYear, $period === null ? '' : sprintf('/%02d', $period)),
                $this->withDimensions($asset, [
                    ['account' => $this->depreciationExpenseAccount(), 'side' => 'debit', 'money' => $amount->jsonSerialize()],
                    ['account' => $asset->assetAccount->value, 'side' => 'credit', 'money' => $amount->jsonSerialize()],
                ]),
            );

            // Record the distribution over the plan months (idempotency + asOf).
            $monthAmounts = count($months) === 1
                ? [$amount]
                : $this->monthAmounts($asset, $months, $amount);

            foreach ($months as $index => $planMonth) {
                $asset->recordDepreciation($planMonth, $bookingDate, $monthAmounts[$index], $entry);
            }

            $this->assets->save($asset);
            $entriesCreated++;
            $total = $total->add($amount);
        }

        // A run that created nothing is still an event: "someone ran depreciation for this
        // period and it was already done" is exactly what an auditor reconstructing a timeline
        // wants to see, and leaving it out would make repeated runs invisible.
        if ($this->tenantId !== null) {
            $this->trace($input, 'depreciationRun', $this->tenantId, 'completed', [
                'fiscalYear' => ['from' => null, 'to' => $fiscalYear],
                'period' => ['from' => null, 'to' => $period],
                'entriesCreated' => ['from' => null, 'to' => $entriesCreated],
            ]);
        }

        if ($entriesCreated === 0) {
            return ['alreadyRun' => true, 'entriesCreated' => 0];
        }

        return [
            'entriesCreated' => $entriesCreated,
            'totalDepreciation' => $total->jsonSerialize(),
        ];
    }

    public function requireAsset(mixed $assetId): Asset
    {
        $asset = null;

        if (is_string($assetId) && $assetId !== '') {
            try {
                $asset = $this->assets->byId(Uuid::fromString($assetId));
            } catch (InvalidValue) {
                $asset = null;
            }
        }

        return $asset ?? throw new DomainError('E_ASSET_UNKNOWN', sprintf(
            'asset %s does not exist',
            is_string($assetId) ? $assetId : '?',
        ));
    }

    // ---- internal --------------------------------------------------------

    /**
     * Year target: all open plan months of the calendar year; amount =
     * yearly allocation (month weights per year) minus what is already booked.
     *
     * @return array{0: list<int>, 1: Money}
     */
    private function yearTarget(Asset $asset, int $fiscalYear): array
    {
        $monthsByYear = [];
        $life = count($asset->monthlySchedule);

        for ($planMonth = 1; $planMonth <= $life; $planMonth++) {
            $monthsByYear[$this->planMonthYear($asset, $planMonth)][] = $planMonth;
        }

        if (!isset($monthsByYear[$fiscalYear])) {
            return [[], Money::zero($this->baseCurrency)];
        }

        if ($asset->scheduleIsAuthoritative()) {
            // A declining-balance plan cannot be re-derived from month counts — each year depends on
            // what the previous one left. The schedule IS the plan, so the year's target is simply
            // the sum of its months. Straight line keeps re-allocating, which is what pins its
            // rounding to the values every existing fixture expects.
            $yearAmount = Money::zero($this->baseCurrency);
            foreach ($monthsByYear[$fiscalYear] as $planMonth) {
                $yearAmount = $yearAmount->add($asset->monthlySchedule[$planMonth - 1]);
            }
        } else {
            $years = array_keys($monthsByYear);
            $weights = array_map(static fn (int $year): int => count($monthsByYear[$year]), $years);
            $yearAmounts = $asset->acquisitionCost->allocate(...$weights);
            $yearIndex = array_search($fiscalYear, $years, true);
            if ($yearIndex === false) {
                return [[], Money::zero($this->baseCurrency)];
            }
            $yearAmount = $yearAmounts[$yearIndex];
        }

        $openMonths = [];
        $bookedAmount = Money::zero($this->baseCurrency);

        foreach ($monthsByYear[$fiscalYear] as $planMonth) {
            if ($asset->isMonthBooked($planMonth)) {
                $bookedAmount = $bookedAmount->add($asset->monthlySchedule[$planMonth - 1]);
                continue;
            }

            $openMonths[] = $planMonth;
        }

        $amount = $yearAmount->subtract($bookedAmount);

        if ($openMonths === [] || !$amount->isPositive()) {
            return [[], Money::zero($this->baseCurrency)];
        }

        return [$openMonths, $amount];
    }

    /**
     * Month target: the plan month that falls in (fiscalYear, period) —
     * amount from the flat monthly schedule (determinismus.md §2).
     *
     * @return array{0: list<int>, 1: Money}
     */
    private function monthTarget(Asset $asset, int $fiscalYear, int $period): array
    {
        $year = $this->fiscalYears->byYear($fiscalYear);
        if ($year === null) {
            throw new DomainError('E_PERIOD_UNKNOWN', sprintf('fiscal year %d is not set up', $fiscalYear));
        }

        $periodEntity = $year->period($period);
        $life = count($asset->monthlySchedule);

        for ($planMonth = 1; $planMonth <= $life; $planMonth++) {
            $date = $asset->planMonthDate($planMonth);

            if (!$periodEntity->contains($date)) {
                continue;
            }

            if ($asset->isMonthBooked($planMonth)) {
                return [[], Money::zero($this->baseCurrency)];
            }

            return [[$planMonth], $asset->monthlySchedule[$planMonth - 1]];
        }

        return [[], Money::zero($this->baseCurrency)];
    }

    /**
     * @param list<int> $months
     *
     * @return list<Money>
     */
    private function monthAmounts(Asset $asset, array $months, Money $total): array
    {
        // Distribute the yearly amount over the open months — the difference to
        // the flat schedule lands deterministically up front via largest remainder.
        $planned = array_map(
            static fn (int $planMonth): Money => $asset->monthlySchedule[$planMonth - 1],
            $months,
        );

        $plannedSum = Money::zero($this->baseCurrency);
        foreach ($planned as $amount) {
            $plannedSum = $plannedSum->add($amount);
        }

        if ($plannedSum->equals($total)) {
            return $planned;
        }

        return $total->allocateEvenly(count($months));
    }

    /** @param list<int> $months */
    private function bookingDate(Asset $asset, int $fiscalYear, ?int $period, array $months): CalendarDate
    {
        if ($period !== null) {
            $year = $this->fiscalYears->byYear($fiscalYear);

            if ($year !== null) {
                return $year->period($period)->end;
            }
        }

        $year = $this->fiscalYears->byYear($fiscalYear);

        return $year->end ?? $asset->planMonthDate($months[count($months) - 1]);
    }

    /** @param list<array<string, mixed>> $lines */
    /**
     * Dimensions the asset carries, in the shape a posting line expects (IMPL-023). Every machine
     * entry about an asset gets them on every line: the whole event belongs to that cost centre,
     * and a line without them would be refused wherever the pack makes a dimension mandatory —
     * which is precisely the case that used to make depreciation impossible to run.
     *
     * @return list<array{type: string, code: string}>
     */
    private static function parseDimensions(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $parsed = [];
        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }
            $type = $item['type'] ?? null;
            $code = $item['code'] ?? null;
            if (is_string($type) && is_string($code)) {
                $parsed[] = ['type' => $type, 'code' => $code];
            }
        }

        return $parsed;
    }

    /**
     * @param  list<array<string, mixed>> $lines
     * @return list<array<string, mixed>>
     */
    private function withDimensions(Asset $asset, array $lines): array
    {
        if ($asset->dimensions === []) {
            return $lines;
        }

        return array_map(
            static fn (array $line): array => $line + ['dimensions' => $asset->dimensions],
            $lines,
        );
    }

    /** @param list<array<string, mixed>> $lines */
    private function postMachineEntry(CalendarDate $date, Uuid $voucherId, string $text, array $lines): Uuid
    {
        $result = $this->ledger->post([
            'entryDate' => $date->iso,
            'voucherId' => $voucherId->value,
            'text' => $text,
            'lines' => $lines,
        ]);

        // Machine-generated entry: finalize immediately (machine entries are not hand-correctable).
        $this->ledger->finalize(['entryId' => $result->entry->id->value]);

        return $result->entry->id;
    }

    private function depreciationVoucher(Asset $asset, int $fiscalYear, ?int $period): Uuid
    {
        $voucher = new Voucher(
            $this->ids->next(),
            sprintf('AFA-%d%s-%s', $fiscalYear, $period === null ? '' : sprintf('-%02d', $period), substr($asset->id->value, -6)),
            CalendarDate::of(sprintf('%04d-12-31', $fiscalYear)),
            kind: 'internal',
        );
        $this->vouchers->add($voucher);

        return $voucher->id;
    }

    private function resolveRoute(string $choice, Money $cost, CalendarDate $acquiredOn): AssetRoute
    {
        if ($choice !== 'auto') {
            return AssetRoute::tryFrom($choice) ?? AssetRoute::Capitalize;
        }

        $threshold = $this->applicableThreshold($acquiredOn);
        if ($threshold === null) {
            return AssetRoute::Capitalize;
        }

        if ($cost->compareTo(Money::of($threshold['immediateMax'], $this->baseCurrency)) <= 0) {
            return AssetRoute::ImmediateExpense;
        }

        if (
            $threshold['poolMin'] !== null
            && $threshold['poolMax'] !== null
            && $cost->compareTo(Money::of($threshold['poolMin'], $this->baseCurrency)) >= 0
            && $cost->compareTo(Money::of($threshold['poolMax'], $this->baseCurrency)) <= 0
        ) {
            return AssetRoute::Pool;
        }

        return AssetRoute::Capitalize;
    }

    /**
     * The threshold row in force on the acquisition date — the first whose validity window contains it.
     *
     * @return array{validFrom: string, validTo: ?string, immediateMax: string, poolMin: ?string, poolMax: ?string, poolYears: ?int, poolReducedOnDisposal: ?bool, poolProRataInFirstYear: ?bool}|null
     */
    private function applicableThreshold(CalendarDate $acquiredOn): ?array
    {
        foreach ($this->thresholds() as $threshold) {
            $validFrom = CalendarDate::of($threshold['validFrom']);
            $validTo = $threshold['validTo'] === null ? null : CalendarDate::of($threshold['validTo']);

            if ($acquiredOn->isBefore($validFrom) || ($validTo !== null && $acquiredOn->isAfter($validTo))) {
                continue;
            }

            return $threshold;
        }

        return null;
    }

    /**
     * How long a pooled asset is written off. Refused rather than defaulted: a pack that opens a pool
     * range without saying over how long is incomplete, and picking a number here would put a statute
     * back into the core — the exact thing SPEC-004 is about. The schema requires the field alongside
     * `poolMax`, so this fires only for hand-fed rule data that never went through a pack.
     */
    private function poolYears(CalendarDate $acquiredOn): int
    {
        $threshold = $this->applicableThreshold($acquiredOn);

        if ($threshold === null || $threshold['poolYears'] === null) {
            throw new DomainError(
                'E_PACK_INCOHERENT',
                'gwgThresholds: a pool range (poolMin/poolMax) without poolYears — the pack must say over how many years the pool is written off',
                ['field' => 'poolYears', 'acquiredOn' => $acquiredOn->iso],
            );
        }

        return $threshold['poolYears'];
    }

    /**
     * @return list<array{validFrom: string, validTo: ?string, immediateMax: string, poolMin: ?string, poolMax: ?string, poolYears: ?int, poolReducedOnDisposal: ?bool, poolProRataInFirstYear: ?bool}>
     */
    private function thresholds(): array
    {
        $thresholds = [];

        foreach (is_array($this->ruleModule['gwgThresholds'] ?? null) ? $this->ruleModule['gwgThresholds'] : [] as $raw) {
            if (!is_array($raw) || !is_string($raw['validFrom'] ?? null) || !is_string($raw['immediateMax'] ?? null)) {
                continue;
            }

            $thresholds[] = [
                'validFrom' => $raw['validFrom'],
                'validTo' => is_string($raw['validTo'] ?? null) ? $raw['validTo'] : null,
                'immediateMax' => $raw['immediateMax'],
                'poolMin' => is_string($raw['poolMin'] ?? null) ? $raw['poolMin'] : null,
                'poolMax' => is_string($raw['poolMax'] ?? null) ? $raw['poolMax'] : null,
                'poolYears' => is_int($raw['poolYears'] ?? null) && $raw['poolYears'] >= 1 ? $raw['poolYears'] : null,
                'poolReducedOnDisposal' => is_bool($raw['poolReducedOnDisposal'] ?? null)
                    ? $raw['poolReducedOnDisposal']
                    : null,
                'poolProRataInFirstYear' => is_bool($raw['poolProRataInFirstYear'] ?? null)
                    ? $raw['poolProRataInFirstYear']
                    : null,
            ];
        }

        return $thresholds;
    }

    /**
     * Which yearly bucket a plan month belongs to.
     *
     * The fiscal year that contains the month, when one is set up — NOT its calendar year. Where
     * the two differ the old shortcut came apart badly: for a fiscal year 07/2026–06/2027 (labelled
     * by its end year) an asset acquired in September 2026 had its September-to-December months
     * filed under "2026", a label no fiscal year carries, so no run ever booked them, while months
     * belonging to the following fiscal year were pulled into this one. Which months belong to a
     * fiscal year is what a fiscal year is; reading it off the calendar was simply wrong.
     *
     * Falls back to the calendar year for a month beyond every fiscal year that has been set up.
     * That is not a second opinion about the boundary — it keeps the weighting COMPLETE. The yearly
     * amount comes from allocating the cost across all buckets by month count, so dropping the
     * months that reach past the last configured year would push their share into the years that
     * remain and write the asset off too fast. With calendar-year fiscal years the fallback is
     * identical to the answer above; with deviating ones it is the old behaviour, and it corrects
     * itself as soon as the year is created. The honest limit: set up the fiscal years an asset
     * runs through.
     */
    private function planMonthYear(Asset $asset, int $planMonth): int
    {
        $date = $asset->planMonthDate($planMonth);
        $year = $this->fiscalYears->forDate($date);

        return $year === null ? $date->year() : $year->year;
    }

    /**
     * The depreciation method the caller asked for. Straight line when absent — the method every
     * jurisdiction allows and the only one this core knew until 2026-08-23.
     */
    /**
     * Expected total output. A whole number, at least one — JSON has no int/float split, so 100000.0
     * is the same value as 100000, but 100000.5 is a caller's mistake and not half a kilometre.
     */
    private static function parseTotalUnits(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            $units = $value;
        } elseif (is_float($value) && $value === floor($value)) {
            $units = (int) $value;
        } else {
            throw new DomainError('E_INPUT_INVALID', 'acquireAsset: "totalUnits" must be a whole number', [
                'totalUnits' => DomainError::rejectedValue($value),
            ]);
        }

        if ($units < 1) {
            throw new DomainError('E_INPUT_INVALID', 'acquireAsset: "totalUnits" must be at least 1', [
                'totalUnits' => $units,
            ]);
        }

        return $units;
    }

    private static function parseMethod(mixed $value): string
    {
        if ($value === null) {
            return 'straight_line';
        }

        if ($value !== 'straight_line' && $value !== 'declining_balance' && $value !== 'units_of_production') {
            throw new DomainError(
                'E_INPUT_INVALID',
                'acquireAsset: "depreciationMethod" must be "straight_line", "declining_balance" or "units_of_production"',
                ['depreciationMethod' => DomainError::rejectedValue($value)],
            );
        }

        return $value;
    }

    /**
     * A declining-balance plan, with the switch to straight line built in.
     *
     * Each year takes a fixed percentage of what is LEFT, so the amounts fall and never quite reach
     * zero on their own — which is why every declining-balance regime pairs the method with a switch
     * to straight line over the remaining life, and why the final year simply takes the remainder.
     * Without that last step an asset would keep a residue forever.
     *
     * The switch is taken automatically at the first year where straight line over the remaining
     * life yields more. It is a permission rather than a duty, but no one entitled to it declines
     * it — an option nobody ever sets differently is better expressed as the behaviour.
     *
     * Rate, factor and the window come from the pack: the mechanism is shared (Germany applies it
     * both to movables and, at a different rate, to new residential buildings), the numbers are not.
     * Both the percentage and the remaining-life figure go through `allocate`, so the cents fall
     * where the rest of the core puts them.
     *
     * @return list<Money>
     */
    private function decliningBalanceSchedule(Money $cost, int $usefulLifeMonths, CalendarDate $acquiredOn, string $assetClass): array
    {
        $rule = $this->decliningBalanceRule($acquiredOn, $assetClass);
        $years = intdiv($usefulLifeMonths + 11, 12);

        // min(factor x straight-line rate, cap) — expressed on the cost, not per year, because the
        // rate is a property of the asset's life and does not change as the balance falls.
        $straightLineRate = 100 / $years;
        $rate = min($rule['factor'] * $straightLineRate, (float) $rule['maxRate']);
        $ratePermille = (string) round($rate, 4);

        $schedule = [];
        $remaining = $cost;

        for ($year = 1; $year <= $years; $year++) {
            $remainingYears = $years - $year + 1;

            if ($year === $years) {
                $amount = $remaining;
            } else {
                $declining = $remaining->allocate($ratePermille, (string) round(100 - $rate, 4))[0];
                $straightLine = $remaining->allocate(...array_fill(0, $remainingYears, 1))[0];
                $amount = $declining->compareTo($straightLine) >= 0 ? $declining : $straightLine;
            }

            $remaining = $remaining->subtract($amount);

            foreach ($amount->allocateEvenly(12) as $monthAmount) {
                $schedule[] = $monthAmount;
            }
        }

        return array_slice($schedule, 0, $usefulLifeMonths);
    }

    /**
     * The declining-balance rule in force on the acquisition date, for this kind of asset. Refused
     * rather than defaulted, like every other pack question here: a core that invents a rate has one
     * jurisdiction's tax policy in the substrate, and these rates are the most short-lived numbers in
     * the whole pack — Germany's current one applies to a window of two and a half years.
     *
     * Two entries can be in force at once, because a jurisdiction may run one declining-balance
     * regime for movables and another for buildings over overlapping windows — Germany does exactly
     * that. So an entry may name the asset classes it covers, and **a class-specific entry wins over
     * a general one no matter which comes first in the file.** Order-independence is the point: a
     * rule that changed meaning when someone appended a line above it would be a trap, and the
     * general entry is the one a pack author is most likely to add later.
     *
     * An entry without `assetClasses` covers every class, which is what a pack written before this
     * distinction existed says — those keep computing what they always did.
     *
     * @return array{factor: int, maxRate: string}
     */
    private function decliningBalanceRule(CalendarDate $acquiredOn, string $assetClass): array
    {
        $general = null;

        foreach (is_array($this->ruleModule['decliningBalance'] ?? null) ? $this->ruleModule['decliningBalance'] : [] as $raw) {
            if (!is_array($raw) || !is_string($raw['validFrom'] ?? null)) {
                continue;
            }

            $validFrom = CalendarDate::of($raw['validFrom']);
            $validTo = is_string($raw['validTo'] ?? null) ? CalendarDate::of($raw['validTo']) : null;

            if ($acquiredOn->isBefore($validFrom) || ($validTo !== null && $acquiredOn->isAfter($validTo))) {
                continue;
            }

            $factor = is_int($raw['factor'] ?? null) ? $raw['factor'] : null;
            $maxRate = is_string($raw['maxRate'] ?? null) ? $raw['maxRate'] : null;

            if ($factor === null || $factor < 1 || $maxRate === null) {
                continue;
            }

            $classes = is_array($raw['assetClasses'] ?? null) ? $raw['assetClasses'] : null;

            if ($classes === null) {
                $general ??= ['factor' => $factor, 'maxRate' => $maxRate];
                continue;
            }

            if (in_array($assetClass, $classes, true)) {
                return ['factor' => $factor, 'maxRate' => $maxRate];
            }
        }

        if ($general !== null) {
            return $general;
        }

        throw new DomainError(
            'E_PACK_INCOHERENT',
            sprintf(
                'declining-balance depreciation was asked for, but the pack declares no rule in force on %s for asset class "%s"',
                $acquiredOn->iso,
                $assetClass,
            ),
            ['field' => 'decliningBalance', 'acquiredOn' => $acquiredOn->iso, 'assetClass' => $assetClass],
        );
    }

    /**
     * A useful life given per acquisition: a whole number of months, at least one. JSON has no
     * int/float split, so 60.0 is the same value as 60 — but 60.4 is a caller's mistake and not a
     * number to round into shape.
     */
    private static function parseUsefulLifeMonths(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        $months = is_float($value) && $value === floor($value) ? (int) $value : $value;

        if (!is_int($months) || $months < 1) {
            throw new DomainError(
                'E_INPUT_INVALID',
                'acquireAsset: "usefulLifeMonths" must be a whole number of months, at least 1',
                ['usefulLifeMonths' => DomainError::rejectedValue($value)],
            );
        }

        return $months;
    }

    private function usefulLifeMonths(string $assetClass): int
    {
        foreach (is_array($this->ruleModule['usefulLife'] ?? null) ? $this->ruleModule['usefulLife'] : [] as $raw) {
            if (is_array($raw) && ($raw['assetClass'] ?? null) === $assetClass && is_int($raw['months'] ?? null)) {
                return $raw['months'];
            }
        }

        throw new DomainError('E_ASSET_UNKNOWN', sprintf(
            'No useful life for asset class "%s" in the rule module (see FINDINGS-CLOSED.md)',
            $assetClass,
        ));
    }

    private function counterAccount(): string
    {
        return $this->assetAccount('acquisitionCounterAccount');
    }

    private function depreciationExpenseAccount(): string
    {
        return $this->assetAccount('depreciationExpenseAccount');
    }

    private function gwgExpenseAccount(): string
    {
        return $this->assetAccount('gwgExpenseAccount');
    }

    /**
     * Depreciation owed up to the disposal, booked before the write-off (IMPL-022).
     *
     * Without this the disposal wrote off whatever carrying amount happened to be booked, and the
     * asset's last months of depreciation never happened at all: runDepreciation skips disposed
     * assets, so nobody would book them afterwards either. The expense landed in the disposal
     * account as an inflated loss instead of in depreciation — the total hit the income statement
     * correctly, the split did not, and the depreciation figure the fixed-asset schedule reports
     * was short.
     *
     * Which months are owed follows the schedule's own due-date convention — a plan month falls
     * due on its last day, exactly as monthTarget reads it for the regular run. No new rule is
     * invented here, and deliberately so: whether the month an asset leaves in counts as a whole
     * month is a *jurisdiction's* answer, so it belongs in a pack, not in this code. Consequence
     * today: an asset disposed mid-month gets no depreciation for that month. Recorded as a
     * follow-up.
     */
    private function catchUpDepreciation(Asset $asset, CalendarDate $disposedOn, Uuid $voucherId): void
    {
        $due = [];
        $life = count($asset->monthlySchedule);
        for ($planMonth = 1; $planMonth <= $life; $planMonth++) {
            if ($asset->planMonthDate($planMonth)->isAfter($disposedOn)) {
                break;
            }
            if (!$asset->isMonthBooked($planMonth)) {
                $due[] = $planMonth;
            }
        }
        if ($due === []) {
            return;
        }

        $amount = Money::zero($this->baseCurrency);
        foreach ($due as $planMonth) {
            $amount = $amount->add($asset->monthlySchedule[$planMonth - 1]);
        }
        if ($amount->isZero()) {
            return;
        }

        $entry = $this->postMachineEntry(
            $disposedOn,
            $voucherId,
            sprintf('Depreciation up to disposal %s', $asset->name),
            $this->withDimensions($asset, [
                [
                    'account' => $this->depreciationExpenseAccount(),
                    'side' => 'debit',
                    'money' => $amount->jsonSerialize(),
                ],
                [
                    'account' => $asset->assetAccount->value,
                    'side' => 'credit',
                    'money' => $amount->jsonSerialize(),
                ],
            ]),
        );

        $amounts = $this->monthAmounts($asset, $due, $amount);
        foreach ($due as $index => $planMonth) {
            $asset->recordDepreciation($planMonth, $disposedOn, $amounts[$index], $entry);
        }
        $this->assets->save($asset);
    }

    /**
     * Whether a disposal takes the item out of the pool. Same reasoning as poolYears, and the same
     * refusal: this is a jurisdiction's answer, not a property of pooling. Germany does not reduce
     * the pool when an item leaves (the yearly fraction runs to the end of the term regardless);
     * the UK and Australia take disposals out of their pools. Deciding it here would have put a
     * statute back into the core — which is exactly what IMPL-019 accidentally did before this.
     */
    private function poolReducedOnDisposal(CalendarDate $acquiredOn): bool
    {
        $threshold = $this->applicableThreshold($acquiredOn);
        if ($threshold === null || !is_bool($threshold['poolReducedOnDisposal'] ?? null)) {
            throw new DomainError(
                'E_PACK_INCOHERENT',
                'gwgThresholds: a pool range (poolMin/poolMax) without poolReducedOnDisposal — the pack must say whether a disposal reduces the pool',
                ['field' => 'poolReducedOnDisposal', 'acquiredOn' => $acquiredOn->iso],
            );
        }

        return $threshold['poolReducedOnDisposal'];
    }

    /**
     * Does the pool's first year get shortened by the acquisition month?
     *
     * Germany says no: the pool is dissolved in the fiscal year it is formed and the following
     * ones by equal fractions, so an asset bought in November still carries a full fraction in
     * that year, and the term ends after `poolYears` fiscal years. Treating a pool like ordinary
     * linear depreciation — pro rata from the month of acquisition — understated the first year
     * and invented a last one.
     *
     * Other pool regimes answer this differently, which is why the pack has to say it rather than
     * the core assuming it — the same reason `poolYears` (SPEC-004) and `poolReducedOnDisposal`
     * (IMPL-019) are pack data. The schema requires the field alongside `poolMax`, so this fires
     * only for hand-fed rule data that never went through a pack.
     */
    private function poolProRataInFirstYear(CalendarDate $acquiredOn): bool
    {
        $threshold = $this->applicableThreshold($acquiredOn);
        if ($threshold === null || !is_bool($threshold['poolProRataInFirstYear'] ?? null)) {
            throw new DomainError(
                'E_PACK_INCOHERENT',
                'gwgThresholds: a pool range (poolMin/poolMax) without poolProRataInFirstYear — the pack must say whether the first year is shortened by the acquisition month',
                ['field' => 'poolProRataInFirstYear', 'acquiredOn' => $acquiredOn->iso],
            );
        }

        return $threshold['poolProRataInFirstYear'];
    }

    /**
     * Where the depreciation plan starts. Capitalised assets start in the month of acquisition
     * (pro rata temporis). A pooled asset whose pack dissolves the pool in whole fiscal-year
     * fractions starts at the beginning of the fiscal year it was acquired in — that, and nothing
     * else, is what makes the first year full and the term end after `poolYears` years.
     */
    private function planStartFor(AssetRoute $route, CalendarDate $acquiredOn): ?CalendarDate
    {
        if ($route !== AssetRoute::Pool) {
            return null;
        }

        $year = $this->fiscalYears->forDate($acquiredOn);

        // Acquired on the first day of the fiscal year? Then both answers produce the same plan,
        // and the pack is not asked. That is deliberate and mirrors `poolReducedOnDisposal`, which
        // is only demanded when something is actually disposed: a pack owes an answer where the
        // answer changes the books, not everywhere. It is also what keeps rule data written before
        // this field existed working for the case where it cannot matter — the guarantee that a
        // *pack* carries the field is the schema's job, not this method's.
        if ($year !== null && $acquiredOn->iso === $year->start->iso) {
            return null;
        }

        if ($this->poolProRataInFirstYear($acquiredOn)) {
            return null;
        }

        if ($year === null) {
            throw new DomainError(
                'E_PERIOD_UNKNOWN',
                sprintf(
                    'the pool for an asset acquired on %s is dissolved in whole fiscal-year fractions, but no fiscal year contains that date',
                    $acquiredOn->iso,
                ),
                ['acquiredOn' => $acquiredOn->iso],
            );
        }

        return $year->start;
    }

    /** A pooled asset that its pack keeps in the pool after disposal — the write-off does not apply. */
    private function staysInPool(Asset $asset): bool
    {
        return $asset->route === AssetRoute::Pool && !$this->poolReducedOnDisposal($asset->acquiredOn);
    }

    private function disposalProceedsAccount(): string
    {
        return $this->assetAccount('disposalProceedsAccount');
    }

    private function disposalLossAccount(): string
    {
        return $this->assetAccount('disposalLossAccount');
    }

    /**
     * The disposal entry (F-AST-004). Two things leave the books at once: the asset's carrying
     * amount, and the difference between that and what the sale brought in.
     *
     * This core depreciates *net* — runDepreciation credits the asset account directly, there is
     * no accumulated-depreciation account — so the write-off is a single credit of the carrying
     * amount against that same account, not the gross form with an offsetting contra account.
     *
     * The difference is a gain (proceeds above book value) or a loss (below, including a scrapping
     * with no proceeds at all), and it goes to the account the pack names for it. Before this,
     * dispose booked only `bank → proceedsAccount`: the asset stayed in the balance sheet at its
     * carrying amount and the proceeds counted as income in full, overstating profit by exactly
     * that amount.
     *
     * The `proceedsAccount` input parameter still wins over the pack's account — it is documented
     * and fixtures pass it — but is no longer required for the entry to happen.
     *
     * @return list<array<string, mixed>>
     */
    private function disposalLines(
        Asset $asset,
        Money $carrying,
        ?Money $proceeds,
        string $bankAccount,
        ?string $proceedsAccountOverride,
    ): array {
        $lines = [];
        $received = $proceeds ?? Money::zero($this->baseCurrency);

        if ($received->isPositive()) {
            $lines[] = ['account' => $bankAccount, 'side' => 'debit', 'money' => $received->jsonSerialize()];
        }
        if ($carrying->isPositive()) {
            $lines[] = [
                'account' => $asset->assetAccount->value,
                'side' => 'credit',
                'money' => $carrying->jsonSerialize(),
            ];
        }

        $difference = $received->subtract($carrying);
        if ($difference->isPositive()) {
            $lines[] = [
                'account' => $proceedsAccountOverride ?? $this->disposalProceedsAccount(),
                'side' => 'credit',
                'money' => $difference->jsonSerialize(),
            ];
        } elseif (!$difference->isZero()) {
            $lines[] = [
                'account' => $this->disposalLossAccount(),
                'side' => 'debit',
                'money' => $difference->negate()->jsonSerialize(),
            ];
        }

        // Nothing moved: a fully depreciated asset scrapped without proceeds. Booking a zero entry
        // would put an empty voucher in the journal for no reason.
        return count($lines) > 1 ? $lines : [];
    }

    /**
     * v0.5/SPEC-004: asset accounts come from the rule-module block
     * `assetAccounts` — no more name heuristic.
     */
    private function assetAccount(string $key): string
    {
        $block = is_array($this->ruleModule['assetAccounts'] ?? null) ? $this->ruleModule['assetAccounts'] : [];
        $value = $block[$key] ?? null;

        if (is_string($value) && $value !== '') {
            return $value;
        }

        throw new DomainError('E_ACCOUNT_UNKNOWN', sprintf(
            'assetAccounts.%s is not set in the rule module',
            $key,
        ), ['key' => $key]);
    }

    private function parseMoney(mixed $raw): Money
    {
        $amount = is_array($raw) && is_string($raw['amount'] ?? null) ? $raw['amount'] : null;

        if ($amount === null) {
            throw new InvalidValue('amount missing');
        }

        return Money::of($amount, $this->baseCurrency);
    }
}
