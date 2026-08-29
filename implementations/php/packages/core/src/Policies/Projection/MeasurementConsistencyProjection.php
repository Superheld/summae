<?php

declare(strict_types=1);

namespace Summae\Core\Policies\Projection;

use Summae\Core\Port\CostingRunRepository;

/**
 * Consistency of measurement across periods (F-CORE-049).
 *
 * **Half of this was already built, and saying which half is the point.** A released costing run has
 * always frozen the basis it was computed under: `productionCost.components` lists every configured
 * component with the pack's treatment and whether it went in, and the run *stores* that rather than
 * recomputing it, on the stated ground that "a released run that answers differently tomorrow is not
 * released". So the record existed. What did not exist was anybody comparing two records. The
 * election lives in the tenant configuration and can be changed between two runs, and until this
 * projection there was no question you could ask that would have told you.
 *
 * **It reports; it does not refuse — and that is a rule about rules, not a softness.** Frameworks
 * that require measurement to stay consistent also permit a departure that is justified and
 * disclosed. A mechanism that rejected a changed election would therefore be *wrong* rather than
 * merely strict: it would enforce half a rule. Whether a departure is justified is a judgement about
 * the business, which no library can make. What a library can do is make the departure impossible to
 * miss. Same line `vatReturn.gapWarnings` and `duplicateVouchers` already draw.
 *
 * **Released runs only, all of them, in the repository's order.** A draft is a working figure, and a
 * basis that never reached a released run never reached the books either. Every released run is
 * reported and not merely the newest per period: re-releasing a period under a different basis *is*
 * a change of measurement, and one that whoever read the earlier version has already relied on.
 * Collapsing to the newest would hide exactly that.
 *
 * **`withoutBasis` is not padding.** A tenant that valued under a basis and then ran without one has
 * not changed the basis, so it does not belong in `changes` — but a reader comparing two periods
 * needs to know the second measured nothing rather than measured the same. An omission that is not
 * stated reads as agreement.
 *
 * **Named more broadly than its current content, deliberately.** The production-cost election is the
 * only measurement option summae has today. When stock arrives with a consumption sequence, or
 * provisions with a discount rate, they belong in *this* answer and not in a second projection with
 * a third name — the question being asked is "did the way you measure change", and it is asked once.
 *
 * The projection carries **no threshold, no citation and no verdict**: which framework demands the
 * comparison, and what counts as a justified departure, is pack and census territory (the German
 * reading is in `docs/hgb-conformance.md`). The core only guarantees that the two bases end up on
 * the table next to each other.
 */
final readonly class MeasurementConsistencyProjection
{
    public function __construct(private CostingRunRepository $runs)
    {
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array{
     *     runs: list<array{runId: string, fiscalYear: int, period: int, version: int, included: list<string>, elected: list<string>}>,
     *     withoutBasis: list<array{runId: string, fiscalYear: int, period: int, version: int}>,
     *     changes: list<array{fromRunId: string, toRunId: string, from: array{fiscalYear: int, period: int}, to: array{fiscalYear: int, period: int}, added: list<string>, removed: list<string>, acrossFiscalYears: bool}>,
     *     consistent: bool
     * }
     */
    public function compute(array $params): array
    {
        unset($params);

        $rows = [];
        $withoutBasis = [];

        foreach ($this->runs->all() as $run) {
            if ($run->status() !== 'released') {
                continue;
            }

            $where = [
                'runId' => $run->id->value,
                'fiscalYear' => $run->period->fiscalYear,
                'period' => $run->period->period,
                'version' => $run->version,
            ];

            if ($run->productionCost === null) {
                $withoutBasis[] = $where;
                continue;
            }

            $included = [];
            $elected = [];
            foreach ($run->productionCost['components'] as $component) {
                if (!$component['included']) {
                    continue;
                }
                $included[] = $component['id'];
                // The election is the optional half. A mandatory component is in the basis because
                // the pack says so, and reporting it as a choice would blame the preparer for the
                // jurisdiction — the two must stay distinguishable, or a pack that promotes a
                // component from optional to mandatory reads as the tenant having changed its mind.
                if ($component['treatment'] === 'optional') {
                    $elected[] = $component['id'];
                }
            }

            // Sorted by code point, like every other list this library emits: the basis is a SET,
            // and comparing two of them must not depend on the order the components were configured
            // in. Two runs with the same components in a different order are the same measurement.
            sort($included, SORT_STRING);
            sort($elected, SORT_STRING);

            $rows[] = $where + ['included' => $included, 'elected' => $elected];
        }

        $changes = [];
        for ($i = 1, $n = count($rows); $i < $n; ++$i) {
            $previous = $rows[$i - 1];
            $current = $rows[$i];

            $added = array_values(array_diff($current['included'], $previous['included']));
            $removed = array_values(array_diff($previous['included'], $current['included']));

            if ($added === [] && $removed === []) {
                continue;
            }

            $changes[] = [
                'fromRunId' => $previous['runId'],
                'toRunId' => $current['runId'],
                'from' => ['fiscalYear' => $previous['fiscalYear'], 'period' => $previous['period']],
                'to' => ['fiscalYear' => $current['fiscalYear'], 'period' => $current['period']],
                'added' => $added,
                'removed' => $removed,
                // Reported, never judged. A change inside one fiscal year is still absorbed by
                // that year's own result; a change that spans a year boundary is the one a reader
                // has to account for, because the two years are then not comparable without an
                // explanation. Which of the two a framework cares about is the pack's business —
                // the flag exists so a caller does not have to derive it from two period numbers.
                'acrossFiscalYears' => $previous['fiscalYear'] !== $current['fiscalYear'],
            ];
        }

        return [
            'runs' => $rows,
            'withoutBasis' => $withoutBasis,
            'changes' => $changes,
            'consistent' => $changes === [],
        ];
    }
}
