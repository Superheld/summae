<?php

declare(strict_types=1);

namespace Summae\Core\Policies\Constraint;

use Summae\Core\DomainError;
use Summae\Core\Substrate\AccountNumber;
use Summae\Core\Substrate\DimensionValue;

/**
 * Dimension validation: mechanism in the core, contents as rule module data
 * (ledger-modell.md, tactical question 4). Types and values are master data;
 * mandatory dimensions come from `ruleModules.dimensionRules`.
 */
final class DimensionRegistry
{
    /**
     * @param array<string, true> $types known type codes
     * @param array<string, true> $values "type:code"
     * @param list<array{from: string, to: string, required: string}> $rules
     */
    private function __construct(
        private array $types,
        private array $values,
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
     * Declares a dimension type (a cost centre axis, a project axis, …).
     *
     * Dimension types and values are the tenant's own master data, like accounts — not the pack's,
     * because "Materialstelle" is a fact about one company and not about German law. They were
     * declarable only through the in-memory construction path, which meant a tenant built FROM A PACK
     * had an empty registry and every posting carrying a cost centre was rejected: cost accounting was
     * unreachable on `de`, `us` and `default` alike, and nothing in the packs said so.
     *
     * @throws DomainError E_DIMENSION_INVALID
     */
    public function defineType(string $code): void
    {
        if ($code === '') {
            throw new DomainError('E_DIMENSION_INVALID', 'A dimension type needs a code', ['type' => $code]);
        }

        if (isset($this->types[$code])) {
            throw new DomainError('E_DIMENSION_INVALID', sprintf(
                'Dimension type "%s" is already defined',
                $code,
            ), ['type' => $code]);
        }

        $this->types[$code] = true;
    }

    /**
     * Declares a value of an existing type. Refused for an unknown type rather than creating it on
     * the way: a typo in the type would otherwise open a second, near-identical axis in silence.
     *
     * @throws DomainError E_DIMENSION_INVALID
     */
    public function defineValue(string $typeCode, string $code): void
    {
        if (!isset($this->types[$typeCode])) {
            throw new DomainError('E_DIMENSION_INVALID', sprintf(
                'Unknown dimension type "%s"',
                $typeCode,
            ), ['type' => $typeCode]);
        }

        if ($code === '') {
            throw new DomainError('E_DIMENSION_INVALID', 'A dimension value needs a code', ['type' => $typeCode]);
        }

        if (isset($this->values[$typeCode . ':' . $code])) {
            throw new DomainError('E_DIMENSION_INVALID', sprintf(
                'Dimension value "%s" of type "%s" is already defined',
                $code,
                $typeCode,
            ), ['type' => $typeCode, 'code' => $code]);
        }

        $this->values[$typeCode . ':' . $code] = true;
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
                    'Unknown dimension type "%s"',
                    $dimension->type,
                ), ['type' => $dimension->type]);
            }

            if (!isset($this->values[$dimension->type . ':' . $dimension->code])) {
                throw new DomainError('E_DIMENSION_INVALID', sprintf(
                    'Unknown dimension value "%s" for type "%s"',
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
                'Mandatory dimension "%s" missing on account %s',
                $rule['required'],
                $account->value,
            ), ['account' => $account->value, 'required' => $rule['required']]);
        }
    }
}
