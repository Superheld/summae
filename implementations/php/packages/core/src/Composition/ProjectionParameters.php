<?php

declare(strict_types=1);

namespace Summae\Core\Composition;

use Summae\Core\DomainError;
use Summae\Core\Policies\Projection\Parameters;

/**
 * The parameter contract of the projections, as data — plus the check that enforces it.
 *
 * The table is a hand-kept copy of testing/testsuite/schema/api-parameters.json, the normative
 * source. The core reads no files by design (framework-free, no I/O), so the table has to live
 * in the code — and a copy that nothing compares drifts. `ProjectionParametersTest` (Node:
 * `projection-parameters.test.ts`) asserts this constant equals that file, in both languages.
 * Change the schema in the knowledge base, mirror it, and the tests tell you what to change here.
 *
 * `required` is declared for completeness; enforcing it is a separate step (see validate()).
 * `acceptedWithoutEffect` marks a parameter existing fixtures pass and no implementation reads —
 * declared so the gap is visible instead of hiding behind a tolerant reader.
 */
final class ProjectionParameters
{
    /** @var array<string, array<string, array{type: string, required?: bool, acceptedWithoutEffect?: bool}>> */
    public const PARAMETERS = [
        'trialBalance' => [
            'fiscalYear' => ['type' => 'integer', 'required' => true],
            'throughPeriod' => ['type' => 'integer'],
            'includeZeroBalances' => ['type' => 'boolean'],
        ],
        'accountSheet' => [
            'account' => ['type' => 'string', 'required' => true],
            'fiscalYear' => ['type' => 'integer', 'required' => true],
            'throughPeriod' => ['type' => 'integer'],
        ],
        'balanceSheet' => [
            'mapping' => ['type' => 'string', 'required' => true],
            'fiscalYear' => ['type' => 'integer'],
            'asOf' => ['type' => 'date'],
            'incomeMapping' => ['type' => 'string', 'acceptedWithoutEffect' => true],
        ],
        'incomeStatement' => [
            'mapping' => ['type' => 'string', 'required' => true],
            'fiscalYear' => ['type' => 'integer', 'required' => true],
            'fromPeriod' => ['type' => 'integer'],
            'throughPeriod' => ['type' => 'integer'],
        ],
        'cashBasisReport' => [
            'year' => ['type' => 'integer', 'required' => true],
            'mapping' => ['type' => 'string'],
            'asOf' => ['type' => 'date'],
        ],
        'vatReturn' => [
            'year' => ['type' => 'integer', 'required' => true],
            'quarter' => ['type' => 'integer'],
            'asOf' => ['type' => 'date'],
        ],
        'ecSalesList' => [
            'year' => ['type' => 'integer', 'required' => true],
            'quarter' => ['type' => 'integer'],
        ],
        'openItems' => [
            'kind' => ['type' => 'string'],
            'partnerId' => ['type' => 'string'],
            'asOf' => ['type' => 'date'],
        ],
        'assetRegister' => [
            'asOf' => ['type' => 'date'],
        ],
        'auditLog' => [
            'from' => ['type' => 'date'],
            'to' => ['type' => 'date'],
        ],
        'journalExport' => [
            'fiscalYear' => ['type' => 'integer', 'required' => true],
            'format' => ['type' => 'string', 'acceptedWithoutEffect' => true],
        ],
        'datevExport' => [
            'kind' => ['type' => 'string'],
            'fiscalYear' => ['type' => 'integer'],
            'fromPeriod' => ['type' => 'integer'],
            'throughPeriod' => ['type' => 'integer'],
        ],
        'auditDataExport' => [
            'fiscalYear' => ['type' => 'integer', 'required' => true],
            'asOf' => ['type' => 'date'],
        ],
        'costAllocationSheet' => [
            'runId' => ['type' => 'string', 'required' => true],
            'fiscalYear' => ['type' => 'integer', 'acceptedWithoutEffect' => true],
            'period' => ['type' => 'integer', 'acceptedWithoutEffect' => true],
        ],
    ];

    /**
     * Checks a projection's params against the contract — once, at the dispatcher, instead of
     * 18 times inside the projections.
     *
     * Two rules, both about telling "absent" from "wrong":
     *   - a parameter that is not declared is a caller mistake, not something to ignore. Ignoring
     *     it turned a misspelled `fiscalYear` on vatReturn into a plausible ANNUAL figure where a
     *     quarter was asked for, and `includeZeroBalance` into a flag that did nothing.
     *   - a declared parameter present with the wrong type is rejected, never coerced. `2026.4` is
     *     not a year; whoever meant "year 2026, period 4" has to say which.
     * Absent keeps the documented default — that is not this method's business, it is the
     * reader's (Parameters).
     *
     * `null` counts as absent: JSON callers and CLI wrappers write an omitted parameter both ways,
     * and every projection has treated it as absent since long before this contract existed.
     *
     * @param array<string, mixed> $params
     */
    public static function validate(string $name, array $params): void
    {
        // An unknown projection name is the dispatcher's own error (E_NOT_IMPLEMENTED); it must
        // not be reported as an input problem on the way there.
        if (!isset(self::PARAMETERS[$name])) {
            return;
        }

        $declared = self::PARAMETERS[$name];

        foreach ($params as $key => $value) {
            if (!isset($declared[$key])) {
                throw new DomainError(
                    'E_INPUT_INVALID',
                    sprintf('%s: unknown parameter "%s"', $name, $key),
                    ['parameter' => $key],
                );
            }

            if ($value === null) {
                continue;
            }

            $type = $declared[$key]['type'];

            if (!Parameters::matchesType($value, $type)) {
                throw new DomainError(
                    'E_INPUT_INVALID',
                    sprintf('%s: parameter "%s" must be of type %s', $name, $key, $type),
                    [$key => DomainError::rejectedValue($value)],
                );
            }
        }
    }
}
