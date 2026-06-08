<?php

declare(strict_types=1);

namespace Rechnungswesen\Cli;

/**
 * Exit-Codes = Fehlercodes (api.md F-IO-003): stabile numerische
 * Abbildung des Fehlerkatalogs. 0 = Erfolg, 1 = unbekannter Fehler.
 * Reihenfolge ist append-only — Umsortieren wäre ein Breaking Change.
 */
final class ExitCodes
{
    /** @var list<string> */
    private const array CODES = [
        'E_ENTRY_UNBALANCED',
        'E_ENTRY_NO_VOUCHER',
        'E_VOUCHER_UNKNOWN',
        'E_ENTRY_TOO_FEW_LINES',
        'E_ENTRY_INVALID_AMOUNT',
        'E_ENTRY_FINALIZED',
        'E_ENTRY_ALREADY_REVERSED',
        'E_ENTRY_UNKNOWN',
        'E_PERIOD_CLOSED',
        'E_PERIOD_OUT_OF_ORDER',
        'E_PERIOD_UNKNOWN',
        'E_FISCALYEAR_CLOSED',
        'E_FISCALYEAR_UNFINALIZED_ENTRIES',
        'E_FISCALYEAR_OVERLAP',
        'E_ACCOUNT_UNKNOWN',
        'E_ACCOUNT_LOCKED',
        'E_ACCOUNT_NUMBER_TAKEN',
        'E_COA_FORMAT_INVALID',
        'E_SETTLEMENT_EXCEEDS_ITEM',
        'E_SETTLEMENT_DIFFERENCE_INVALID',
        'E_OPENITEM_UNKNOWN',
        'E_CASHBASIS_DEVIATING_FISCAL_YEAR',
        'E_TAXCODE_UNKNOWN',
        'E_TAXCODE_NO_VALID_VERSION',
        'E_PROFILE_RETROACTIVE_CONFLICT',
        'E_PROFILE_UNKNOWN',
        'E_PARTNER_UNKNOWN',
        'E_DIMENSION_INVALID',
        'E_ASSET_UNKNOWN',
        'E_ASSET_DISPOSED',
        'E_COSTING_RUN_RELEASED',
        'E_COSTING_RUN_UNKNOWN',
        'E_COSTING_CYCLE',
        'E_MAPPING_OVERLAP',
        'E_NOT_IMPLEMENTED',
    ];

    private function __construct()
    {
    }

    public static function for(string $errorCode): int
    {
        $index = array_search($errorCode, self::CODES, true);

        return $index === false ? 1 : $index + 10;
    }
}
