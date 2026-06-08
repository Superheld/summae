<?php

declare(strict_types=1);

namespace Rechnungswesen\Core\Shared\Exception;

/**
 * Ungültiger Wert für ein Value Object des Shared Kernel.
 * Programmierfehler-Ebene — fachliche Fehlercodes (E_*) entstehen
 * erst an den Operationen des Ledgers (fehlerkatalog.md).
 */
final class InvalidValue extends \InvalidArgumentException
{
}
