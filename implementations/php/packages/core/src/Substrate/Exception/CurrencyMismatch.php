<?php

declare(strict_types=1);

namespace Summae\Core\Substrate\Exception;

/**
 * Calculating across currency boundaries is a programming error, not a business error:
 * v1 knows exactly one tenant currency (foreign currency is v2, fields reserved).
 */
final class CurrencyMismatch extends \LogicException
{
}
