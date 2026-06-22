<?php

declare(strict_types=1);

namespace Summae\Runner\Subject;

/**
 * Domain error with catalog code (fehlerkatalog.md).
 * The runner compares the code exactly against expect.error.
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
