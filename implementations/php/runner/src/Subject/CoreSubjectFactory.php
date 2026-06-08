<?php

declare(strict_types=1);

namespace Rechnungswesen\Runner\Subject;

final class CoreSubjectFactory implements SubjectFactory
{
    public function create(): Subject
    {
        return new CoreSubject();
    }
}
