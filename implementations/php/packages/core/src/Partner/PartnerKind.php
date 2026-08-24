<?php

declare(strict_types=1);

namespace Summae\Core\Partner;

/**
 * What a business partner is to this tenant.
 *
 * The manual has named these three since the partner record existed; the field was a plain string
 * that took anything, so `custommer` was a partner kind like any other and only surfaced as a
 * category nothing could filter on. The stored value stays a string — this enum is the validator,
 * not a change to the data format.
 */
enum PartnerKind: string
{
    case Customer = 'customer';
    case Supplier = 'supplier';
    case Both = 'both';
}
