<?php

declare(strict_types=1);

namespace Summae\Cli;

/**
 * Exit codes = error codes (api.md F-IO-003): stable numeric
 * mapping of the error catalog. 0 = success, 1 = unknown error.
 * Order is append-only — reordering would be a breaking change.
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
        // Appended 2026-08-15. A supplied parameter or field is present but not valid input —
        // a caller mistake, not an internal failure. Before this code the same situations either
        // escaped as an uncaught InvalidValue (stack trace, then E_UNEXPECTED/exit 1, indistinguishable
        // from a summae bug) or were silently coerced into a plausible default.
        'E_INPUT_INVALID',
        // Appended 2026-08-16. The workspace file is readable but a required field is missing or
        // unusable. Before this code every field fell back to a default and a missing tenantId was
        // regenerated, so a damaged summae.json opened the same database under a different identity
        // and reported an empty ledger — indistinguishable from books never written (R-9).
        'E_WORKSPACE_INVALID',
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
