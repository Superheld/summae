<?php

declare(strict_types=1);

namespace Summae\Core\Policies\Expansion\Costing;

use Summae\Core\DomainError;
use Summae\Core\Substrate\AccountType;
use Summae\Core\Substrate\Side;
use Summae\Core\Port\AccountRepository;
use Summae\Core\Port\JournalRepository;
use Summae\Core\Substrate\Currency;
use Summae\Core\Substrate\Exception\InvalidValue;
use Summae\Core\Substrate\IdGenerator;
use Summae\Core\Substrate\Money;
use Summae\Core\Substrate\PeriodRef;
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
    /** @var list<array{sender: string, receivers: list<array{code: string, share: string}>}> */
    private array $schemeSteps = [];

    /** @var array<string, CostingRun> */
    private array $runs = [];

    /** @var array<string, int> "year-period" -> latest version */
    private array $versions = [];

    public function __construct(
        private readonly Currency $baseCurrency,
        private readonly AccountRepository $accounts,
        private readonly JournalRepository $journal,
        private readonly IdGenerator $ids,
    ) {
    }

    /**
     * The step ladder requires acyclicity (E_COSTING_CYCLE);
     * the simultaneous-equation method would be the cycle-capable extension.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function setAllocationScheme(array $input): array
    {
        $method = is_string($input['method'] ?? null) ? $input['method'] : 'step_ladder';

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

        $this->schemeSteps = $steps;

        return ['valid' => true, 'method' => $method, 'stepCount' => count($steps)];
    }

    /**
     * @param array<string, mixed> $input {fiscalYear, period}
     */
    public function run(array $input): CostingRun
    {
        $fiscalYear = is_int($input['fiscalYear'] ?? null) ? $input['fiscalYear'] : 0;
        $period = is_int($input['period'] ?? null) ? $input['period'] : 0;
        $periodRef = new PeriodRef($fiscalYear, $period);

        // Primary-cost intake: expense lines with costCenter dimension.
        $zero = Money::zero($this->baseCurrency);
        /** @var array<string, Money> $primary */
        $primary = [];

        foreach ($this->journal->forFiscalYear($fiscalYear) as $entry) {
            if ($entry->periodRef->period !== $period) {
                continue;
            }

            foreach ($entry->lines() as $line) {
                $account = $this->accounts->byId($line->accountId);
                if ($account === null || $account->type !== AccountType::Expense) {
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

        // Allocation (step ladder, in step order): distribute, never create.
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

        $grandTotal = $zero;
        foreach ($after as $total) {
            $grandTotal = $grandTotal->add($total);
        }

        $key = $fiscalYear . '-' . $period;
        $version = ($this->versions[$key] ?? 0) + 1;
        $this->versions[$key] = $version;

        $run = new CostingRun($this->ids->next(), $periodRef, $version, $primary, $after, $grandTotal);
        $this->runs[$run->id->value] = $run;

        return $run;
    }

    /** @param array<string, mixed> $input */
    public function release(array $input): CostingRun
    {
        $run = $this->requireRun($input['runId'] ?? null);
        $run->release();

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

        return [
            'runId' => $run->id->value,
            'status' => $run->status(),
            'version' => $run->version,
            'primary' => $this->serializeTotals($run->primary),
            'afterAllocation' => $this->serializeTotals($run->afterAllocation),
            'grandTotal' => $run->grandTotal->amountAsString(),
        ];
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

    private function requireRun(mixed $runId): CostingRun
    {
        $run = null;

        if (is_string($runId) && $runId !== '') {
            try {
                $run = $this->runs[Uuid::fromString($runId)->value] ?? null;
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
