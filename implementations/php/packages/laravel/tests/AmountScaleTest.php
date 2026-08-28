<?php

declare(strict_types=1);

namespace Summae\Laravel\Tests;

use Summae\Core\Composition\TenantOperations;
use Summae\Core\DomainError;
use Summae\Core\Substrate\Currency;
use Summae\Core\Substrate\FixedClock;
use Summae\Core\Substrate\Money;
use Summae\Core\Substrate\Uuid;
use Summae\Core\Substrate\UuidV7IdGenerator;
use Summae\Core\Tenant;
use Summae\Laravel\DatabaseTenantFactory;
use Summae\Laravel\Repository\Hydrator;
use Summae\Laravel\Schema\SchemaInstaller;

/**
 * `E_AMOUNT_SCALE_MISMATCH` — the store's amounts carry exactly the tenant's decimal places
 * (IMPL-040).
 *
 * The code sat in `fehlerkatalog.md` and in both exit-code tables and was raised by nothing: the
 * only catalogue code reachable through the API with no test behind it. What the check protects is
 * the shared data set — a store written by one runtime at scale 3 opened by a tenant at scale 2 is
 * SF-15's own scenario, and SF-15 passes because both runtimes agree, not because anything verifies
 * the amounts.
 *
 * Building it found the defect underneath: the hydrator built `Currency::of($code)` with no scale
 * override, so it read every amount on the ISO default no matter what the tenant's pack says. At
 * scale 3 that threw a raw `InvalidValue` out of the adapter; at scale 0 it silently widened
 * `"1234"` to `"1234.00"`. No fixture that re-hydrates money runs at a scale other than 2, so
 * twelve thousand hydrations per suite run all agreed with a wrong default.
 *
 * `E_ENTRY_INVALID_AMOUNT` keeps the API-input side (`core/post-malformed` pins it): that code
 * judges an amount a caller offered, this one judges an amount already in the books.
 *
 * Node twin: amount-scale.test.ts.
 */
final class AmountScaleTest extends AdapterTestCase
{
    private function tenantAtScale(Uuid $tenantId, int $scale): Tenant
    {
        $clock = FixedClock::at('2026-06-07T12:00:00+02:00');

        return (new DatabaseTenantFactory($this->connection))->build(
            'Skalen GmbH',
            Currency::of('EUR', $scale),
            $clock,
            new UuidV7IdGenerator($clock),
            null,
            null,
            null,
            null,
            $tenantId,
        );
    }

    /** A tenant with a receivable, so an open item exists whose amount is a column of its own. */
    private function seedReceivable(Tenant $tenant, string $amount): void
    {
        $ops = new TenantOperations($tenant);
        $ops->execute('createFiscalYear', ['year' => 2026, 'start' => '2026-01-01', 'end' => '2026-12-31']);
        $ops->execute('createAccount', ['number' => '10000', 'name' => 'Kunde', 'type' => 'asset', 'subtype' => 'ar']);
        $ops->execute('createAccount', ['number' => '8400', 'name' => 'Erlöse', 'type' => 'revenue']);
        $voucher = $ops->execute('createVoucher', [
            'voucher' => ['voucherNumber' => 'RE-1', 'voucherDate' => '2026-03-01'],
        ]);
        $ops->execute('post', [
            'entryDate' => '2026-03-01',
            'voucherId' => is_string($voucher['id'] ?? null) ? $voucher['id'] : '',
            'text' => 'Ausgangsrechnung',
            'lines' => [
                ['account' => '10000', 'side' => 'debit', 'money' => ['amount' => $amount, 'currency' => 'EUR']],
                ['account' => '8400', 'side' => 'credit', 'money' => ['amount' => $amount, 'currency' => 'EUR']],
            ],
        ]);
    }

    /**
     * The bug the finding did not know about: a scale-3 tenant could not read its own books back.
     *
     * This is the regression this whole entry rests on — before the fix, reading a scale-3 open item
     * threw `InvalidValue` ("Invalid amount for currency EUR (scale 2)") from inside the adapter,
     * because the hydrator had rebuilt the currency at its ISO default.
     */
    public function testATenantReadsItsOwnBooksBackOnItsOwnScale(): void
    {
        $tenantId = Uuid::v7(FixedClock::at('2026-06-07T12:00:00+02:00'));
        $this->seedReceivable($this->tenantAtScale($tenantId, 3), '107.501');

        // A second tenant instance on the same connection: everything asserted has been through a
        // column, which is the only way this test differs from an in-memory one.
        $ops = new TenantOperations($this->tenantAtScale($tenantId, 3));
        $open = $ops->project('openItems', []);

        /** @var list<array<string, mixed>> $items */
        $items = is_array($open['items'] ?? null) ? $open['items'] : [];
        self::assertCount(1, $items);
        /** @var array<string, mixed> $money */
        $money = is_array($items[0]['money'] ?? null) ? $items[0]['money'] : [];
        self::assertSame('107.501', $money['amount'] ?? null);
    }

    /**
     * The reader direction: a stored amount on the wrong scale is refused by name.
     *
     * The row is written straight to the column rather than through a repository — that is the whole
     * point. A store on the wrong scale cannot be produced by this engine; it arrives from another
     * runtime, a restore, or a hand edit, and that is exactly when the books must not be reshaped
     * quietly.
     */
    public function testAStoredAmountOnTheWrongScaleIsRefusedByName(): void
    {
        $tenantId = Uuid::v7(FixedClock::at('2026-06-07T12:00:00+02:00'));
        $this->seedReceivable($this->tenantAtScale($tenantId, 2), '100.00');

        $this->connection->table(SchemaInstaller::PREFIX . 'open_items')->update(['amount' => '100.000']);

        $ops = new TenantOperations($this->tenantAtScale($tenantId, 2));

        try {
            $ops->project('openItems', []);
            self::fail('a stored amount on the wrong scale must not be read as if it were right');
        } catch (DomainError $error) {
            self::assertSame('E_AMOUNT_SCALE_MISMATCH', $error->errorCode);
        }
    }

    /**
     * Too FEW places is the same defect and the easier one to miss: `"100.0"` is a value the engine
     * can represent, so nothing would have complained — it would simply have been widened.
     */
    public function testMandatoryZerosAreNotOptional(): void
    {
        $tenantId = Uuid::v7(FixedClock::at('2026-06-07T12:00:00+02:00'));
        $this->seedReceivable($this->tenantAtScale($tenantId, 2), '100.00');

        $this->connection->table(SchemaInstaller::PREFIX . 'open_items')->update(['amount' => '100.0']);

        $ops = new TenantOperations($this->tenantAtScale($tenantId, 2));

        try {
            $ops->project('openItems', []);
            self::fail('"100.0" is not the canonical form of 100.00 and must not be padded silently');
        } catch (DomainError $error) {
            self::assertSame('E_AMOUNT_SCALE_MISMATCH', $error->errorCode);
        }
    }

    /**
     * The writer direction, at the one seam where an amount leaves as a bare string rather than
     * through a `Money` object. Everything else is serialised by `Money` itself, which is canonical
     * by construction — so this is the only place a writer could reshape an amount by hand.
     */
    public function testTheWriterRefusesToStoreAnAmountOffTheTenantScale(): void
    {
        try {
            Hydrator::assertScale(Money::of('100.00', Currency::of('EUR'))->amountAsString(), Currency::of('EUR', 3));
            self::fail('writing a scale-2 amount into a scale-3 store must not pass');
        } catch (DomainError $error) {
            self::assertSame('E_AMOUNT_SCALE_MISMATCH', $error->errorCode);
        }

        // And the happy path really is happy, in both the zero-scale and the wide case.
        self::assertSame('1234', Hydrator::assertScale('1234', Currency::of('JPY')));
        self::assertSame('1.500', Hydrator::assertScale('1.500', Currency::of('EUR', 3)));
    }

    /**
     * An absent amount keeps the documented zero fallback. A malformed document must not take the
     * process down mid-read, and "no amount at all" is a different thing from "an amount on the
     * wrong scale" — only the second is a claim somebody made about the books.
     */
    public function testAnAbsentAmountIsStillTheDocumentedZero(): void
    {
        self::assertSame('0.000', Hydrator::money([], Currency::of('EUR', 3))->amountAsString());
    }
}
