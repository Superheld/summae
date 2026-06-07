<?php

declare(strict_types=1);

namespace Rechnungswesen\Runner\Subject;

/**
 * Liefert je Fixture-Lauf ein frisches, isoliertes Subject.
 * Zweite Implementierung (Eloquent-Adapter) kommt mit JOB-012.
 */
interface SubjectFactory
{
    public function create(): Subject;
}
