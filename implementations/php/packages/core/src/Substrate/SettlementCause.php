<?php

declare(strict_types=1);

namespace Summae\Core\Substrate;

/**
 * Why an open item was settled (IMPL-008). `Payment` is the ordinary case and the default when the
 * field is absent; `Cancellation` arises only from `reverse` and means the item is done because its
 * origin entry was reversed — no money moved. Without the distinction a reversal is indistinguishable
 * from a payment, and cash-basis VAT would declare tax for money that never arrived.
 */
enum SettlementCause: string
{
    case Payment = 'payment';
    case Cancellation = 'cancellation';

    public static function parse(mixed $value): self
    {
        return $value === 'cancellation' ? self::Cancellation : self::Payment;
    }
}
