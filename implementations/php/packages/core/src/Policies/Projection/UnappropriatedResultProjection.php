<?php

declare(strict_types=1);

namespace Summae\Core\Policies\Projection;

use Summae\Core\Port\FiscalYearRepository;

/**
 * The report itself: the pot, where it came from, and when a resolution about it falls due.
 *
 * The deadline is the part only the pack can answer — how many months a form has after the year
 * end, whether a smaller entity has more, whether the form passes a resolution at all — and only
 * the tenant can say which form it is (`setEntityProfile`). Where either is missing the fields are
 * `null` rather than a plausible default: an application must be able to tell "no deadline in this
 * jurisdiction" from "nobody has said what this company is".
 *
 * Monitoring the date stays outside. summae reports what the data say; who gets reminded, and what
 * happens when the date passes, is the embedding's workflow.
 *
 * The SAME shape lives in the Node unappropriated-result.ts.
 */
final readonly class UnappropriatedResultProjection
{
    public function __construct(
        private UnappropriatedResult $figures,
        private FiscalYearRepository $fiscalYears,
        private LegalFormRegistry $legalForms,
    ) {
    }

    /**
     * @param array<string, mixed> $params fiscalYear?
     *
     * @return array<string, mixed>
     */
    public function compute(array $params): array
    {
        $wanted = Parameters::integerOrNull($params['fiscalYear'] ?? null);
        $figures = $this->figures->figures();
        $declared = $this->legalForms->declared();
        $resolution = $this->legalForms->resolution();

        $rows = [];
        foreach ($figures['byFiscalYear'] as $row) {
            if ($wanted !== null && $row['fiscalYear'] !== $wanted) {
                continue;
            }
            $rows[] = [
                'fiscalYear' => $row['fiscalYear'],
                'result' => $row['result']->amountAsString(),
                'cumulativeResult' => $row['cumulativeResult']->amountAsString(),
                'available' => $row['available']->amountAsString(),
                'resolutionDueBy' => $this->dueBy($row['fiscalYear']),
            ];
        }

        return [
            'cumulativeResult' => $figures['cumulativeResult']->amountAsString(),
            'appropriated' => $figures['allocated']->amountAsString(),
            'unappropriated' => $figures['cumulativeResult']->subtract($figures['allocated'])->amountAsString(),
            'legalForm' => $declared === null ? null : $declared['legalForm'],
            'resolutionRequired' => $resolution === null ? null : $resolution['required'],
            'resolutionBasis' => $resolution === null ? null : $resolution['basis'],
            'byFiscalYear' => $rows,
        ];
    }

    /**
     * The due date needs the year's END, which only the fiscal-year record knows: a year running
     * July to June is not eight months from 31 December. A year the journal knows and the record
     * does not cannot happen through the API, and reports `null` rather than inventing a December.
     */
    private function dueBy(int $fiscalYear): ?string
    {
        $year = $this->fiscalYears->byYear($fiscalYear);
        if ($year === null) {
            return null;
        }
        $due = $this->legalForms->resolutionDueBy($year->end);

        return $due?->iso;
    }
}
