<?php

declare(strict_types=1);

namespace Summae\Core\Substrate\Exception;

/**
 * Invalid value for a value object of the shared kernel.
 * Programming-error level — business error codes (E_*) arise
 * only at the operations of the ledger (fehlerkatalog.md).
 */
final class InvalidValue extends \InvalidArgumentException
{
}
