<?php

declare(strict_types=1);

namespace Rechnungswesen\Core\Shared;

/**
 * ID-Quelle des Kerns — Port, damit Tests und Determinismus-Läufe
 * die Erzeugung kontrollieren können. Produktion: UUIDv7.
 */
interface IdGenerator
{
    public function next(): Uuid;
}
