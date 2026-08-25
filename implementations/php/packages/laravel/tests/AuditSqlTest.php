<?php

declare(strict_types=1);

namespace Summae\Laravel\Tests;

use PHPUnit\Framework\TestCase;
use Summae\Laravel\Repository\AuditSql;

/**
 * Both dialects of the audit filter, without a server.
 *
 * The adapter suite runs on SQLite and the conformance suite runs the Postgres path against a real
 * server — proof that it works, in a run that measures no coverage here. This pins the strings
 * themselves so a wrong Postgres predicate is found in the file it was written in, not three
 * pipelines away. It asserts *shape*, not behaviour: behaviour is what `--subject=database` and
 * `AuditQueryEquivalenceTest` are for.
 */
final class AuditSqlTest extends TestCase
{
    public function testSqliteReadsTheJsonPayloadWithJsonExtract(): void
    {
        self::assertSame("json_extract(payload, '$.objectType') = ?", AuditSql::equals(false, 'objectType'));
        self::assertSame("json_extract(payload, '$.action') = ?", AuditSql::equals(false, 'action'));
        self::assertSame("json_extract(payload, '$.actor') = ?", AuditSql::equals(false, 'actor'));
        self::assertSame("json_extract(payload, '$.objectId') = ?", AuditSql::equals(false, 'objectId'));
        self::assertSame("substr(json_extract(payload, '$.at'), 1, 10) >= ?", AuditSql::dateAtLeast(false));
        self::assertSame("substr(json_extract(payload, '$.at'), 1, 10) <= ?", AuditSql::dateAtMost(false));
    }

    public function testPostgresReadsTheJsonPayloadWithTheArrowOperator(): void
    {
        self::assertSame("payload->>'objectType' = ?", AuditSql::equals(true, 'objectType'));
        self::assertSame("payload->>'action' = ?", AuditSql::equals(true, 'action'));
        self::assertSame("payload->>'actor' = ?", AuditSql::equals(true, 'actor'));
        self::assertSame("payload->>'objectId' = ?", AuditSql::equals(true, 'objectId'));
        self::assertSame("substr(payload->>'at', 1, 10) >= ?", AuditSql::dateAtLeast(true));
        self::assertSame("substr(payload->>'at', 1, 10) <= ?", AuditSql::dateAtMost(true));
    }
}
