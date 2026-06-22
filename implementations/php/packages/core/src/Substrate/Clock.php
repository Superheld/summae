<?php

declare(strict_types=1);

namespace Summae\Core\Substrate;

/**
 * Time source of the core. Deliberately a dedicated interface instead of psr/clock:
 * the core has exactly one dependency (brick/math, RUNTIME-LEITFADEN).
 * The signature is PSR-20-compatible, an adapter is trivial.
 */
interface Clock
{
    public function now(): \DateTimeImmutable;
}
