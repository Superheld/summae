<?php

declare(strict_types=1);

namespace Summae\Core\Tests\Projection;

use PHPUnit\Framework\TestCase;
use Summae\Core\InMemory\InMemoryCostingRunRepository;
use Summae\Core\Policies\Expansion\Costing\CostingRun;
use Summae\Core\Policies\Projection\MeasurementConsistencyProjection;
use Summae\Core\Substrate\Currency;
use Summae\Core\Substrate\Money;
use Summae\Core\Substrate\PeriodRef;
use Summae\Core\Substrate\Uuid;

/**
 * The cases the fixture cannot reach, and one of them matters more than it looks.
 *
 * `costing/measurement-consistency` drives the real path — two years, a changed election, a run
 * with no basis. What it cannot exercise is the shape of the answer when there is nothing to
 * compare, and `consistent: true` over an empty set is a *claim*: a caller that renders a green
 * badge from that field will render it for books that were never valued at all. So the empty case
 * is pinned here, together with the two boundaries the fixture only passes through once — a draft
 * that must not be reported, and two runs whose components differ only in order, which is the same
 * measurement and must not count as a change.
 *
 * The SAME cases live in the Node measurement-consistency.test.ts.
 */
final class MeasurementConsistencyTest extends TestCase
{
    /** @param list<array{id: string, treatment: string, included: bool}> $components */
    private static function costingRun(int $year, int $period, string $status, ?array $components): CostingRun
    {
        $eur = Currency::of('EUR');
        $productionCost = null;
        if ($components !== null) {
            $rows = [];
            foreach ($components as $component) {
                $rows[] = $component + ['amount' => '0.00'];
            }
            $productionCost = ['total' => '0.00', 'components' => $rows];
        }

        return CostingRun::restore(
            Uuid::fromString(sprintf('00000000-0000-7000-8000-%012d', $year * 100 + $period)),
            new PeriodRef($year, $period),
            1,
            $status,
            [],
            [],
            Money::zero($eur),
            'step_ladder',
            [],
            [],
            $productionCost,
        );
    }

    /**
     * @return array{
     *     runs: list<array{runId: string, fiscalYear: int, period: int, version: int, included: list<string>, elected: list<string>}>,
     *     withoutBasis: list<array{runId: string, fiscalYear: int, period: int, version: int}>,
     *     changes: list<array{fromRunId: string, toRunId: string, from: array{fiscalYear: int, period: int}, to: array{fiscalYear: int, period: int}, added: list<string>, removed: list<string>, acrossFiscalYears: bool}>,
     *     consistent: bool
     * }
     */
    private static function compute(CostingRun ...$runs): array
    {
        $repository = new InMemoryCostingRunRepository();
        foreach ($runs as $run) {
            $repository->add($run);
        }

        return (new MeasurementConsistencyProjection($repository))->compute([]);
    }

    public function testAnEmptyReportSaysConsistentAndAlsoSaysThereWasNothingToCompare(): void
    {
        $result = self::compute();

        self::assertSame([], $result['runs']);
        self::assertSame([], $result['withoutBasis']);
        self::assertSame([], $result['changes']);
        // True, and only readable together with an empty `runs`. Named here so a caller that turns
        // this field into a badge has been warned by a test rather than by an auditor.
        self::assertTrue($result['consistent']);
    }

    public function testADraftIsNotReportedAtAll(): void
    {
        $result = self::compute(
            self::costingRun(2026, 1, 'draft', [['id' => 'material', 'treatment' => 'mandatory', 'included' => true]]),
        );

        self::assertSame([], $result['runs']);
        self::assertTrue($result['consistent']);
    }

    public function testTheSameComponentsInADifferentOrderAreTheSameMeasurement(): void
    {
        $result = self::compute(
            self::costingRun(2026, 1, 'released', [
                ['id' => 'material', 'treatment' => 'mandatory', 'included' => true],
                ['id' => 'admin', 'treatment' => 'optional', 'included' => true],
            ]),
            self::costingRun(2027, 1, 'released', [
                ['id' => 'admin', 'treatment' => 'optional', 'included' => true],
                ['id' => 'material', 'treatment' => 'mandatory', 'included' => true],
            ]),
        );

        self::assertSame([], $result['changes']);
        self::assertTrue($result['consistent']);
        self::assertSame(['admin', 'material'], $result['runs'][0]['included']);
    }

    public function testAMandatoryComponentIsNeverReportedAsAnElection(): void
    {
        $result = self::compute(
            self::costingRun(2026, 1, 'released', [
                ['id' => 'material', 'treatment' => 'mandatory', 'included' => true],
                ['id' => 'research', 'treatment' => 'forbidden', 'included' => false],
            ]),
        );

        self::assertSame(['material'], $result['runs'][0]['included']);
        // A pack that promotes a component from optional to mandatory must not read as the tenant
        // having changed its mind, which is what an `elected` list built from `included` would do.
        self::assertSame([], $result['runs'][0]['elected']);
    }
}
