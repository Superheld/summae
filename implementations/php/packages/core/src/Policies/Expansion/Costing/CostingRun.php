<?php

declare(strict_types=1);

namespace Summae\Core\Policies\Expansion\Costing;

use Summae\Core\DomainError;
use Summae\Core\Substrate\Money;
use Summae\Core\Substrate\PeriodRef;
use Summae\Core\Substrate\Uuid;

/**
 * Costing run (costing-modell.md aggregate 1): unique per period + version;
 * repetition creates a new version. draft -> released.
 * Invariants: the allocation total is preserved, auxiliary centers after
 * allocation = 0 (ensured by the service during computation).
 */
final class CostingRun
{
    private string $status = 'draft';

    /**
     * @param array<string, Money> $primary cost center -> primary costs
     * @param array<string, Money> $afterAllocation
     * @param list<array{costCenter: string, label: string, overhead: string, base: string, rate: string|null}> $rates
     * @param list<array{costCenter: string, reason: string}> $rateWarnings
     * @param array{total: string, components: list<array{id: string, amount: string, treatment: string, included: bool}>}|null $productionCost
     */
    public function __construct(
        public readonly Uuid $id,
        public readonly PeriodRef $period,
        public readonly int $version,
        public readonly array $primary,
        public readonly array $afterAllocation,
        public readonly Money $grandTotal,
        // Which procedure produced these numbers. It belongs in the run because the two answer the
        // same question differently, and a sheet that does not say how it was allocated cannot be
        // checked against anything.
        public readonly string $method = 'step_ladder',
        // Frozen with the run, not recomputed on read: the configuration can change after a release,
        // and a released run that answers differently tomorrow is not released.
        public readonly array $rates = [],
        public readonly array $rateWarnings = [],
        public readonly ?array $productionCost = null,
    ) {
    }

    public function status(): string
    {
        return $this->status;
    }

    public function release(): void
    {
        if ($this->status === 'released') {
            throw new DomainError('E_COSTING_RUN_RELEASED', sprintf(
                'run %s is already released — changes create a new version',
                $this->id->value,
            ), ['runId' => $this->id->value]);
        }

        $this->status = 'released';
    }

    /**
     * Persistable form (F-KLR-001/004).
     *
     * A run used to live in an array inside the service, which meant a released run was gone with
     * the process that made it. The requirements had said otherwise all along — runs are versioned
     * per period, and the BAB and the rates are a projection *of a released run* — so a run no
     * later process can read satisfies neither. Everything the three projections read is in here,
     * and nothing else is: what a run answers must not depend on configuration that has moved.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id->value,
            'period' => $this->period->jsonSerialize(),
            'version' => $this->version,
            'status' => $this->status,
            'method' => $this->method,
            'primary' => self::totalsToJson($this->primary),
            'afterAllocation' => self::totalsToJson($this->afterAllocation),
            'grandTotal' => $this->grandTotal->jsonSerialize(),
            'rates' => $this->rates,
            'rateWarnings' => $this->rateWarnings,
            'productionCost' => $this->productionCost,
        ];
    }

    /**
     * Restore from persistence — status taken over directly, no re-validation.
     *
     * @param array<string, Money> $primary
     * @param array<string, Money> $afterAllocation
     * @param list<array{costCenter: string, label: string, overhead: string, base: string, rate: string|null}> $rates
     * @param list<array{costCenter: string, reason: string}> $rateWarnings
     * @param array{total: string, components: list<array{id: string, amount: string, treatment: string, included: bool}>}|null $productionCost
     */
    public static function restore(
        Uuid $id,
        PeriodRef $period,
        int $version,
        string $status,
        array $primary,
        array $afterAllocation,
        Money $grandTotal,
        string $method,
        array $rates,
        array $rateWarnings,
        ?array $productionCost,
    ): self {
        $run = new self($id, $period, $version, $primary, $afterAllocation, $grandTotal, $method, $rates, $rateWarnings, $productionCost);
        $run->status = $status;

        return $run;
    }

    /**
     * Cost-centre totals as an object, keys sorted by code point.
     *
     * A JSON object preserves insertion order, so writing the map as it happens to be iterated
     * would make the stored bytes depend on the order the postings arrived in — and the export has
     * to be byte-identical across implementations (SF-15).
     *
     * @param array<string, Money> $totals
     *
     * @return array<string, mixed>
     */
    private static function totalsToJson(array $totals): array|\stdClass
    {
        $codes = array_keys($totals);
        sort($codes, SORT_STRING);

        $out = [];
        foreach ($codes as $code) {
            $out[$code] = $totals[$code]->jsonSerialize();
        }

        // A map with no entries is `{}`, never `[]` — PHP's empty array encodes as a LIST, and a run
        // with no cost centres wrote `"primary": []` where Node wrote `"primary": {}`. Same idiom as
        // AuditRecord::changes, and the same defect: the two engines' stored documents stopped being
        // byte-identical, on a shared database, with every gate green. Nothing crossed that line
        // until the cross-test started comparing the stored aggregates (IMPL-046) — it found this on
        // its first run, in one fixture out of 126.
        return $out === [] ? new \stdClass() : $out;
    }
}
