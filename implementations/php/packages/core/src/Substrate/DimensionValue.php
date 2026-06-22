<?php

declare(strict_types=1);

namespace Summae\Core\Substrate;

use Summae\Core\Substrate\Exception\InvalidValue;

/**
 * Additional allocation of a posting line: dimension type + value code
 * (datenformat.md: `"dimensions": [{ "type": "costCenter", "code": "100" }]`).
 * Types are master data — the validity check against them happens
 * at the operation (E_DIMENSION_INVALID), not here.
 */
final readonly class DimensionValue implements \JsonSerializable
{
    private function __construct(
        public string $type,
        public string $code,
    ) {
    }

    public static function of(string $type, string $code): self
    {
        if ($type === '' || $code === '') {
            throw new InvalidValue('Dimension type and code must not be empty');
        }

        return new self($type, $code);
    }

    public function equals(self $other): bool
    {
        return $this->type === $other->type && $this->code === $other->code;
    }

    /** @return array{type: string, code: string} */
    public function jsonSerialize(): array
    {
        return [
            'type' => $this->type,
            'code' => $this->code,
        ];
    }
}
