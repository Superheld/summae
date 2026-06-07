<?php

declare(strict_types=1);

namespace Rechnungswesen\Runner\Subject;

/**
 * Subject über rechnungswesen/core mit In-Memory-Port.
 *
 * JOB-002-Stand: reines Gerüst — jede Operation meldet kontrolliert
 * E_NOT_IMPLEMENTED (rot, nicht crash). Wird ab JOB-003 Operation für
 * Operation mit dem Kern verdrahtet.
 */
final class CoreSubject implements Subject
{
    /** @var array<string, mixed> */
    private array $setup = [];

    public function setup(array $setup): void
    {
        // Ab JOB-003: In-Memory-Mandant aus tenant/accounts/fiscalYears/… bauen.
        $this->setup = $setup;
    }

    public function execute(string $op, array $input): array
    {
        throw new SubjectError('E_NOT_IMPLEMENTED', sprintf(
            'Operation "%s" ist noch nicht implementiert (setup-Schlüssel: %s)',
            $op,
            implode(',', array_keys($this->setup)),
        ));
    }

    public function project(string $name, array $params): array
    {
        throw new SubjectError('E_NOT_IMPLEMENTED', sprintf(
            'Projektion "%s" ist noch nicht implementiert',
            $name,
        ));
    }
}
