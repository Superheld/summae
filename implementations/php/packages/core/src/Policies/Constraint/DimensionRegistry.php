<?php

declare(strict_types=1);

namespace Summae\Core\Policies\Constraint;

use Summae\Core\DomainError;
use Summae\Core\Substrate\AccountNumber;
use Summae\Core\Substrate\DimensionValue;

/**
 * Dimensions-Validierung: Mechanik im Kern, Inhalte als Regelmodul-Daten
 * (ledger-modell.md, taktische Frage 4). Typen und Werte sind Stammdaten;
 * Pflichtdimensionen kommen aus `ruleModules.dimensionRules`.
 */
final class DimensionRegistry
{
    /**
     * @param array<string, true> $types bekannte Typ-Codes
     * @param array<string, true> $values "typ:code"
     * @param list<array{from: string, to: string, required: string}> $rules
     */
    private function __construct(
        private readonly array $types,
        private readonly array $values,
        private readonly array $rules,
    ) {
    }

    public static function empty(): self
    {
        return new self([], [], []);
    }

    /**
     * @param list<array{code: string}> $dimensionTypes
     * @param list<array{typeCode: string, code: string}> $dimensionValues
     * @param list<array{accountRange: array{from: string, to: string}, requiredDimension: string}> $dimensionRules
     */
    public static function fromData(array $dimensionTypes, array $dimensionValues, array $dimensionRules): self
    {
        $types = [];
        foreach ($dimensionTypes as $type) {
            $types[$type['code']] = true;
        }

        $values = [];
        foreach ($dimensionValues as $value) {
            $values[$value['typeCode'] . ':' . $value['code']] = true;
        }

        $rules = array_map(
            static fn (array $rule): array => [
                'from' => $rule['accountRange']['from'],
                'to' => $rule['accountRange']['to'],
                'required' => $rule['requiredDimension'],
            ],
            $dimensionRules,
        );

        return new self($types, $values, $rules);
    }

    /**
     * @param list<DimensionValue> $dimensions
     *
     * @throws DomainError E_DIMENSION_INVALID
     */
    public function validateLine(AccountNumber $account, array $dimensions): void
    {
        foreach ($dimensions as $dimension) {
            if (!isset($this->types[$dimension->type])) {
                throw new DomainError('E_DIMENSION_INVALID', sprintf(
                    'Unbekannter Dimensionstyp "%s"',
                    $dimension->type,
                ), ['type' => $dimension->type]);
            }

            if (!isset($this->values[$dimension->type . ':' . $dimension->code])) {
                throw new DomainError('E_DIMENSION_INVALID', sprintf(
                    'Unbekannter Dimensionswert "%s" für Typ "%s"',
                    $dimension->code,
                    $dimension->type,
                ), ['type' => $dimension->type, 'code' => $dimension->code]);
            }
        }

        foreach ($this->rules as $rule) {
            $inRange = strcmp($account->value, $rule['from']) >= 0
                && strcmp($account->value, $rule['to']) <= 0;

            if (!$inRange) {
                continue;
            }

            foreach ($dimensions as $dimension) {
                if ($dimension->type === $rule['required']) {
                    continue 2;
                }
            }

            throw new DomainError('E_DIMENSION_INVALID', sprintf(
                'Pflichtdimension "%s" fehlt auf Konto %s',
                $rule['required'],
                $account->value,
            ), ['account' => $account->value, 'required' => $rule['required']]);
        }
    }
}
