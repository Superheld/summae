<?php

declare(strict_types=1);

namespace Summae\Core\Tests\Composition;

use PHPUnit\Framework\TestCase;
use Summae\Core\Composition\OperationParameters;
use Summae\Core\Composition\TenantOperations;
use Summae\Core\DomainError;
use Summae\Core\Policies\Projection\SystemDescriptionProjection;
use Summae\Core\Substrate\Currency;
use Summae\Core\Substrate\DeterministicIdGenerator;
use Summae\Core\Substrate\FixedClock;
use Summae\Core\Tenant;

/**
 * Drift guard for the operation input contract — the write-side twin of
 * ProjectionParametersTest (F-9).
 *
 * Same reasoning, one level more serious: the core cannot read
 * testing/testsuite/schema/api-parameters.json (framework-free, no file I/O), so it carries the
 * table as a constant, and a copy nobody compares drifts. Where a drifted *projection* parameter
 * returns a wrong number, a drifted *operation* input writes one into the books.
 *
 * The third assertion is the one that keeps the guard honest: an operation missing from the table
 * is not validated at all, silently, because validate() leaves unknown names to the dispatcher.
 * Holding the table's key set against API_OPERATIONS means "not declared" cannot quietly mean
 * "not checked".
 */
final class OperationParametersTest extends TestCase
{
    /**
     * `$comment` is documentation, not contract — see the projection twin.
     *
     * @param array<string, array<string, array<string, mixed>>> $operations
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function withoutComments(array $operations): array
    {
        return self::stripComments($operations);
    }

    /**
     * Recursive since SPEC-017: a declaration nests now (`fields`, `element`), and a `$comment` may
     * sit at any depth — it is documentation, and documentation belongs on the thing it concerns.
     */
    private static function stripComments(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        $out = [];
        foreach ($value as $key => $item) {
            if ($key === '$comment') {
                continue;
            }

            $out[$key] = self::stripComments($item);
        }

        return $out;
    }

    /**
     * Every structural input says what is inside it (SPEC-017).
     *
     * This is the guard that makes the finding stay closed. Declaring the element shapes once fixes
     * today's inputs; without this test the next array added to the contract would be structural
     * and unchecked again, and nothing would say so — which is exactly how the gap arose. An author
     * must choose: `fields` for an object, `element` for an array, or `opaque` **with a reason**
     * when another schema owns the structure.
     */
    public function testEveryStructuralInputDeclaresWhatIsInsideIt(): void
    {
        $undeclared = [];
        foreach ($this->schemaOperations() as $op => $params) {
            foreach ($params as $name => $spec) {
                self::collectUndeclared($spec, $op . '.' . $name, $undeclared);
            }
        }

        self::assertSame([], $undeclared, 'an array or object input must declare `element`, `fields` '
            .'or `opaque` with a reason — otherwise it is structural and silent, which is SPEC-017');
    }

    /**
     * @param array<string, mixed> $spec
     * @param list<string> $undeclared
     */
    private static function collectUndeclared(array $spec, string $path, array &$undeclared): void
    {
        $type = $spec['type'] ?? null;

        if ($type === 'array' || $type === 'object') {
            $reason = $spec['opaque'] ?? null;
            $hasInner = isset($spec['fields']) || isset($spec['element']);

            if (!$hasInner && !(is_string($reason) && $reason !== '')) {
                $undeclared[] = $path;
            }
        }

        foreach (is_array($spec['fields'] ?? null) ? $spec['fields'] : [] as $key => $field) {
            if (is_array($field)) {
                self::collectUndeclared($field, $path . '.' . $key, $undeclared);
            }
        }

        if (is_array($spec['element'] ?? null)) {
            self::collectUndeclared($spec['element'], $path . '[]', $undeclared);
        }
    }

    /**
     * The rule reaches all the way down, not one level (SPEC-017).
     *
     * `dimension` instead of `dimensions` inside a net line is the case that was reported: accepted,
     * booked correctly, cost centre gone, no error. The error names the path so the caller does not
     * have to find it.
     */
    public function testRejectsAnUndeclaredKeyInsideAStructure(): void
    {
        self::assertSame('E_INPUT_INVALID', $this->errorCodeOf('post', [
            'voucherId' => 'v', 'entryDate' => '2026-01-01',
            'lines' => [['account' => '1200', 'side' => 'debit', 'dimension' => []]],
        ]));

        // ... and two levels down, where the declaration goes.
        self::assertSame('E_INPUT_INVALID', $this->errorCodeOf('post', [
            'voucherId' => 'v', 'entryDate' => '2026-01-01',
            'lines' => [['account' => '1200', 'dimensions' => [['type' => 'costCenter', 'kode' => 'A']]]],
        ]));
    }

    public function testRejectsAWronglyTypedValueInsideAStructure(): void
    {
        self::assertSame('E_INPUT_INVALID', $this->errorCodeOf('post', [
            'voucherId' => 'v', 'entryDate' => '2026-01-01',
            'lines' => [['account' => '1200', 'money' => 1200]],
        ]));
    }

    /** An opaque structure is passed through untouched — that is what the reason buys. */
    public function testAcceptsAnythingInsideAnOpaqueStructure(): void
    {
        self::assertNotSame('E_INPUT_INVALID', $this->errorCodeOf('createPartner', [
            'name' => 'Kunde AG', 'kind' => 'customer',
            'address' => ['street' => 'Hauptstr. 1', 'whatever' => ['deep' => true]],
        ]));
    }

    /**
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function schemaOperations(): array
    {
        $path = __DIR__ . '/../../../../../../testing/testsuite/schema/api-parameters.json';
        self::assertFileExists($path, 'the normative input contract must be mirrored here');

        $raw = file_get_contents($path);
        self::assertIsString($raw);
        $decoded = json_decode($raw, true);
        self::assertIsArray($decoded);
        self::assertIsArray($decoded['operations'] ?? null);

        /** @var array<string, array<string, array<string, mixed>>> $operations */
        $operations = $decoded['operations'];

        return $operations;
    }

    private function freshOps(): TenantOperations
    {
        $clock = FixedClock::at('2026-06-07T12:00:00+02:00');
        $tenant = Tenant::inMemory('Inputs', Currency::of('EUR'), $clock, new DeterministicIdGenerator($clock));

        return new TenantOperations($tenant);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function errorCodeOf(string $op, array $input): string
    {
        try {
            $this->freshOps()->execute($op, $input);
        } catch (\Throwable $error) {
            return $error instanceof DomainError ? $error->errorCode : 'NOT_A_DOMAIN_ERROR';
        }

        return 'NO_ERROR';
    }

    public function testDeclaresExactlyTheOperationsTheSchemaDeclares(): void
    {
        $declared = array_keys(OperationParameters::OPERATIONS);
        $schema = array_keys($this->schemaOperations());
        sort($declared);
        sort($schema);

        self::assertSame($schema, $declared);
    }

    public function testDeclaresEveryInputWithTheSameTypeAndFlagsAsTheSchema(): void
    {
        self::assertEquals($this->withoutComments($this->schemaOperations()), OperationParameters::OPERATIONS);
    }

    public function testDeclaresEveryOperationTheApiPublishes(): void
    {
        // An operation the table does not know is not validated, and nothing else would say so.
        $declared = array_keys(OperationParameters::OPERATIONS);
        $published = SystemDescriptionProjection::API_OPERATIONS;
        sort($declared);
        sort($published);

        self::assertSame($published, $declared);
    }

    public function testRejectsAnUndeclaredInputInsteadOfIgnoringIt(): void
    {
        // The real-world shapes. `usefulLifeYears` looks right, means nothing, and used to leave
        // the pack's lookup in charge of a useful life the caller thought they had set — summae's
        // own audit-trail test passed it for months. `role` on a partner is the same mistake: the
        // field is called `kind`.
        self::assertSame('E_INPUT_INVALID', $this->errorCodeOf('acquireAsset', ['usefulLifeYears' => 5]));
        self::assertSame(
            'E_INPUT_INVALID',
            $this->errorCodeOf('createPartner', ['name' => 'Kunde AG', 'role' => 'customer']),
        );
    }

    public function testRejectsANumericInputPassedAsAStringRatherThanIgnoringIt(): void
    {
        // A form posts "30", every handler read a type check, and the value was not rejected but
        // DROPPED — the documented default stood in its place and nothing was said.
        self::assertSame(
            'E_INPUT_INVALID',
            $this->errorCodeOf('createPartner', ['name' => 'X', 'paymentTermsDays' => '30']),
        );
        self::assertSame('E_INPUT_INVALID', $this->errorCodeOf('runDepreciation', ['fiscalYear' => '2026']));
    }

    public function testRejectsAnAmountPassedAsANumberRatherThanAsMoney(): void
    {
        // `proceeds: 2000` was read with an is-array check and silently became "no proceeds" — a
        // disposal booked as if the asset had been scrapped for nothing.
        self::assertSame(
            'E_INPUT_INVALID',
            $this->errorCodeOf('disposeAsset', ['assetId' => 'x', 'disposedOn' => '2026-06-30', 'proceeds' => 2000]),
        );
    }

    public function testLeavesAnAbsentOptionalInputToItsDocumentedDefault(): void
    {
        self::assertSame('NO_ERROR', $this->errorCodeOf('runDepreciation', ['fiscalYear' => 2026, 'period' => null]));
    }

    public function testLeavesAnUnknownOperationNameToTheDispatcher(): void
    {
        self::assertSame('E_NOT_IMPLEMENTED', $this->errorCodeOf('doesNotExist', ['whatever' => 1]));
    }
}
