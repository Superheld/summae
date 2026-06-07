<?php

declare(strict_types=1);

namespace Rechnungswesen\Core\Partner;

use Rechnungswesen\Core\DomainError;
use Rechnungswesen\Core\Ledger\AuditRecord;
use Rechnungswesen\Core\Port\AuditTrail;
use Rechnungswesen\Core\Port\PartnerRepository;
use Rechnungswesen\Core\Shared\Clock;
use Rechnungswesen\Core\Shared\Exception\InvalidValue;
use Rechnungswesen\Core\Shared\IdGenerator;
use Rechnungswesen\Core\Shared\Uuid;

/**
 * Partner-Operationen (api.md v0.4): createPartner / updatePartner,
 * beide mit Audit-Trail (Stammdatenänderungen sind GoBD-relevant).
 */
final readonly class PartnerService
{
    public function __construct(
        private PartnerRepository $partners,
        private AuditTrail $audit,
        private Clock $clock,
        private IdGenerator $ids,
    ) {
    }

    /** @param array<string, mixed> $input */
    public function create(array $input): Partner
    {
        /** @var list<string> $accountNumbers */
        $accountNumbers = array_values(array_filter(
            is_array($input['accountNumbers'] ?? null) ? $input['accountNumbers'] : [],
            is_string(...),
        ));

        /** @var array<string, mixed> $address */
        $address = is_array($input['address'] ?? null) ? $input['address'] : [];

        $partner = new Partner(
            $this->ids->next(),
            is_string($input['name'] ?? null) ? $input['name'] : '',
            is_string($input['kind'] ?? null) ? $input['kind'] : 'both',
            is_string($input['vatId'] ?? null) ? $input['vatId'] : null,
            is_int($input['paymentTermsDays'] ?? null) ? $input['paymentTermsDays'] : null,
            $accountNumbers,
            $address,
        );

        $this->partners->add($partner);
        $this->recordAudit($input, 'created', $partner->id, []);

        return $partner;
    }

    /** @param array<string, mixed> $input */
    public function update(array $input): Partner
    {
        $partner = $this->require($input['partnerId'] ?? null);
        $changes = $partner->update($input);

        if ($changes !== []) {
            $this->partners->save($partner);
            $this->recordAudit($input, 'updated', $partner->id, $changes);
        }

        return $partner;
    }

    public function require(mixed $partnerId): Partner
    {
        $partner = null;

        if (is_string($partnerId) && $partnerId !== '') {
            try {
                $partner = $this->partners->byId(Uuid::fromString($partnerId));
            } catch (InvalidValue) {
                $partner = null;
            }
        }

        return $partner ?? throw new DomainError('E_PARTNER_UNKNOWN', sprintf(
            'Geschäftspartner %s existiert nicht',
            is_string($partnerId) ? $partnerId : '?',
        ));
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, array{from: mixed, to: mixed}> $changes
     */
    private function recordAudit(array $input, string $action, Uuid $objectId, array $changes): void
    {
        $actor = is_string($input['actor'] ?? null) && $input['actor'] !== '' ? $input['actor'] : 'system';

        $this->audit->append(new AuditRecord(
            $this->ids->next(),
            $this->clock->now(),
            $actor,
            'partner',
            $objectId,
            $action,
            $changes,
        ));
    }
}
