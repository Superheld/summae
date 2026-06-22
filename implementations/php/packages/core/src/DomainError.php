<?php

declare(strict_types=1);

namespace Summae\Core;

/**
 * Domain error with catalog code (fehlerkatalog.md). Contract part:
 * same violation -> same code in all implementations.
 * `message` is free-form, `details` carries the IDs/values involved.
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
