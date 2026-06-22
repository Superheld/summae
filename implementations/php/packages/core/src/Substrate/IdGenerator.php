<?php

declare(strict_types=1);

namespace Summae\Core\Substrate;

/**
 * ID source of the core — port, so that tests and determinism runs
 * can control generation. Production: UUIDv7.
 */
interface IdGenerator
{
    public function next(): Uuid;
}
