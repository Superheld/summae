<?php

declare(strict_types=1);

namespace Summae\Core\Shared;

use Summae\Core\Shared\Exception\InvalidValue;

/**
 * Währung nach ISO 4217 mit fester Nachkommastellen-Skala
 * (datenformat.md: "feste Nachkommastellen je Währung").
 */
final readonly class Currency implements \JsonSerializable, \Stringable
{
    /**
     * Skalen abweichend vom Default 2. Bewusst klein gehalten —
     * v1 ist EUR-zentriert, Fremdwährung kommt erst in v2.
     */
    private const array SCALES = [
        'JPY' => 0,
        'KRW' => 0,
        'BHD' => 3,
        'KWD' => 3,
        'TND' => 3,
    ];

    private function __construct(
        public string $code,
        public int $scale,
    ) {
    }

    /**
     * `$scaleOverride` setzt die Nachkommastellen-Skala explizit (Pack-Parameter
     * `packPolicy.currencyScale`) — überstimmt die globale Default-/ISO-Skala pro
     * Mandant (Skala ist Pack-Sache, nicht global).
     */
    public static function of(string $code, ?int $scaleOverride = null): self
    {
        if (preg_match('/^[A-Z]{3}$/', $code) !== 1) {
            throw new InvalidValue(sprintf('Ungültiger ISO-4217-Code: "%s"', $code));
        }

        return new self($code, $scaleOverride ?? self::SCALES[$code] ?? 2);
    }

    public function equals(self $other): bool
    {
        return $this->code === $other->code;
    }

    public function jsonSerialize(): string
    {
        return $this->code;
    }

    public function __toString(): string
    {
        return $this->code;
    }
}
