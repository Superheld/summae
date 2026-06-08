<?php

declare(strict_types=1);

namespace Summae\Core\Partner;

use Summae\Core\Shared\Uuid;

/**
 * Geschäftspartner (datenformat.md v0.4) — bewusst schlank, kein CRM:
 * deckt OP-je-Partner, igL-Nachweis (USt-IdNr. GoBD-fest am Vorgang),
 * ZM-Grundlage und DATEV-Stammdaten-Export.
 */
final class Partner implements \JsonSerializable
{
    /**
     * @param list<string> $accountNumbers
     * @param array<string, mixed> $address
     */
    public function __construct(
        public readonly Uuid $id,
        private string $name,
        private string $kind,
        private ?string $vatId,
        private ?int $paymentTermsDays,
        private array $accountNumbers = [],
        private array $address = [],
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function vatId(): ?string
    {
        return $this->vatId;
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, array{from: mixed, to: mixed}> Änderungs-Diff fürs Audit
     */
    public function update(array $input): array
    {
        $changes = [];

        if (is_string($input['name'] ?? null) && $input['name'] !== $this->name) {
            $changes['name'] = ['from' => $this->name, 'to' => $input['name']];
            $this->name = $input['name'];
        }

        if (array_key_exists('vatId', $input) && $input['vatId'] !== $this->vatId && (is_string($input['vatId']) || $input['vatId'] === null)) {
            $changes['vatId'] = ['from' => $this->vatId, 'to' => $input['vatId']];
            $this->vatId = $input['vatId'];
        }

        if (is_string($input['kind'] ?? null) && $input['kind'] !== $this->kind) {
            $changes['kind'] = ['from' => $this->kind, 'to' => $input['kind']];
            $this->kind = $input['kind'];
        }

        if (is_int($input['paymentTermsDays'] ?? null) && $input['paymentTermsDays'] !== $this->paymentTermsDays) {
            $changes['paymentTermsDays'] = ['from' => $this->paymentTermsDays, 'to' => $input['paymentTermsDays']];
            $this->paymentTermsDays = $input['paymentTermsDays'];
        }

        return $changes;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id->value,
            'name' => $this->name,
            'kind' => $this->kind,
            'vatId' => $this->vatId,
            'paymentTermsDays' => $this->paymentTermsDays,
            'accountNumbers' => $this->accountNumbers,
            'address' => $this->address === [] ? null : $this->address,
        ];
    }
}
