<?php

declare(strict_types=1);

namespace Summae\Core\Shared;

use Summae\Core\Shared\Exception\InvalidValue;

/**
 * Zusatzzuordnung einer Buchungsposition: Dimensionstyp + Wert-Code
 * (datenformat.md: `"dimensions": [{ "type": "costCenter", "code": "100" }]`).
 * Typen sind Stammdaten — die Gültigkeitsprüfung gegen sie passiert
 * an der Operation (E_DIMENSION_INVALID), nicht hier.
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
            throw new InvalidValue('Dimensionstyp und -code dürfen nicht leer sein');
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
