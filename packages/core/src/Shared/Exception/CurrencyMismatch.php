<?php

declare(strict_types=1);

namespace Rechnungswesen\Core\Shared\Exception;

/**
 * Rechnen über Währungsgrenzen ist ein Programmierfehler, kein Fachfehler:
 * v1 kennt genau eine Mandantenwährung (Fremdwährung ist v2, Felder reserviert).
 */
final class CurrencyMismatch extends \LogicException
{
}
