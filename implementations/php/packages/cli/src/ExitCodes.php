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
        // Appended 2026-08-16 (R-3): correcting the LINES of an entry that produced open items
        // would leave the subledger describing a posting that no longer exists.
        'E_ENTRY_HAS_OPEN_ITEMS',
        // Appended 2026-08-16 (IMPL-008): reversing an entry whose open item already carries a
        // settlement would drop money that actually moved out of the open-item history.
        'E_ENTRY_HAS_SETTLED_ITEMS',
        // Appended 2026-08-16 (IMPL-018). These five were in the error catalogue and thrown by the
        // core, but not in this list — so they exited 1, the code that means "unknown error", and
        // a script branching on the exit could not tell them from a summae crash. It hit every
        // `summae init --pack …` (the three pack codes) and every settlement that over-claims its
        // entry.
        'E_SETTLEMENT_EXCEEDS_ENTRY',
        'E_PACK_UNRESOLVED_REF',
        'E_PACK_INCOHERENT',
        'E_POLICY_INVALID',
        // Declared in the catalogue, not yet thrown anywhere (its fixture is still open). Mapped
        // regardless: reserving the number costs nothing, and it lets ExitCodesTest demand the
        // catalogue *without an exception list* — an exception list is the hole IMPL-018 came
        // through.
        'E_AMOUNT_SCALE_MISMATCH',
        // Appended 2026-08-23. The simultaneous-equation method solves the whole allocation scheme at
        // once; a scheme in which a group of cost centres passes everything among themselves and never
        // reaches one that keeps it has no solution at all. Refused, because there is no number to give
        // — cost that circulates forever is a modelling mistake, not a small residue.
        'E_COSTING_UNSOLVABLE',
        // Appropriation of profit (v0.14.0). Two refusals that must not be confused: the pack does
        // not offer this operation or this target at all, versus the books do not carry the amount.
        // The first is answered by configuration, the second only by posting differently.
        'E_APPROPRIATION_UNSUPPORTED',
        'E_APPROPRIATION_EXCEEDS_RESULT',
        // Erasure of a partner (v0.15.1, F-CORE-040). Not "unknown partner" — the partner exists
        // and is kept on purpose, because a voucher or an open item names it and the retention duty
        // outranks the right to erasure. A caller that cannot tell the two apart cannot tell the
        // data subject why.
        'E_PARTNER_IN_USE',
        // The constraint socket's second predicate (v0.15.1, F-CORE-042). Two codes rather than one
        // with a discriminator: a script branches on the exit code, and "you are missing a line" and
        // "you have one line too many" call for opposite corrections.
        'E_COMBINATION_REQUIRED',
        'E_COMBINATION_FORBIDDEN',
        // Appended 2026-08-28 (F-CORE-045). Not a lock: this one is about the POSTING's date, so a
        // caller can tell "unlock the account" from "your date is wrong" — the two need opposite
        // corrections, which is the same argument the two combination codes were split for.
        'E_ACCOUNT_NOT_VALID_AT_DATE',
        'E_ACCOUNT_USE_FORBIDDEN',
        // Stock (F-CORE-050). Two codes rather than one because a script reacts oppositely: a run
        // that is merely a draft gets released, an account that is not a stock account gets
        // replaced. Appended, never reordered — the position IS the exit code.
        'E_COSTING_RUN_NOT_RELEASED',
        'E_INVENTORY_ACCOUNT_INVALID',
        // Provisions (F-CORE-051). Four codes because a caller reacts differently to each: find
        // the right id, name a provision account, release less, or supply the rate.
        'E_PROVISION_UNKNOWN',
        'E_PROVISION_ACCOUNT_INVALID',
        'E_PROVISION_EXCEEDS_CARRYING',
        'E_PROVISION_DISCOUNT_RATE_REQUIRED',
    ];

    private function __construct()
    {
    }

    public static function for(string $errorCode): int
    {
        $index = array_search($errorCode, self::CODES, true);

        return $index === false ? 1 : $index + 10;
    }

    /**
     * Every code that has an exit code, in mapping order. The list is a published contract, so
     * reading it is legitimate — ExitCodesTest compares it against the error catalogue in both
     * directions, which is how a code that lives here but nowhere in the catalogue (as
     * `E_NOT_IMPLEMENTED` did) stops being invisible. Node twin: `allExitCodes()`.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return self::CODES;
    }
}
