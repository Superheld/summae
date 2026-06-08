<?php

declare(strict_types=1);

namespace Summae\Runner\Subject;

/**
 * Fachlicher Fehler mit Katalog-Code (fehlerkatalog.md).
 * Der Runner vergleicht den Code exakt gegen expect.error.
 */
final class SubjectError extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message = '',
    ) {
        parent::__construct($message !== '' ? $message : $errorCode);
    }
}
