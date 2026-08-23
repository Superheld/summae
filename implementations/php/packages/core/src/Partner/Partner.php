<?php

declare(strict_types=1);

namespace Summae\Core\Partner;

use Summae\Core\Substrate\CanonicalJson;
use Summae\Core\Substrate\Uuid;

/**
 * Business partner (datenformat.md v0.4) — deliberately lean, no CRM:
 * covers OP-per-partner, intra-community supply proof (VAT ID fixed on the transaction),
 * EC sales list basis and DATEV master-data export.
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
     * @return array<string, array{from: mixed, to: mixed}> change diff for the audit
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

        // `null` clears the term, the way `vatId: null` above already did. Reading it with an
        // is-int check meant an agreed payment term could be set and never taken back: neither null
        // nor an absent field removed it, and the two fields behaved differently with nothing
        // saying so.
        if (
            array_key_exists('paymentTermsDays', $input)
            && (is_int($input['paymentTermsDays']) || $input['paymentTermsDays'] === null)
            && $input['paymentTermsDays'] !== $this->paymentTermsDays
        ) {
            $changes['paymentTermsDays'] = ['from' => $this->paymentTermsDays, 'to' => $input['paymentTermsDays']];
            $this->paymentTermsDays = $input['paymentTermsDays'];
        }

        // Create-only until now, which made a wrong account link permanent: the partner had to be
        // abandoned and created again under a new id, and every open item stayed on the old one.
        // Both replace wholesale rather than merging — "these are the accounts now" is a statement
        // an application can make from a form, while a merge would need a way to say "remove this
        // one".
        if (is_array($input['accountNumbers'] ?? null)) {
            /** @var list<string> $next */
            $next = array_values(array_filter($input['accountNumbers'], is_string(...)));
            if (CanonicalJson::encode($next) !== CanonicalJson::encode($this->accountNumbers)) {
                $changes['accountNumbers'] = ['from' => $this->accountNumbers, 'to' => $next];
                $this->accountNumbers = $next;
            }
        }

        if (isset($input['address']) && is_array($input['address'])) {
            /** @var array<string, mixed> $next */
            $next = array_is_list($input['address']) && $input['address'] !== [] ? [] : $input['address'];
            if (CanonicalJson::encode($next) !== CanonicalJson::encode($this->address)) {
                $changes['address'] = ['from' => $this->address, 'to' => $next];
                $this->address = $next;
            }
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
