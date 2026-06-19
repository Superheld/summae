<?php

declare(strict_types=1);

namespace Summae\Runner\Subject;

/**
 * Liefert je Fixture-Lauf ein frisches, isoliertes Subject.
 * Zweite Implementierung (Database-Adapter) kommt mit JOB-012.
 */
interface SubjectFactory
{
    public function create(): Subject;
}
