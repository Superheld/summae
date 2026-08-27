<?php

declare(strict_types=1);

namespace Summae\Core\Policies\Projection;

use Summae\Core\Composition\TenantConfigStore;
use Summae\Core\Ledger\AuditWriter;
use Summae\Core\Substrate\Uuid;

/**
 * `setEntityProfile` — the write side of the registry (F-CORE-039).
 *
 * Three steps in a fixed order, the same order every configuration operation here uses: the
 * registry refuses first, the audit record is written second, the store third. A rejected call
 * therefore leaves no trail and no record — which is the discipline SPEC-015 had to invent after
 * five operations audited changes the books stopped carrying at the next restart.
 *
 * The SAME shape lives in the Node legal-forms.ts.
 */
final readonly class EntityProfileService
{
    public function __construct(
        private LegalFormRegistry $registry,
        private AuditWriter $audit,
        private Uuid $tenantId,
        private ?TenantConfigStore $configStore,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function set(array $input): array
    {
        $before = $this->registry->declared();
        $profile = $this->registry->set($input['legalForm'] ?? null, $input['sizeClass'] ?? null);

        $this->audit->record($this->audit->actorOf($input), 'entityProfile', $this->tenantId, 'changed', [
            'legalForm' => ['from' => $before === null ? null : $before['legalForm'], 'to' => $profile['legalForm']],
            'sizeClass' => ['from' => $before === null ? null : $before['sizeClass'], 'to' => $profile['sizeClass']],
        ]);
        $this->configStore?->rememberEntityProfile($profile);

        return [
            'legalForm' => $profile['legalForm'],
            'label' => $this->registry->label(),
            'sizeClass' => $profile['sizeClass'],
            'resolutionRequired' => $this->registry->resolution()['required'] ?? false,
            'resolutionDeadlineMonths' => $this->registry->deadlineMonths(),
        ];
    }
}
