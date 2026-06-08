<?php

declare(strict_types=1);

namespace Rechnungswesen\Core\Costing;

use Rechnungswesen\Core\DomainError;
use Rechnungswesen\Core\Ledger\AccountType;
use Rechnungswesen\Core\Ledger\Side;
use Rechnungswesen\Core\Port\AccountRepository;
use Rechnungswesen\Core\Port\JournalRepository;
use Rechnungswesen\Core\Shared\Currency;
use Rechnungswesen\Core\Shared\Exception\InvalidValue;
use Rechnungswesen\Core\Shared\IdGenerator;
use Rechnungswesen\Core\Shared\Money;
use Rechnungswesen\Core\Shared\PeriodRef;
use Rechnungswesen\Core\Shared\Uuid;

/**
 * KLR-Abrechnung (costing-modell.md): eigener Rechnungskreis — das
 * Fibu-Journal bleibt unberührt. Primärkostenübernahme über die
 * costCenter-Dimension, Umlage per Stufenleiter (zyklenfrei,
 * E_COSTING_CYCLE), Verteilung per Money::allocate (largest remainder,
 * Gleichstand -> erster Empfänger in stabiler Reihenfolge).
 */
final class CostingService
{
    /** @var list<array{sender: string, receivers: list<array{code: string, share: string}>}> */
    private array $schemeSteps = [];

    /** @var array<string, CostingRun> */
    private array $runs = [];

    /** @var array<string, int> "year-period" -> letzte Version */
    private array $versions = [];

    public function __construct(
        private readonly Currency $baseCurrency,
        private readonly AccountRepository $accounts,
        private readonly JournalRepository $journal,
        private readonly IdGenerator $ids,
    ) {
    }

    /**
     * Stufenleiter verlangt Zyklenfreiheit (E_COSTING_CYCLE);
     * das Gleichungsverfahren wäre die zyklenfähige Ausbaustufe.
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
                throw new InvalidValue('Umlageschritt braucht sender');
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

        // Primärkostenübernahme: Aufwandszeilen mit costCenter-Dimension.
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

        // Umlage (Stufenleiter, in Schrittreihenfolge): verteilen, nie erzeugen.
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
     * BAB: Matrix-Summen eines Laufs (costing-modell.md Projektionen).
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
            'Abrechnungslauf %s existiert nicht',
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
                    'Umlagezyklus über Kostenstelle "%s" — Stufenleiter verlangt Zyklenfreiheit',
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
