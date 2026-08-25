<?php

declare(strict_types=1);

namespace Summae\Core\Policies\Expansion\Costing;

use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Summae\Core\DomainError;
use Summae\Core\Composition\TenantConfigStore;
use Summae\Core\Ledger\AuditWriter;
use Summae\Core\Port\AccountRepository;
use Summae\Core\Port\CostingRunRepository;
use Summae\Core\Port\JournalRepository;
use Summae\Core\Substrate\AccountNumber;
use Summae\Core\Substrate\AccountType;
use Summae\Core\Substrate\Currency;
use Summae\Core\Substrate\Exception\InvalidValue;
use Summae\Core\Substrate\IdGenerator;
use Summae\Core\Substrate\Money;
use Summae\Core\Substrate\PeriodRef;
use Summae\Core\Substrate\Rational;
use Summae\Core\Substrate\Side;
use Summae\Core\Substrate\Uuid;

/**
 * Cost accounting (costing-modell.md): own accounting circle — the
 * financial-accounting journal stays untouched. Primary-cost intake via the
 * costCenter dimension, allocation by step ladder (acyclic,
 * E_COSTING_CYCLE), distribution by Money::allocate (largest remainder,
 * tie -> first receiver in stable order).
 */
final class CostingService
{
    /**
     * The two ways this core allocates internal services. A method it cannot perform is refused
     * rather than approximated: until now `method` was read, echoed back in the answer and then
     * ignored, so asking for the simultaneous method returned step-ladder numbers under the name of
     * a different procedure — the worst shape a defect can take, because the answer asserts it did
     * what was asked.
     */
    private const METHODS = ['step_ladder', 'simultaneous'];

    /** @var list<array{sender: string, receivers: list<array{code: string, share: string}>}> */
    private array $schemeSteps = [];

    /**
     * The pack's answer to "what may enter production cost". Data, never code — which components a
     * jurisdiction requires, permits or forbids is the part that differs, and the summation is not.
     *
     * @var array<string, mixed>
     */
    private array $ruleModule = [];

    /**
     * Production-cost component definitions and the tenant's election among the optional ones,
     * frozen into each run for the same reason the rates are.
     *
     * @var list<array{id: string, treatment: string, included: bool, accounts: list<string>, costCenters: list<string>}>|null
     */
    private ?array $productionCostConfig = null;

    /**
     * Overhead rate definitions, part of the same tenant-level configuration as the scheme.
     *
     * They sit on `setAllocationScheme` rather than on an operation of their own because a rate is
     * computed FROM an allocation and frozen INTO the run: a second operation would need its own
     * freezing rule against the same run, and two rules for one moment is how they drift apart.
     *
     * @var list<array{costCenter: string, label: string, accounts: list<string>, costCenters: list<string>}>
     */
    private array $rateDefinitions = [];

    /** @var array<string, mixed>|null a stored scheme waiting for its first use — see restoreAllocationScheme */
    private ?array $pendingScheme = null;

    /**
     * The scheme as it was given, kept for `tenantConfiguration` to report.
     *
     * The raw input rather than the parsed fields, for the same reason the store keeps the raw
     * input: it is exactly what `setAllocationScheme` accepts, so what comes out can be put back
     * in and no second serializer can drift from the first one.
     *
     * @var array<string, mixed>|null
     */
    private ?array $schemeData = null;

    private string $method = 'step_ladder';

    public function __construct(
        private readonly Currency $baseCurrency,
        private readonly AccountRepository $accounts,
        private readonly JournalRepository $journal,
        /**
         * Where the runs live (F-KLR-001/004).
         *
         * They used to live in two private arrays in this class — the runs themselves and a
         * per-period version counter — which meant a released run was gone with the process that
         * produced it and the version restarted at 1 after every restart. The requirements had said
         * otherwise all along: runs are versioned per period, and the BAB and the rates are a
         * projection *of a released run*. A run no later process can read satisfies neither; it
         * satisfies them inside one process, which is not what a repository port is for.
         */
        private readonly CostingRunRepository $runs,
        private readonly IdGenerator $ids,
        // The allocation scheme is a tenant-level singleton — see TaxService for why the
        // audit record names the tenant as its object (F-CORE-014 "Profile").
        private readonly ?Uuid $tenantId = null,
        private readonly ?AuditWriter $audit = null,
        /** Where the scheme is kept, so it outlives this object (SPEC-015). */
        private readonly ?TenantConfigStore $configStore = null,
    ) {
    }

    /** @param array<string, mixed> $ruleModule the resolved pack bundle (`productionCost` is read here) */
    public function setRuleModule(array $ruleModule): void
    {
        $this->ruleModule = $ruleModule;
    }

    /**
     * The step ladder requires acyclicity (E_COSTING_CYCLE); the simultaneous-equation method is the
     * cycle-capable one and solves the whole scheme at once (SimultaneousAllocation).
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function setAllocationScheme(array $input): array
    {
        $this->applyPendingScheme();
        $previousStepCount = count($this->schemeSteps);
        $previousRateCount = count($this->rateDefinitions);
        $result = $this->applyAllocationScheme($input);

        if ($this->audit !== null && $this->tenantId !== null) {
            $this->audit->record($this->audit->actorOf($input), 'allocationScheme', $this->tenantId, 'changed', [
                'method' => ['from' => null, 'to' => $result['method']],
                'stepCount' => ['from' => $previousStepCount, 'to' => $result['stepCount']],
                'rateCount' => ['from' => $previousRateCount, 'to' => $result['rateCount']],
            ]);
        }
        // The raw input, not the parsed fields: it is exactly what this method accepts, so reloading
        // it on the next open runs the same validation again rather than a second, drifting reader.
        $this->configStore?->rememberAllocationScheme($input);

        return $result;
    }

    /**
     * Hands back a stored scheme when a tenant is opened (SPEC-015).
     *
     * **Deferred on purpose.** A scheme can reference production-cost treatments, which only the
     * pack answers — and the pack arrives through `setRuleModule`, *after* the factory has built the
     * tenant. Applying it here would make opening the books fail on a scheme that was perfectly
     * valid when it was set, which is the wrong moment to find out and the wrong thing to block:
     * reading a journal does not need an allocation scheme.
     *
     * So it is applied on first use — `setAllocationScheme` and `run` are the only entry points that
     * read it. A stored scheme that the *current* pack no longer accepts then fails when somebody
     * runs a costing, with the error the operation itself would have given.
     *
     * @param array<string, mixed> $input
     */
    public function restoreAllocationScheme(array $input): void
    {
        $this->pendingScheme = $input;
    }

    /** Applies what `restoreAllocationScheme` handed back, once, at the moment it is first needed. */
    private function applyPendingScheme(): void
    {
        if ($this->pendingScheme === null) {
            return;
        }

        $pending = $this->pendingScheme;
        $this->pendingScheme = null;
        $this->applyAllocationScheme($pending);
    }

    /**
     * The allocation scheme in force, as it was set — what `tenantConfiguration` reports, or null
     * when none was ever set.
     *
     * Reads the *pending* scheme first and deliberately does not apply it. A stored scheme may
     * name production-cost treatments only the current pack answers, so applying it is what
     * `restoreAllocationScheme` defers to first use — and a projection is the wrong place to find
     * out: reporting what a tenant is configured as must not fail on a scheme that was valid when
     * it was set. Reporting it unapplied is the honest answer, because unapplied is what it is.
     *
     * @return array<string, mixed>|null
     */
    public function allocationScheme(): ?array
    {
        return $this->pendingScheme ?? $this->schemeData;
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{valid: bool, method: string, stepCount: int, rateCount: int, productionCostComponents: int}
     */
    private function applyAllocationScheme(array $input): array
    {
        $method = is_string($input['method'] ?? null) ? $input['method'] : 'step_ladder';

        if (!in_array($method, self::METHODS, true)) {
            throw new DomainError('E_INPUT_INVALID', sprintf(
                'setAllocationScheme: unknown allocation method "%s" — this core allocates by %s',
                $method,
                implode(' or ', self::METHODS),
            ), ['method' => DomainError::rejectedValue($method)]);
        }

        /** @var list<array{sender: string, receivers: list<array{code: string, share: string}>}> $steps */
        $steps = [];
        /** @var array<string, list<string>> $edges */
        $edges = [];

        foreach (is_array($input['steps'] ?? null) ? array_values($input['steps']) : [] as $rawStep) {
            if (!is_array($rawStep) || !is_string($rawStep['sender'] ?? null)) {
                throw new InvalidValue('allocation step requires sender');
            }

            $receivers = [];
            foreach (is_array($rawStep['receivers'] ?? null) ? array_values($rawStep['receivers']) : [] as $rawReceiver) {
                if (!is_array($rawReceiver) || !is_string($rawReceiver['code'] ?? null)) {
                    continue;
                }

                $receivers[] = [
                    'code' => $rawReceiver['code'],
                    'share' => is_string($rawReceiver['share'] ?? null) ? $rawReceiver['share'] : '1',
                ];
                $edges[$rawStep['sender']][] = $rawReceiver['code'];
            }

            $steps[] = ['sender' => $rawStep['sender'], 'receivers' => $receivers];
        }

        if ($method === 'step_ladder') {
            $this->assertAcyclic($edges);
        }

        $rates = [];
        foreach (is_array($input['rates'] ?? null) ? array_values($input['rates']) : [] as $rawRate) {
            if (!is_array($rawRate) || !is_string($rawRate['costCenter'] ?? null)) {
                throw new DomainError(
                    'E_INPUT_INVALID',
                    'setAllocationScheme: an overhead rate requires "costCenter"',
                    ['field' => 'rates'],
                );
            }

            $base = is_array($rawRate['base'] ?? null) ? $rawRate['base'] : [];

            $rates[] = [
                'costCenter' => $rawRate['costCenter'],
                'label' => is_string($rawRate['label'] ?? null) ? $rawRate['label'] : $rawRate['costCenter'],
                'accounts' => self::stringList($base['accounts'] ?? null),
                'costCenters' => self::stringList($base['costCenters'] ?? null),
            ];
        }

        $productionCost = null;
        if (is_array($input['productionCost'] ?? null)) {
            $productionCost = $this->resolveProductionCost($input['productionCost']);
        }

        $this->schemeSteps = $steps;
        $this->method = $method;
        $this->rateDefinitions = $rates;
        $this->productionCostConfig = $productionCost;
        $this->schemeData = $input;

        return [
            'valid' => true,
            'method' => $method,
            'stepCount' => count($steps),
            'rateCount' => count($rates),
            'productionCostComponents' => $productionCost === null ? 0 : count($productionCost),
        ];
    }

    /**
     * @param array<string, mixed> $input {fiscalYear, period}
     */
    public function run(array $input): CostingRun
    {
        $this->applyPendingScheme();
        $fiscalYear = is_int($input['fiscalYear'] ?? null) ? $input['fiscalYear'] : 0;
        $period = is_int($input['period'] ?? null) ? $input['period'] : 0;
        $periodRef = new PeriodRef($fiscalYear, $period);

        // Primary-cost intake: expense lines with costCenter dimension.
        $zero = Money::zero($this->baseCurrency);
        /** @var array<string, Money> $primary */
        $primary = [];
        /** @var array<string, Money> $accountTotals */
        $accountTotals = [];

        foreach ($this->journal->forFiscalYear($fiscalYear) as $entry) {
            if ($entry->periodRef->period !== $period) {
                continue;
            }

            foreach ($entry->lines() as $line) {
                $account = $this->accounts->byId($line->accountId);
                if ($account === null) {
                    continue;
                }

                // Direct costs — the denominator of an overhead rate — are booked WITHOUT a cost
                // centre: they belong to the product, not to a department. So they are collected per
                // account here, in the same pass, and not through the costCenter dimension.
                $number = (string) $account->number;
                $signedLine = $line->side === Side::Debit ? $line->money : $line->money->negate();
                $accountTotals[$number] = ($accountTotals[$number] ?? $zero)->add($signedLine);

                if ($account->type !== AccountType::Expense) {
                    continue;
                }

                foreach ($line->dimensions as $dimension) {
                    if ($dimension->type !== 'costCenter') {
                        continue;
                    }

                    $signed = $line->side === Side::Debit ? $line->money : $line->money->negate();
                    $primary[$dimension->code] = ($primary[$dimension->code] ?? $zero)->add($signed);
                }
            }
        }

        // Allocation: distribute, never create — by either method.
        $after = $this->method === 'simultaneous'
            ? $this->allocateSimultaneously($primary)
            : $this->allocateByStepLadder($primary);

        $grandTotal = $zero;
        foreach ($after as $total) {
            $grandTotal = $grandTotal->add($total);
        }

        $rates = $this->computeRates($after, $accountTotals);
        $productionCost = $this->computeProductionCost($after, $accountTotals);

        // The next version comes from what is stored, not from a counter in this object: a counter
        // starts at zero in every new process, so the second run of a period would have claimed to
        // be its first.
        $version = $this->nextVersionFor($fiscalYear, $period);

        $run = new CostingRun(
            $this->ids->next(),
            $periodRef,
            $version,
            $primary,
            $after,
            $grandTotal,
            $this->method,
            $rates['rates'],
            $rates['warnings'],
            $productionCost,
        );
        $this->runs->add($run);
        if ($this->audit !== null) {
            $this->audit->record($this->audit->actorOf($input), 'costingRun', $run->id, 'created', [
                'period' => ['from' => null, 'to' => $periodRef->fiscalYear . '/' . $periodRef->period],
                'method' => ['from' => null, 'to' => $this->method],
                'version' => ['from' => null, 'to' => $version],
                'status' => ['from' => null, 'to' => $run->status()],
            ]);
        }

        return $run;
    }

    /** @param array<string, mixed> $input */
    public function release(array $input): CostingRun
    {
        $run = $this->requireRun($input['runId'] ?? null);
        $before = $run->status();
        $run->release();
        $this->runs->save($run);
        if ($this->audit !== null) {
            $this->audit->record($this->audit->actorOf($input), 'costingRun', $run->id, 'released', [
                'status' => ['from' => $before, 'to' => $run->status()],
            ]);
        }

        return $run;
    }

    /**
     * Cost allocation sheet: matrix totals of a run (costing-modell.md projections).
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public function costAllocationSheet(array $params): array
    {
        $run = $this->requireRun($params['runId'] ?? null);

        // The run already fixes fiscal year and period. Passing them alongside was accepted and
        // ignored, so a caller could ask for period 2, receive period 1's numbers, and have nothing
        // in the answer to contradict them. If they are given, they have to agree.
        $fiscalYear = $params['fiscalYear'] ?? null;
        if ($fiscalYear !== null && $fiscalYear !== $run->period->fiscalYear) {
            throw new DomainError(
                'E_INPUT_INVALID',
                sprintf('costAllocationSheet: the run belongs to fiscal year %d', $run->period->fiscalYear),
                ['fiscalYear' => DomainError::rejectedValue($fiscalYear)],
            );
        }

        $period = $params['period'] ?? null;
        if ($period !== null && $period !== $run->period->period) {
            throw new DomainError(
                'E_INPUT_INVALID',
                sprintf('costAllocationSheet: the run belongs to period %d', $run->period->period),
                ['period' => DomainError::rejectedValue($period)],
            );
        }

        return [
            'runId' => $run->id->value,
            'status' => $run->status(),
            'version' => $run->version,
            'method' => $run->method,
            'primary' => $this->serializeTotals($run->primary),
            'afterAllocation' => $this->serializeTotals($run->afterAllocation),
            'grandTotal' => $run->grandTotal->amountAsString(),
        ];
    }

    /**
     * Overhead rates of a run (F-KLR-004: "BAB *und Kalkulationssätze*").
     *
     * A rate answers the question the allocation sheet cannot: the sheet says what a cost centre
     * ended up carrying, a rate says how that attaches to a product. Numerator is the centre after
     * allocation, denominator is a base the scheme declares — direct-cost ACCOUNTS, other cost
     * CENTRES, or both. The classic four fall straight out of that: material and production overhead
     * over their direct costs, administration and sales overhead over cost of production, which is
     * "the direct-cost accounts plus the two centres" and needs no special case.
     *
     * Rounded to four decimals, half-up away from zero — a rate is a published figure, not money,
     * and four places is where the pack schema already puts a percentage.
     *
     * Note what this deliberately does NOT do: refuse a draft run. F-KLR-001 says evaluations read
     * released runs only, and the fixture `parameter-effect` reads a draft sheet — an append-only
     * contract that says otherwise. Bending the fixture would rewrite what the contract always said,
     * so the rule is followed as the contract has it and the contradiction is recorded
     * (SPEC-FINDINGS). `status` is in the answer, so nobody has to guess which they got.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public function overheadRates(array $params): array
    {
        $run = $this->requireRun($params['runId'] ?? null);

        return [
            'runId' => $run->id->value,
            'status' => $run->status(),
            'version' => $run->version,
            'method' => $run->method,
            'rates' => $run->rates,
            'warnings' => $run->rateWarnings,
        ];
    }

    /**
     * Production cost of the period — the figure inventory is carried at.
     *
     * This is the one piece of cost accounting with balance-sheet effect, so what may be counted into
     * it is law rather than preference. The split falls exactly along the socket/plug line the rest of
     * the core uses — **this method sums, the pack says what may enter.** One jurisdiction requires
     * material and production cost with their overhead and the production-related depreciation, leaves
     * general administration to the preparer, and forbids research and distribution; another treats
     * general administration as a period charge and so reaches a different inventory value from
     * identical books. None of that is written here, and none of it should be: which of the three
     * treatments a component gets is a row in the pack, not a branch in this method.
     *
     * Three rules, and each refuses rather than guesses:
     *
     * - a component the pack does not declare is `E_PACK_INCOHERENT` — an unknown component silently
     *   counted or silently dropped would move the balance sheet either way;
     * - electing a component the pack forbids is `E_INPUT_INVALID`, not a quiet exclusion, because
     *   the caller has said something about their books that is not allowed;
     * - asking for the figure without configuring the components is `E_INPUT_INVALID` rather than
     *   0.00, since a valuation nobody set up is not a valuation of zero.
     *
     * What this deliberately does NOT do is divide by a quantity. Production cost per unit needs
     * produced quantities, and the core carries no quantities at all — goods movements and production
     * orders are the embedding application's data. summae answers what the components add up to and
     * why; the division is arithmetic on top of that.
     *
     * @param array<string, Money> $after
     * @param array<string, Money> $accountTotals
     *
     * @return array{total: string, components: list<array{id: string, amount: string, treatment: string, included: bool}>}|null
     */
    private function computeProductionCost(array $after, array $accountTotals): ?array
    {
        if ($this->productionCostConfig === null) {
            return null;
        }

        $zero = Money::zero($this->baseCurrency);
        $total = $zero;
        $components = [];

        foreach ($this->productionCostConfig as $component) {
            $amount = $zero;

            foreach ($component['accounts'] as $number) {
                if ($this->accounts->byNumber(AccountNumber::of($number)) === null) {
                    throw new DomainError('E_ACCOUNT_UNKNOWN', sprintf(
                        'production-cost component "%s" names account %s, which does not exist',
                        $component['id'],
                        $number,
                    ), ['account' => $number]);
                }

                $amount = $amount->add($accountTotals[$number] ?? $zero);
            }

            foreach ($component['costCenters'] as $code) {
                $amount = $amount->add($after[$code] ?? $zero);
            }

            if ($component['included']) {
                $total = $total->add($amount);
            }

            // Excluded components stay in the answer with their amount and the reason. A valuation
            // that shows only what it counted cannot be checked against the law it claims to follow.
            $components[] = [
                'id' => $component['id'],
                'amount' => $amount->amountAsString(),
                'treatment' => $component['treatment'],
                'included' => $component['included'],
            ];
        }

        return ['total' => $total->amountAsString(), 'components' => $components];
    }

    /**
     * Applies the pack's treatment table to a production-cost configuration — at the moment the
     * configuration is set, not at the moment the run happens, so a caller learns about a refusal
     * where they can still do something about it.
     *
     * @param array<mixed> $raw
     *
     * @return list<array{id: string, treatment: string, included: bool, accounts: list<string>, costCenters: list<string>}>
     */
    private function resolveProductionCost(array $raw): array
    {
        $treatments = self::treatmentTable($this->ruleModule);
        $elected = self::stringList($raw['include'] ?? null);

        foreach ($elected as $componentId) {
            $treatment = $treatments[$componentId] ?? null;

            if ($treatment === null) {
                throw new DomainError('E_PACK_INCOHERENT', sprintf(
                    'production-cost component "%s" was elected, but the pack declares no treatment for it',
                    $componentId,
                ), ['component' => $componentId]);
            }

            if ($treatment === 'forbidden') {
                throw new DomainError('E_INPUT_INVALID', sprintf(
                    'production-cost component "%s" may not be capitalised under this pack',
                    $componentId,
                ), ['component' => $componentId]);
            }
        }

        $components = [];
        foreach (is_array($raw['components'] ?? null) ? array_values($raw['components']) : [] as $rawComponent) {
            if (!is_array($rawComponent) || !is_string($rawComponent['id'] ?? null)) {
                throw new DomainError(
                    'E_INPUT_INVALID',
                    'setAllocationScheme: a production-cost component requires "id"',
                    ['field' => 'productionCost.components'],
                );
            }

            $treatment = $treatments[$rawComponent['id']] ?? null;

            if ($treatment === null) {
                throw new DomainError('E_PACK_INCOHERENT', sprintf(
                    'production-cost component "%s" is not declared by the pack',
                    $rawComponent['id'],
                ), ['component' => $rawComponent['id']]);
            }

            $base = is_array($rawComponent['base'] ?? null) ? $rawComponent['base'] : [];

            $components[] = [
                'id' => $rawComponent['id'],
                'treatment' => $treatment,
                'included' => $treatment === 'mandatory'
                    || ($treatment === 'optional' && in_array($rawComponent['id'], $elected, true)),
                'accounts' => self::stringList($base['accounts'] ?? null),
                'costCenters' => self::stringList($base['costCenters'] ?? null),
            ];
        }

        return $components;
    }

    /**
     * @param array<string, mixed> $ruleModule
     *
     * @return array<string, string> component id -> mandatory|optional|forbidden
     */
    private static function treatmentTable(array $ruleModule): array
    {
        $module = is_array($ruleModule['productionCost'] ?? null) ? $ruleModule['productionCost'] : null;

        if ($module === null) {
            throw new DomainError(
                'E_PACK_INCOHERENT',
                'production cost was asked for, but the pack declares no production-cost treatments',
                ['field' => 'productionCost'],
            );
        }

        $table = [];
        foreach (is_array($module['treatments'] ?? null) ? $module['treatments'] : [] as $row) {
            if (!is_array($row) || !is_string($row['component'] ?? null) || !is_string($row['treatment'] ?? null)) {
                continue;
            }

            $table[$row['component']] = $row['treatment'];
        }

        return $table;
    }

    /**
     * Production cost of a run, component by component (F-KLR-004's balance-sheet neighbour).
     *
     * Every configured component appears, whether it was counted or not, with the pack's treatment
     * next to it — `mandatory`, `optional` or `forbidden` — and whether it went in. A valuation that
     * shows only its own total is unauditable; this one shows what it left out and on whose authority.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public function productionCost(array $params): array
    {
        $run = $this->requireRun($params['runId'] ?? null);

        if ($run->productionCost === null) {
            throw new DomainError('E_INPUT_INVALID', sprintf(
                'run %s has no production-cost components — declare them in setAllocationScheme before the run',
                $run->id->value,
            ), ['runId' => $run->id->value]);
        }

        return [
            'runId' => $run->id->value,
            'status' => $run->status(),
            'version' => $run->version,
            'total' => $run->productionCost['total'],
            'components' => $run->productionCost['components'],
        ];
    }

    /**
     * @param array<string, Money> $after
     * @param array<string, Money> $accountTotals
     *
     * @return array{rates: list<array{costCenter: string, label: string, overhead: string, base: string, rate: string|null}>, warnings: list<array{costCenter: string, reason: string}>}
     */
    private function computeRates(array $after, array $accountTotals): array
    {
        $zero = Money::zero($this->baseCurrency);
        $rates = [];
        $warnings = [];

        // Definition order, not alphabetical: an administration rate takes cost of production as its
        // base, so the order the caller wrote is the order that reads correctly. It is deterministic
        // for the same reason the steps are — it comes from the input.
        foreach ($this->rateDefinitions as $definition) {
            $overhead = $after[$definition['costCenter']] ?? $zero;
            $base = $zero;

            foreach ($definition['accounts'] as $number) {
                if ($this->accounts->byNumber(AccountNumber::of($number)) === null) {
                    throw new DomainError('E_ACCOUNT_UNKNOWN', sprintf(
                        'overhead rate for cost center "%s" names account %s, which does not exist',
                        $definition['costCenter'],
                        $number,
                    ), ['account' => $number]);
                }

                $base = $base->add($accountTotals[$number] ?? $zero);
            }

            foreach ($definition['costCenters'] as $code) {
                $base = $base->add($after[$code] ?? $zero);
            }

            $rate = null;
            if ($base->isZero()) {
                // A rate over an empty base is not zero and not infinite, it is undefined — and an
                // undefined number returned as 0.00 would be applied to products as if it meant
                // something. Named instead, in the same shape the cash-basis report uses for its gaps.
                $warnings[] = [
                    'costCenter' => $definition['costCenter'],
                    'reason' => 'the base is zero, so no rate can be computed',
                ];
            } else {
                $rate = self::formatRate(
                    Rational::fromDecimalString($overhead->amountAsString())
                        ->divide(Rational::fromDecimalString($base->amountAsString()))
                        ->multiply(Rational::of(100)),
                );
            }

            $rates[] = [
                'costCenter' => $definition['costCenter'],
                'label' => $definition['label'],
                'overhead' => $overhead->amountAsString(),
                'base' => $base->amountAsString(),
                'rate' => $rate,
            ];
        }

        return ['rates' => $rates, 'warnings' => $warnings];
    }

    /** A percentage with four decimals, commercial half-up (away from zero), like everything else here. */
    private static function formatRate(Rational $value): string
    {
        $scaled = $value->multiply(Rational::of(BigInteger::of(10)->power(4)));
        $negative = $scaled->isNegative();
        $magnitude = $negative ? $scaled->negate() : $scaled;
        $rounded = $magnitude->add(Rational::of(1, 2))->floorToBigInteger();

        return (string) BigDecimal::ofUnscaledValue($negative ? $rounded->negated() : $rounded, 4);
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        $result = [];
        foreach (is_array($value) ? array_values($value) : [] as $item) {
            if (is_string($item)) {
                $result[] = $item;
            }
        }

        return $result;
    }

    /**
     * One pass in step order. Cheap, and wrong the moment two centres serve each other — which is
     * why a cycle is refused here rather than resolved by picking an order.
     *
     * @param array<string, Money> $primary
     *
     * @return array<string, Money>
     */
    private function allocateByStepLadder(array $primary): array
    {
        $zero = Money::zero($this->baseCurrency);
        $after = $primary;

        foreach ($this->schemeSteps as $step) {
            $senderTotal = $after[$step['sender']] ?? $zero;

            if ($senderTotal->isZero() || $step['receivers'] === []) {
                continue;
            }

            $weights = array_map(static fn (array $receiver): string => $receiver['share'], $step['receivers']);
            $parts = $senderTotal->allocate(...$weights);

            foreach ($step['receivers'] as $index => $receiver) {
                $after[$receiver['code']] = ($after[$receiver['code']] ?? $zero)->add($parts[$index]);
            }

            $after[$step['sender']] = $zero;
        }

        return $after;
    }

    /**
     * All centres at once, solved exactly (SimultaneousAllocation) and only then turned back into
     * money.
     *
     * The order matters and is the reason this is not simply "solve and round": the solution is a
     * vector of exact fractions whose sum is the primary total to the last cent, and rounding each
     * one on its own would break that — a cent appears or vanishes, and the sheet no longer says
     * that allocation distributes rather than creates. So the fractions are floored and the
     * difference handed out by largest remainder, ties to the earlier cost centre, which is
     * `Money::allocate`'s rule applied to a vector instead of a single amount.
     *
     * @param array<string, Money> $primary
     *
     * @return array<string, Money>
     */
    private function allocateSimultaneously(array $primary): array
    {
        $codes = array_keys($primary);
        foreach ($this->schemeSteps as $step) {
            $codes[] = $step['sender'];
            foreach ($step['receivers'] as $receiver) {
                $codes[] = $receiver['code'];
            }
        }

        $codes = array_values(array_unique(array_map(strval(...), $codes)));
        usort($codes, static fn (string $a, string $b): int => strcmp($a, $b));

        if ($codes === []) {
            return [];
        }

        // Minor units throughout: the solver knows nothing about currencies, and an integer count of
        // cents is the one representation in which "the total is preserved" is checkable.
        $scale = $this->baseCurrency->scale;
        $toMinor = Rational::of(BigInteger::of(10)->power($scale));

        /** @var array<string, Rational> $primaryMinor */
        $primaryMinor = [];
        $totalMinor = BigInteger::zero();
        foreach ($primary as $code => $money) {
            $value = Rational::fromDecimalString($money->amountAsString())->multiply($toMinor);
            $primaryMinor[(string) $code] = $value;
            $totalMinor = $totalMinor->plus($value->floorToBigInteger());
        }

        $solved = SimultaneousAllocation::solve($codes, $primaryMinor, $this->schemeSteps);
        $senderSet = array_flip($solved['senders']);

        /** @var list<string> $keepers */
        $keepers = [];
        foreach ($codes as $code) {
            if (!isset($senderSet[$code])) {
                $keepers[] = $code;
            }
        }

        /** @var array<int, BigInteger> $floors */
        $floors = [];
        $assigned = BigInteger::zero();
        foreach ($keepers as $position => $code) {
            $floors[$position] = $solved['totals'][$code]->floorToBigInteger();
            $assigned = $assigned->plus($floors[$position]);
        }

        $leftover = $totalMinor->minus($assigned)->toInt();
        $order = range(0, count($keepers) - 1);
        usort($order, static function (int $a, int $b) use ($keepers, $solved): int {
            $byRemainder = $solved['totals'][$keepers[$b]]->fractionalPart()
                ->compareTo($solved['totals'][$keepers[$a]]->fractionalPart());

            return $byRemainder !== 0 ? $byRemainder : $a <=> $b;
        });

        for ($i = 0; $i < $leftover; $i++) {
            $floors[$order[$i]] = $floors[$order[$i]]->plus(1);
        }

        $zero = Money::zero($this->baseCurrency);
        /** @var array<string, Money> $after */
        $after = [];
        foreach ($codes as $code) {
            $after[$code] = $zero;
        }
        foreach ($keepers as $position => $code) {
            $after[$code] = Money::fromCalculation(
                BigDecimal::ofUnscaledValue($floors[$position], $scale),
                $this->baseCurrency,
            );
        }

        return $after;
    }

    /**
     * @param array<string, Money> $totals
     *
     * @return list<array{costCenter: string, total: string}>
     */
    private function serializeTotals(array $totals): array
    {
        $codes = array_map(strval(...), array_keys($totals));
        usort($codes, static fn (string $a, string $b): int => strcmp($a, $b));

        $rows = [];
        foreach ($codes as $code) {
            $rows[] = ['costCenter' => $code, 'total' => $totals[$code]->amountAsString()];
        }

        return $rows;
    }

    /**
     * The next version for a period: one more than the highest stored for it.
     *
     * Derived rather than counted, because a counter in this object starts at zero in every new
     * process — the second run of a period would have claimed to be its first, and F-KLR-001's
     * "versioned per period" would have held only until a restart.
     */
    private function nextVersionFor(int $fiscalYear, int $period): int
    {
        $highest = 0;
        foreach ($this->runs->all() as $run) {
            if ($run->period->fiscalYear !== $fiscalYear || $run->period->period !== $period) {
                continue;
            }
            if ($run->version > $highest) {
                $highest = $run->version;
            }
        }

        return $highest + 1;
    }

    private function requireRun(mixed $runId): CostingRun
    {
        $run = null;

        if (is_string($runId) && $runId !== '') {
            try {
                $run = $this->runs->byId(Uuid::fromString($runId));
            } catch (InvalidValue) {
                $run = null;
            }
        }

        return $run ?? throw new DomainError('E_COSTING_RUN_UNKNOWN', sprintf(
            'costing run %s does not exist',
            is_string($runId) ? $runId : '?',
        ));
    }

    /**
     * @param array<string, list<string>> $edges
     */
    private function assertAcyclic(array $edges): void
    {
        $visiting = [];
        $done = [];

        $visit = function (string $node) use (&$visit, &$visiting, &$done, $edges): void {
            if (isset($done[$node])) {
                return;
            }

            if (isset($visiting[$node])) {
                throw new DomainError('E_COSTING_CYCLE', sprintf(
                    'allocation cycle via cost center "%s" — step ladder requires acyclicity',
                    $node,
                ), ['costCenter' => $node]);
            }

            $visiting[$node] = true;

            foreach ($edges[$node] ?? [] as $next) {
                $visit($next);
            }

            unset($visiting[$node]);
            $done[$node] = true;
        };

        foreach (array_keys($edges) as $node) {
            $visit((string) $node);
        }
    }
}
