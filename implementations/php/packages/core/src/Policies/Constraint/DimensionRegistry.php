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
     * The registry as the data it was built from (SPEC-015) — `fromData(toData(r))` is `r`.
     *
     * Sorted, because this is what gets stored: two runs that declared the same types in a
     * different order must produce the same stored bytes, or the cross-test would compare a set
     * against an ordering accident. Rules are not included: which accounts require a dimension is
     * the pack's answer, not the tenant's, and it comes back from the pack on every open.
     *
     * @return array{types: list<array{code: string}>, values: list<array{typeCode: string, code: string}>}
     */
    public function toData(): array
    {
        $types = array_keys($this->types);
        sort($types, SORT_STRING);

        $values = array_keys($this->values);
        sort($values, SORT_STRING);

        return [
            'types' => array_map(static fn (string $code): array => ['code' => $code], $types),
            'values' => array_map(
                static function (string $entry): array {
                    $separator = strpos($entry, ':');
                    $separator = $separator === false ? 0 : $separator;

                    return [
                        'typeCode' => substr($entry, 0, $separator),
                        'code' => substr($entry, $separator + 1),
                    ];
                },
                $values,
            ),
        ];
    }

    /**
     * The same rules with different master data (SPEC-015).
     *
     * Reopening a tenant means combining two sources that are not the same kind of thing: the types
     * and values are the tenant's, and come back from its record; the rules — which accounts may not
     * be posted without a dimension — are the pack's, and come back from the pack. This is what
     * keeps them apart without asking the adapter to know the difference.
     *
     * @param list<array{code: string}> $dimensionTypes
     * @param list<array{typeCode: string, code: string}> $dimensionValues
     */
    public function withMasterData(array $dimensionTypes, array $dimensionValues): self
    {
        $types = [];
        foreach ($dimensionTypes as $type) {
            $types[$type['code']] = true;
        }

        $values = [];
        foreach ($dimensionValues as $value) {
            $values[$value['typeCode'] . ':' . $value['code']] = true;
        }

        return new self($types, $values, $this->rules);
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
