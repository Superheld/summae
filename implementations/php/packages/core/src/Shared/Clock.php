<?php

declare(strict_types=1);

namespace Rechnungswesen\Core\Shared;

/**
 * Zeitquelle des Kerns. Bewusst eigenes Interface statt psr/clock:
 * der Kern hat genau eine Abhängigkeit (brick/math, AGENT-BRIEFING).
 * Signatur ist PSR-20-kompatibel, ein Adapter ist trivial.
 */
interface Clock
{
    public function now(): \DateTimeImmutable;
}
