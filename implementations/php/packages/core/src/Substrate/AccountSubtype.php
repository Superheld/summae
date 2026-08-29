<?php

declare(strict_types=1);

namespace Summae\Core\Substrate;

/**
 * The canonical subtypes an account may carry.
 *
 * **Why this is closed.** `subtype` is the field through which the chart tells the engine what an
 * account *is*: which movements are profit-neutral, which account is a tax account and on which
 * side its tax stands, which posting opens a receivable. The field was a free string, so a pack
 * that wrote `tax-out` instead of `tax_out` produced an account that looked annotated and was
 * inert — the VAT return simply skipped it, and nothing in the output said a tax account had gone
 * missing. That is the same defect the tax mechanisms were closed for in v0.8.0 (`reverse-charge`
 * fell back to plain VAT under the ordinary reporting key) and the one `PartnerKind` was closed for
 * (`custommer` was a partner kind like any other). Third time, same shape, same answer.
 *
 * **The stored value stays a string.** This enum is the validator, not a change to the data format
 * — exactly as `PartnerKind` is. Nothing here changes what an export writes.
 *
 * **Where it is enforced, and deliberately where it is not.** At the two boundaries where a subtype
 * is *authored*: `createAccount`/`importChartOfAccounts` (`E_INPUT_INVALID` /
 * `E_COA_FORMAT_INVALID`) and pack resolution (`E_PACK_INCOHERENT`, so a composed pack fails at
 * `resolvePack` rather than at the first posting). It is **not** enforced in the `Account`
 * constructor, and that is not an oversight: hydrating a stored account runs through the same
 * constructor, so a database written before this repertoire existed would stop loading. A
 * validation that refuses to read what it once wrote is a worse failure than the one it prevents.
 *
 * **Two tiers, one list.** Ten of these the engine reads and branches on; `fixed_asset`,
 * `opening_balance` and `private` are annotation that every shipped pack carries and no code
 * consults. They are in the repertoire because the packs use them, and keeping them here is what
 * makes the list safe to check against — a repertoire that only held the readable ones would refuse
 * the three shipped charts.
 *
 * **Two values were added on 2026-08-29 — `inventory` and `provision` — and both are the case this
 * enum named as the one that would reopen it:** *a pack needing an account role the engine genuinely does not have*. Stock
 * was that role. It arrives the way the note said it should — a registered value with a reader, not
 * a free string again — and the reader is `InventoryService`, which refuses to value stock onto an
 * account that is not one. Without it the figure would balance, satisfy every invariant, and land
 * in the wrong balance-sheet position.
 */
enum AccountSubtype: string
{
    /** Read: cash-basis (profit-neutral movement), payment account. */
    case Bank = 'bank';
    /** Read: cash journal (F-CORE-030), cash-basis. */
    case Cash = 'cash';
    /** Read: cash-basis — money in transit is not a profit event. */
    case Transit = 'transit';
    /** Read: a debit opens a receivable. */
    case AccountsReceivable = 'ar';
    /** Read: a credit opens a payable. */
    case AccountsPayable = 'ap';
    /** Read: VAT return (input side), cash-basis, DATEV export. */
    case TaxIn = 'tax_in';
    /** Read: VAT return (output side), cash-basis, DATEV export. */
    case TaxOut = 'tax_out';
    /** Read: where an appropriated result lands (F-CORE-038). */
    case ResultAllocation = 'result_allocation';
    /** Read: stock, the only account `valuateInventory` may value onto (F-CORE-050). */
    case Inventory = 'inventory';
    /** Read: provisions, the only account `recognizeProvision` may form one on (F-CORE-051). */
    case Provision = 'provision';
    /** Annotation: the packs mark their asset accounts; the asset expansion uses its own module. */
    case FixedAsset = 'fixed_asset';
    /** Annotation: the opening-balance account of a chart. */
    case OpeningBalance = 'opening_balance';
    /** Annotation: owner's drawings and contributions. */
    case Private = 'private';

    /**
     * The repertoire, in declaration order. Published for the same reason
     * `TaxMechanisms::all()` is: a document that names the subtypes — `docs/handbuch` and the
     * pack docs do — is making a checkable claim, and a claim nothing checks goes stale.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    public static function isKnown(string $value): bool
    {
        return self::tryFrom($value) !== null;
    }
}
