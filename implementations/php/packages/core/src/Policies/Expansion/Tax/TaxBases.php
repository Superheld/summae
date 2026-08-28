<?php

declare(strict_types=1);

namespace Summae\Core\Policies\Expansion\Tax;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Summae\Core\DomainError;
use Summae\Core\Substrate\Currency;
use Summae\Core\Substrate\Money;

/**
 * How a taxable amount splits into base and tax — the **second seam** of the tax expansion
 * (F-TAX-010).
 *
 * **Why this is its own socket and not a fifth mechanism.** The mechanism seam covers *line
 * assembly*: it receives an already-computed, already-rounded tax amount and decides which accounts
 * and reporting keys it lands on. core/src/CLAUDE.md has said since 2026-08-16 that the variance
 * which actually differs between jurisdictions sits **before** that and had no socket at all —
 * `base x rate / 100` was written twice inside TaxService, once per rounding granularity, and a pack
 * could not reach it. Every tax system that quotes prices with the tax already inside was therefore
 * inexpressible, and that is most of them.
 *
 * **The two kinds, and what separates them.**
 *
 * - `net` — the amount handed in is the base. `tax = amount x rate / 100`. What summae has always
 *   done, and the default when a tax code says nothing, so no shipped pack changes behaviour.
 * - `inclusive` — the amount handed in is the **gross**, tax already inside.
 *   `tax = amount x rate / (100 + rate)`, and the base is what remains. Rounding happens once, on
 *   the tax, and the base is derived by subtraction — the other order lets base and tax fail to add
 *   up to the amount the caller actually posted, which is the one property an inclusive regime
 *   cannot give up: the gross is a fact, the split is arithmetic.
 *
 * **What this seam deliberately does not reach**, because a socket's limits are part of its
 * contract. A *compound* base (Canadian PST computed on a GST-inclusive amount) needs the result of
 * another code and therefore an ordering between codes, which this function cannot see — it is
 * handed one amount and one rate. Tax at payment time (withholding, split payment) is not a base
 * question at all but a timing one, and margin schemes need the purchase price of the thing sold,
 * which is not in the posting. Those stay named and unbuilt; the repertoire question
 * (core/src/CLAUDE.md, "what would reopen it") is *not* settled by this change, because a mechanism
 * still is not describable as pure data.
 */
final class TaxBases
{
    public const NET = 'net';
    public const INCLUSIVE = 'inclusive';

    private function __construct()
    {
    }

    /**
     * The declared repertoire, for the contract test and for tenantConfiguration.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::NET, self::INCLUSIVE];
    }

    public static function isKind(mixed $value): bool
    {
        return is_string($value) && in_array($value, self::all(), true);
    }

    /**
     * @return array{base: Money, tax: Money}
     */
    public static function split(string $kind, Money $amount, string $rate, Currency $currency): array
    {
        if ($kind === self::NET) {
            return [
                'base' => $amount,
                'tax' => Money::fromCalculation(
                    BigDecimal::of($amount->amountAsString())
                        ->multipliedBy(BigDecimal::of($rate))
                        ->dividedBy(100, 10, RoundingMode::Unnecessary),
                    $currency,
                ),
            ];
        }

        $divisor = BigDecimal::of('100')->plus($rate);
        if ($divisor->isZero()) {
            // A rate of -100 % would make the gross zero for any base, so no split exists. Refused
            // rather than divided: a division by zero would surface far from the pack that caused it.
            throw new DomainError(
                'E_TAXCODE_INVALID',
                'an inclusive tax base needs a rate other than -100',
                ['rate' => $rate],
            );
        }

        // Ten decimals then the shared half-up rule, exactly as the net path does — the scale is
        // where a cross-language difference would hide, so both seams use the same one.
        $tax = Money::fromCalculation(
            BigDecimal::of($amount->amountAsString())
                ->multipliedBy(BigDecimal::of($rate))
                ->dividedBy($divisor, 10, RoundingMode::HalfUp),
            $currency,
        );

        return ['base' => $amount->subtract($tax), 'tax' => $tax];
    }
}
