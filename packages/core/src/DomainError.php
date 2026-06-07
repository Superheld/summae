<?php

declare(strict_types=1);

namespace Rechnungswesen\Core;

/**
 * Fachlicher Fehler mit Katalog-Code (fehlerkatalog.md). Vertragsteil:
 * gleicher Verstoß -> gleicher Code in allen Implementierungen.
 * `message` ist frei formulierbar, `details` trägt beteiligte IDs/Werte.
 */
final class DomainError extends \RuntimeException
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        public readonly string $errorCode,
        string $message = '',
        public readonly array $details = [],
    ) {
        parent::__construct($message !== '' ? $message : $errorCode);
    }
}
