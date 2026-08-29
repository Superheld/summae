<?php

declare(strict_types=1);

namespace Summae\Core\Composition;

use Summae\Core\DomainError;
use Summae\Core\Policies\Projection\Parameters;

/**
 * The input contract of the operations, as data — the write-side twin of ProjectionParameters
 * (F-9).
 *
 * The asymmetry this closes was the wrong way round for a long time. Every projection parameter
 * was declared and an undeclared one was E_INPUT_INVALID naming the parameter; `execute()` read
 * what it recognised out of the input and ignored the rest. So a typo in a *read* failed loudly
 * and a typo in a *write* was silent — and a write is the one that ends up in the books.
 *
 * Three defects had already come out of that silence: `expandTax` takes `date` where its
 * neighbours take `voucherDate`/`entryDate`, so the wrong name yielded a tax-version error naming
 * a field that was supplied; a wrong `direction` fell through to a default and booked an incoming
 * invoice inverted (IMPL-013, hardened by hand afterwards); and every numeric input was read with
 * a type check, so a form's "30" was not rejected but *ignored*, and the documented default stood
 * in its place.
 *
 * Same table, same source, same drift test as the projections: this is a hand-kept copy of
 * testing/testsuite/schema/api-parameters.json (the core reads no files by design) and
 * OperationParametersTest asserts the two are equal, in both languages.
 *
 * **What it does not do: enforce `required`.** The flag is declared, and the check stays where it
 * is. An operation missing its subject already fails, and it fails with a better code than this
 * layer could give — E_VOUCHER_UNKNOWN, E_ASSET_UNKNOWN, E_ENTRY_NO_VOUCHER say what is missing;
 * a central E_INPUT_INVALID would say less and would overwrite error codes the fixtures pin. The
 * finding is about inputs that are *present and wrong*, and that is what this catches.
 */
final class OperationParameters
{
    /** @var array<string, array<string, array{type: string, required?: true, acceptedWithoutEffect?: true}>> */
    public const OPERATIONS = [
        'post' => [
            'voucherId' => [ 'type' => 'string', 'required' => true ],
            'entryDate' => [ 'type' => 'date', 'required' => true ],
            'lines' => [
                'type' => 'array',
                'required' => true,
                'element' => [
                    'type' => 'object',
                    'fields' => [
                        'account' => [ 'type' => 'string' ],
                        'side' => [ 'type' => 'string' ],
                        'money' => [ 'type' => 'money' ],
                        'dimensions' => [
                            'type' => 'array',
                            'element' => [
                                'type' => 'object',
                                'fields' => [
                                    'type' => [ 'type' => 'string' ],
                                    'code' => [ 'type' => 'string' ],
                                ],
                            ],
                        ],
                        'taxTag' => [
                            'type' => 'object',
                            'fields' => [
                                'code' => [ 'type' => 'string' ],
                                'appliedVersion' => [ 'type' => 'string' ],
                                'reportingKey' => [ 'type' => 'string' ],
                                'baseMoney' => [ 'type' => 'money' ],
                            ],
                        ],
                        'openItem' => [ 'type' => 'object', 'acceptedWithoutEffect' => true, 'opaque' => 'read by no implementation' ],
                    ],
                ],
            ],
            'text' => [ 'type' => 'string' ],
            'actor' => [ 'type' => 'string' ],
        ],
        'postVoucher' => [
            'voucher' => [
                'type' => 'object',
                'required' => true,
                'fields' => [
                    'voucherNumber' => [ 'type' => 'string' ],
                    'voucherDate' => [ 'type' => 'date' ],
                    'serviceDate' => [ 'type' => 'date' ],
                    'servicePeriod' => [ 'type' => 'object', 'opaque' => 'a from/to window the record stores whole' ],
                    'economicYear' => [ 'type' => 'integer' ],
                    'due' => [ 'type' => 'date' ],
                    'recurring' => [ 'type' => 'boolean' ],
                    'issuer' => [ 'type' => 'string' ],
                    'kind' => [ 'type' => 'string' ],
                    'partnerId' => [ 'type' => 'string' ],
                    'supplierTaxationMethod' => [ 'type' => 'string' ],
                ],
            ],
            'taxCode' => [ 'type' => 'string' ],
            'direction' => [ 'type' => 'string' ],
            'reduction' => [ 'type' => 'boolean' ],
            'netLines' => [
                'type' => 'array',
                'element' => [
                    'type' => 'object',
                    'fields' => [
                        'account' => [ 'type' => 'string' ],
                        'money' => [ 'type' => 'money' ],
                        'taxCode' => [ 'type' => 'string' ],
                        'dimensions' => [
                            'type' => 'array',
                            'element' => [
                                'type' => 'object',
                                'fields' => [
                                    'type' => [ 'type' => 'string' ],
                                    'code' => [ 'type' => 'string' ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'lines' => [
                'type' => 'array',
                'element' => [
                    'type' => 'object',
                    'fields' => [
                        'account' => [ 'type' => 'string' ],
                        'side' => [ 'type' => 'string' ],
                        'money' => [ 'type' => 'money' ],
                        'dimensions' => [
                            'type' => 'array',
                            'element' => [
                                'type' => 'object',
                                'fields' => [
                                    'type' => [ 'type' => 'string' ],
                                    'code' => [ 'type' => 'string' ],
                                ],
                            ],
                        ],
                        'taxTag' => [
                            'type' => 'object',
                            'fields' => [
                                'code' => [ 'type' => 'string' ],
                                'appliedVersion' => [ 'type' => 'string' ],
                                'reportingKey' => [ 'type' => 'string' ],
                                'baseMoney' => [ 'type' => 'money' ],
                            ],
                        ],
                        'openItem' => [ 'type' => 'object', 'acceptedWithoutEffect' => true, 'opaque' => 'read by no implementation' ],
                    ],
                ],
            ],
            'counterAccount' => [ 'type' => 'string' ],
            'entryDate' => [ 'type' => 'date' ],
            'text' => [ 'type' => 'string' ],
            'actor' => [ 'type' => 'string' ],
        ],
        'createVoucher' => [
            'voucher' => [
                'type' => 'object',
                'required' => true,
                'fields' => [
                    'voucherNumber' => [ 'type' => 'string' ],
                    'voucherDate' => [ 'type' => 'date' ],
                    'serviceDate' => [ 'type' => 'date' ],
                    'servicePeriod' => [ 'type' => 'object', 'opaque' => 'a from/to window the record stores whole' ],
                    'economicYear' => [ 'type' => 'integer' ],
                    'due' => [ 'type' => 'date' ],
                    'recurring' => [ 'type' => 'boolean' ],
                    'issuer' => [ 'type' => 'string' ],
                    'kind' => [ 'type' => 'string' ],
                    'partnerId' => [ 'type' => 'string' ],
                    'supplierTaxationMethod' => [ 'type' => 'string' ],
                ],
            ],
            'actor' => [ 'type' => 'string' ],
        ],
        'correct' => [
            'entryId' => [ 'type' => 'string', 'required' => true ],
            'text' => [ 'type' => 'string' ],
            'lines' => [
                'type' => 'array',
                'element' => [
                    'type' => 'object',
                    'fields' => [
                        'account' => [ 'type' => 'string' ],
                        'side' => [ 'type' => 'string' ],
                        'money' => [ 'type' => 'money' ],
                        'dimensions' => [
                            'type' => 'array',
                            'element' => [
                                'type' => 'object',
                                'fields' => [
                                    'type' => [ 'type' => 'string' ],
                                    'code' => [ 'type' => 'string' ],
                                ],
                            ],
                        ],
                        'taxTag' => [
                            'type' => 'object',
                            'fields' => [
                                'code' => [ 'type' => 'string' ],
                                'appliedVersion' => [ 'type' => 'string' ],
                                'reportingKey' => [ 'type' => 'string' ],
                                'baseMoney' => [ 'type' => 'money' ],
                            ],
                        ],
                        'openItem' => [ 'type' => 'object', 'acceptedWithoutEffect' => true, 'opaque' => 'read by no implementation' ],
                    ],
                ],
            ],
            'actor' => [ 'type' => 'string' ],
        ],
        'finalize' => [
            'entryId' => [ 'type' => 'string' ],
            'finalizeUntil' => [ 'type' => 'date' ],
            'actor' => [ 'type' => 'string' ],
        ],
        'reverse' => [
            'entryId' => [ 'type' => 'string', 'required' => true ],
            'entryDate' => [ 'type' => 'date', 'required' => true ],
            'text' => [ 'type' => 'string' ],
            'voucherId' => [ 'type' => 'string' ],
            'actor' => [ 'type' => 'string' ],
        ],
        'settle' => [
            'entryId' => [ 'type' => 'string', 'required' => true ],
            'allocations' => [
                'type' => 'array',
                'required' => true,
                'element' => [
                    'type' => 'object',
                    'fields' => [
                        'openItemId' => [ 'type' => 'string' ],
                        'money' => [ 'type' => 'money' ],
                        'difference' => [
                            'type' => 'object',
                            'fields' => [
                                'money' => [ 'type' => 'money' ],
                                'kind' => [ 'type' => 'string' ],
                            ],
                        ],
                    ],
                ],
            ],
            'actor' => [ 'type' => 'string' ],
        ],
        'createAccount' => [
            'number' => [ 'type' => 'string', 'required' => true ],
            'name' => [ 'type' => 'string', 'required' => true ],
            'type' => [ 'type' => 'string', 'required' => true ],
            'subtype' => [ 'type' => 'string' ],
            'status' => [ 'type' => 'string' ],
            'validFrom' => [ 'type' => 'date' ],
            'validTo' => [ 'type' => 'date' ],
            'actor' => [ 'type' => 'string' ],
        ],
        'lockAccount' => [
            'number' => [ 'type' => 'string', 'required' => true ],
            'actor' => [ 'type' => 'string' ],
        ],
        'unlockAccount' => [
            'number' => [ 'type' => 'string', 'required' => true ],
            'actor' => [ 'type' => 'string' ],
        ],
        'importChartOfAccounts' => [
            'rows' => [
                'type' => 'array',
                'required' => true,
                'element' => [
                    'type' => 'object',
                    'fields' => [
                        'number' => [ 'type' => 'string' ],
                        'name' => [ 'type' => 'string' ],
                        'type' => [ 'type' => 'string' ],
                        'subtype' => [ 'type' => 'string' ],
                        'status' => [ 'type' => 'string' ],
                        'validFrom' => [ 'type' => 'date' ],
                        'validTo' => [ 'type' => 'date' ],
                    ],
                ],
            ],
            'format' => [ 'type' => 'string', 'acceptedWithoutEffect' => true ],
            'actor' => [ 'type' => 'string' ],
        ],
        'defineDimensionType' => [
            'code' => [ 'type' => 'string', 'required' => true ],
            'actor' => [ 'type' => 'string' ],
        ],
        'defineDimensionValue' => [
            'type' => [ 'type' => 'string', 'required' => true ],
            'code' => [ 'type' => 'string', 'required' => true ],
            'actor' => [ 'type' => 'string' ],
        ],
        'createFiscalYear' => [
            'year' => [ 'type' => 'integer', 'required' => true ],
            'start' => [ 'type' => 'date', 'required' => true ],
            'end' => [ 'type' => 'date', 'required' => true ],
            'actor' => [ 'type' => 'string' ],
        ],
        'closePeriod' => [
            'fiscalYear' => [ 'type' => 'integer', 'required' => true ],
            'period' => [ 'type' => 'integer', 'required' => true ],
            'actor' => [ 'type' => 'string' ],
        ],
        'reopenPeriod' => [
            'fiscalYear' => [ 'type' => 'integer', 'required' => true ],
            'period' => [ 'type' => 'integer', 'required' => true ],
            'actor' => [ 'type' => 'string' ],
        ],
        'closeFiscalYear' => [
            'fiscalYear' => [ 'type' => 'integer', 'required' => true ],
            'actor' => [ 'type' => 'string' ],
        ],
        'appropriateResult' => [
            'fiscalYear' => [ 'type' => 'integer', 'required' => true ],
            'entryDate' => [ 'type' => 'date', 'required' => true ],
            'voucherId' => [ 'type' => 'string', 'required' => true ],
            'text' => [ 'type' => 'string' ],
            'appropriations' => [
                'type' => 'array',
                'required' => true,
                'element' => [
                    'type' => 'object',
                    'fields' => [
                        'target' => [ 'type' => 'string' ],
                        'money' => [ 'type' => 'money' ],
                    ],
                ],
            ],
            'actor' => [ 'type' => 'string' ],
        ],
        'expandTax' => [
            'date' => [ 'type' => 'date', 'required' => true ],
            'serviceDate' => [ 'type' => 'date' ],
            'direction' => [ 'type' => 'string' ],
            'reduction' => [ 'type' => 'boolean' ],
            'taxCode' => [ 'type' => 'string' ],
            'netLines' => [
                'type' => 'array',
                'required' => true,
                'element' => [
                    'type' => 'object',
                    'fields' => [
                        'account' => [ 'type' => 'string' ],
                        'money' => [ 'type' => 'money' ],
                        'taxCode' => [ 'type' => 'string' ],
                        'dimensions' => [
                            'type' => 'array',
                            'required' => true,
                            'element' => [
                                'type' => 'object',
                                'fields' => [
                                    'type' => [ 'type' => 'string' ],
                                    'code' => [ 'type' => 'string' ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'setEntityProfile' => [
            'legalForm' => ['type' => 'string', 'required' => true],
            'sizeClass' => ['type' => 'string'],
            'actor' => ['type' => 'string'],
        ],
        'setTaxProfile' => [
            'smallBusiness' => [
                'type' => 'object',
                'required' => true,
                'fields' => [
                    'validFrom' => [ 'type' => 'date' ],
                    'value' => [ 'type' => 'boolean' ],
                ],
            ],
            'reason' => [ 'type' => 'string', 'acceptedWithoutEffect' => true ],
            'actor' => [ 'type' => 'string' ],
        ],
        'importMapping' => [
            'mapping' => [ 'type' => 'object', 'required' => true, 'opaque' => 'a whole mapping document; its shape is owned by format.schema.json, and declaring it twice would be the drift this contract exists to prevent' ],
            'actor' => [ 'type' => 'string' ],
        ],
        'createPartner' => [
            'name' => [ 'type' => 'string', 'required' => true ],
            'kind' => [ 'type' => 'string' ],
            'vatId' => [ 'type' => 'string' ],
            'paymentTermsDays' => [ 'type' => 'integer' ],
            'accountNumbers' => [
                'type' => 'array',
                'element' => [ 'type' => 'string' ],
            ],
            'address' => [ 'type' => 'object', 'opaque' => 'free-form master data; the engine stores it whole and interprets no key of it' ],
            'actor' => [ 'type' => 'string' ],
        ],
        'deactivatePartner' => [
            'partnerId' => [ 'type' => 'string', 'required' => true ],
            'actor' => [ 'type' => 'string' ],
        ],
        'reactivatePartner' => [
            'partnerId' => [ 'type' => 'string', 'required' => true ],
            'actor' => [ 'type' => 'string' ],
        ],
        'updatePartner' => [
            'partnerId' => [ 'type' => 'string', 'required' => true ],
            'name' => [ 'type' => 'string' ],
            'kind' => [ 'type' => 'string' ],
            'vatId' => [ 'type' => 'string' ],
            'paymentTermsDays' => [ 'type' => 'integer' ],
            'accountNumbers' => [
                'type' => 'array',
                'element' => [ 'type' => 'string' ],
            ],
            'address' => [ 'type' => 'object', 'opaque' => 'free-form master data; the engine stores it whole and interprets no key of it' ],
            'actor' => [ 'type' => 'string' ],
        ],
        'erasePartner' => [
            'partnerId' => [ 'type' => 'string', 'required' => true ],
            'actor' => [ 'type' => 'string' ],
        ],
        'acquireAsset' => [
            'name' => [ 'type' => 'string' ],
            'assetClass' => [ 'type' => 'string' ],
            'assetAccount' => [ 'type' => 'string', 'required' => true ],
            'acquisitionCost' => [ 'type' => 'money', 'required' => true ],
            'acquiredOn' => [ 'type' => 'date', 'required' => true ],
            'voucherId' => [ 'type' => 'string', 'required' => true ],
            'gwgChoice' => [ 'type' => 'string' ],
            'usefulLifeMonths' => [ 'type' => 'integer' ],
            'depreciationMethod' => [ 'type' => 'string' ],
            'dimensions' => [
                'type' => 'array',
                'element' => [
                    'type' => 'object',
                    'fields' => [
                        'type' => [ 'type' => 'string' ],
                        'code' => [ 'type' => 'string' ],
                    ],
                ],
            ],
            'specialDepreciation' => [ 'type' => 'boolean' ],
            'totalUnits' => [ 'type' => 'integer' ],
            'actor' => [ 'type' => 'string' ],
        ],
        'disposeAsset' => [
            'assetId' => [ 'type' => 'string', 'required' => true ],
            'disposedOn' => [ 'type' => 'date', 'required' => true ],
            'proceeds' => [ 'type' => 'money' ],
            'proceedsAccount' => [ 'type' => 'string' ],
            'bankAccount' => [ 'type' => 'string' ],
            'voucherId' => [ 'type' => 'string' ],
            'actor' => [ 'type' => 'string' ],
        ],
        'runDepreciation' => [
            'fiscalYear' => [ 'type' => 'integer', 'required' => true ],
            'period' => [ 'type' => 'integer' ],
            'actor' => [ 'type' => 'string' ],
        ],
        'valuateInventory' => [
            'fiscalYear' => [ 'type' => 'integer', 'required' => true ],
            'period' => [ 'type' => 'integer', 'required' => true ],
            'valuationDate' => [ 'type' => 'date', 'required' => true ],
            'runId' => [ 'type' => 'string' ],
            'producedQuantity' => [ 'type' => 'string' ],
            'categories' => [
                'type' => 'array',
                'required' => true,
                'element' => [
                    'type' => 'object',
                    'fields' => [
                        'account' => [ 'type' => 'string' ],
                        'quantity' => [ 'type' => 'string' ],
                        'unitCost' => [ 'type' => 'string' ],
                        'marketValue' => [ 'type' => 'string' ],
                    ],
                ],
            ],
            'actor' => [ 'type' => 'string' ],
        ],
        'recognizeProvision' => [
            'account' => [ 'type' => 'string', 'required' => true ],
            'reason' => [ 'type' => 'string', 'required' => true ],
            'amount' => [ 'type' => 'money', 'required' => true ],
            'recognizedOn' => [ 'type' => 'date', 'required' => true ],
            'dueDate' => [ 'type' => 'date' ],
            'discountRate' => [ 'type' => 'string' ],
            'actor' => [ 'type' => 'string' ],
        ],
        'useProvision' => [
            'provisionId' => [ 'type' => 'string', 'required' => true ],
            'amount' => [ 'type' => 'money', 'required' => true ],
            'settlementAccount' => [ 'type' => 'string', 'required' => true ],
            'date' => [ 'type' => 'date', 'required' => true ],
            'actor' => [ 'type' => 'string' ],
        ],
        'releaseProvision' => [
            'provisionId' => [ 'type' => 'string', 'required' => true ],
            'amount' => [ 'type' => 'money' ],
            'date' => [ 'type' => 'date', 'required' => true ],
            'actor' => [ 'type' => 'string' ],
        ],
        'remeasureProvision' => [
            'provisionId' => [ 'type' => 'string', 'required' => true ],
            'amount' => [ 'type' => 'money', 'required' => true ],
            'date' => [ 'type' => 'date', 'required' => true ],
            'discountRate' => [ 'type' => 'string' ],
            'actor' => [ 'type' => 'string' ],
        ],
        'recognizeDeferral' => [
            'kind' => [ 'type' => 'string', 'required' => true ],
            'reason' => [ 'type' => 'string', 'required' => true ],
            'counterAccount' => [ 'type' => 'string', 'required' => true ],
            'amount' => [ 'type' => 'money', 'required' => true ],
            'recognizedOn' => [ 'type' => 'date', 'required' => true ],
            'firstFiscalYear' => [ 'type' => 'integer', 'required' => true ],
            'firstPeriod' => [ 'type' => 'integer', 'required' => true ],
            'periods' => [ 'type' => 'integer', 'required' => true ],
            'actor' => [ 'type' => 'string' ],
        ],
        'runDeferralRelease' => [
            'fiscalYear' => [ 'type' => 'integer', 'required' => true ],
            'period' => [ 'type' => 'integer', 'required' => true ],
            'actor' => [ 'type' => 'string' ],
        ],
        'reportAssetUsage' => [
            'assetId' => [ 'type' => 'string', 'required' => true ],
            'fiscalYear' => [ 'type' => 'integer', 'required' => true ],
            'units' => [ 'type' => 'integer', 'required' => true ],
            'voucherId' => [ 'type' => 'string' ],
            'actor' => [ 'type' => 'string' ],
        ],
        'bookSpecialDepreciation' => [
            'assetId' => [ 'type' => 'string', 'required' => true ],
            'fiscalYear' => [ 'type' => 'integer', 'required' => true ],
            'amount' => [ 'type' => 'money', 'required' => true ],
            'voucherId' => [ 'type' => 'string' ],
            'actor' => [ 'type' => 'string' ],
        ],
        'writeDownAsset' => [
            'assetId' => [ 'type' => 'string', 'required' => true ],
            'amount' => [ 'type' => 'money', 'required' => true ],
            'date' => [ 'type' => 'date', 'required' => true ],
            'reason' => [ 'type' => 'string', 'required' => true ],
            'voucherId' => [ 'type' => 'string' ],
            'actor' => [ 'type' => 'string' ],
        ],
        'writeUpAsset' => [
            'assetId' => [ 'type' => 'string', 'required' => true ],
            'amount' => [ 'type' => 'money', 'required' => true ],
            'date' => [ 'type' => 'date', 'required' => true ],
            'reason' => [ 'type' => 'string', 'required' => true ],
            'voucherId' => [ 'type' => 'string' ],
            'actor' => [ 'type' => 'string' ],
        ],
        'setAllocationScheme' => [
            'method' => [ 'type' => 'string' ],
            'steps' => [
                'type' => 'array',
                'element' => [
                    'type' => 'object',
                    'fields' => [
                        'sender' => [ 'type' => 'string' ],
                        'receivers' => [
                            'type' => 'array',
                            'element' => [
                                'type' => 'object',
                                'fields' => [
                                    'code' => [ 'type' => 'string' ],
                                    'share' => [ 'type' => 'string' ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'rates' => [
                'type' => 'array',
                'element' => [
                    'type' => 'object',
                    'fields' => [
                        'costCenter' => [ 'type' => 'string' ],
                        'label' => [ 'type' => 'string' ],
                        'base' => [
                            'type' => 'object',
                            'fields' => [
                                'accounts' => [
                                    'type' => 'array',
                                    'element' => [ 'type' => 'string' ],
                                ],
                                'costCenters' => [
                                    'type' => 'array',
                                    'element' => [ 'type' => 'string' ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'productionCost' => [
                'type' => 'object',
                'fields' => [
                    'include' => [
                        'type' => 'array',
                        'element' => [ 'type' => 'string' ],
                    ],
                    'components' => [
                        'type' => 'array',
                        'element' => [
                            'type' => 'object',
                            'fields' => [
                                'id' => [ 'type' => 'string' ],
                                'base' => [
                                    'type' => 'object',
                                    'fields' => [
                                        'accounts' => [
                                            'type' => 'array',
                                            'element' => [ 'type' => 'string' ],
                                        ],
                                        'costCenters' => [
                                            'type' => 'array',
                                            'element' => [ 'type' => 'string' ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'actor' => [ 'type' => 'string' ],
        ],
        'runCosting' => [
            'fiscalYear' => [ 'type' => 'integer', 'required' => true ],
            'period' => [ 'type' => 'integer', 'required' => true ],
            'actor' => [ 'type' => 'string' ],
        ],
        'releaseCosting' => [
            'runId' => [ 'type' => 'string', 'required' => true ],
            'actor' => [ 'type' => 'string' ],
        ],
        'allocate' => [
            'total' => [ 'type' => 'money', 'required' => true ],
            'weights' => [
                'type' => 'array',
                'required' => true,
                'element' => [ 'type' => 'string' ],
            ],
        ],
    ];

    /**
     * Checks an operation's input against the contract — once, at the dispatcher, instead of
     * inside 31 handlers.
     *
     * The rules are the projections' rules, deliberately: an undeclared key is a caller mistake
     * rather than something to ignore, and a declared key present with the wrong type is rejected
     * rather than coerced. `null` counts as absent, because JSON callers and CLI wrappers write an
     * omitted input both ways and every handler has read it that way since long before this
     * existed.
     *
     * An operation the table does not know is left alone: an unknown name is the dispatcher's own
     * error (E_NOT_IMPLEMENTED) and must not be reported as an input problem on the way there.
     * OperationParametersTest holds the table's key set against API_OPERATIONS, so "not known
     * here" cannot quietly mean "not checked".
     *
     * @param array<string, mixed> $input
     */
    public static function validate(string $op, array $input): void
    {
        $declared = self::OPERATIONS[$op] ?? null;
        if ($declared === null) {
            return;
        }

        self::validateFields($op, $input, $declared, '');
    }

    /**
     * The two rules, applied to one level of a structure.
     *
     * They are the same two the outer level has always had — an undeclared key is a caller mistake,
     * a declared key of the wrong type is rejected rather than coerced — and applying them at every
     * declared depth is the whole of SPEC-017. Requiredness is deliberately NOT checked here: it
     * stays with the operation, whose own error says more than a generic one (`post` refuses a line
     * without an account with its own code, naming the line).
     *
     * @param array<string, mixed> $value
     * @param array<string, array<string, mixed>> $fields
     */
    private static function validateFields(string $op, array $value, array $fields, string $path): void
    {
        foreach ($value as $key => $item) {
            $spec = $fields[$key] ?? null;
            $here = $path === '' ? (string) $key : $path . '.' . $key;

            if (!is_array($spec)) {
                throw new DomainError(
                    'E_INPUT_INVALID',
                    sprintf('%s: unknown input "%s"', $op, $here),
                    ['input' => $here],
                );
            }

            if ($item === null) {
                continue;
            }

            self::validateValue($op, $item, $spec, $here);
        }
    }

    /**
     * One declared value: its type, and — where the declaration goes deeper — what is inside it.
     *
     * The recursion stops where the declaration stops, which is the property that makes this
     * durable rather than "one level deeper": a shape that is not written out is not checked, and
     * that is a decision the contract's author makes visibly. `opaque` is the same statement with a
     * reason attached, for a structure another schema owns.
     *
     * @param array<string, mixed> $spec
     */
    private static function validateValue(string $op, mixed $value, array $spec, string $path): void
    {
        $type = is_string($spec['type'] ?? null) ? $spec['type'] : 'string';

        if (!Parameters::matchesType($value, $type)) {
            throw new DomainError(
                'E_INPUT_INVALID',
                sprintf('%s: input "%s" must be of type %s', $op, $path, $type),
                [$path => DomainError::rejectedValue($value)],
            );
        }

        if (isset($spec['opaque'])) {
            return;
        }

        if ($type === 'object' && is_array($spec['fields'] ?? null) && is_array($value)) {
            /** @var array<string, array<string, mixed>> $fields */
            $fields = $spec['fields'];
            /** @var array<string, mixed> $value */
            self::validateFields($op, $value, $fields, $path);

            return;
        }

        if ($type === 'array' && is_array($spec['element'] ?? null) && is_array($value)) {
            /** @var array<string, mixed> $element */
            $element = $spec['element'];
            foreach (array_values($value) as $index => $item) {
                if ($item === null) {
                    continue;
                }

                self::validateValue($op, $item, $element, sprintf('%s[%d]', $path, $index));
            }
        }
    }
}
