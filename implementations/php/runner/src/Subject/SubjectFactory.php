<?php

declare(strict_types=1);

namespace Summae\Runner\Subject;

/**
 * Provides a fresh, isolated subject per fixture run.
 * Second implementation (database adapter) comes with JOB-012.
 */
interface SubjectFactory
{
    public function create(): Subject;
}
