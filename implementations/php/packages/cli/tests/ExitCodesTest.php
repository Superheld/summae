<?php

declare(strict_types=1);

namespace Summae\Cli\Tests;

use PHPUnit\Framework\TestCase;
use Summae\Cli\ExitCodes;

/**
 * Drift guard for the exit-code mapping (IMPL-018).
 *
 * testing/testsuite/fehlerkatalog.md is the normative list of error codes; ExitCodes turns it
 * into the numbers a script branches on. Nothing compared the two, so four codes reached the
 * catalogue and never reached the mapping — they exited 1, which the CLI documents as *unknown
 * error*, i.e. indistinguishable from a summae crash. The JSON on stderr still named the code, so
 * a human reader lost nothing and no test went red: exactly the kind of gap this comparison
 * closes. Its Node twin (`exit-codes.test.ts`) asserts the same thing against the same file, so
 * the two languages cannot drift apart either.
 *
 * Deliberately without an exception list: a code that is declared but not yet thrown gets its
 * number reserved (E_AMOUNT_SCALE_MISMATCH). An allowlist here would be the same hole again.
 */
final class ExitCodesTest extends TestCase
{
    /**
     * The catalogue lists its codes in tables, one per row: `| \`E_…\` | invariant | fixture |`.
     * Codes mentioned in the surrounding prose (E_UNEXPECTED) are explanation, not contract, and
     * are not matched — the anchor is the start of a table row.
     *
     * @return list<string>
     */
    private function catalogCodes(): array
    {
        $path = __DIR__ . '/../../../../../testing/testsuite/fehlerkatalog.md';
        self::assertFileExists($path, 'the normative error catalogue must be mirrored here');

        $raw = file_get_contents($path);
        self::assertIsString($raw);

        preg_match_all('/^\| `(E_[A-Z_]+)`/m', $raw, $matches);
        $codes = $matches[1];

        self::assertGreaterThan(30, count($codes), 'the catalogue parse must not silently yield nothing');

        return array_values(array_unique($codes));
    }

    public function testEveryCatalogedErrorCodeHasAnExitCodeOfItsOwn(): void
    {
        $withoutExit = array_values(array_filter(
            $this->catalogCodes(),
            static fn (string $code): bool => ExitCodes::for($code) === 1,
        ));

        self::assertSame([], $withoutExit, 'these catalogued codes fall through to exit 1 (IMPL-018)');
    }

    /**
     * The other direction, and the reason the two lists are compared as sets: a code that has a
     * number here but no row in the catalogue is invisible to every machine check — the knowledge
     * base's validate.py never sees it, and the test above cannot miss it. `E_NOT_IMPLEMENTED`
     * sat in exactly that blind spot until 2026-08-16.
     */
    public function testMapsNoErrorCodeTheCatalogDoesNotKnow(): void
    {
        $cataloged = $this->catalogCodes();
        $uncataloged = array_values(array_filter(
            ExitCodes::all(),
            static fn (string $code): bool => !in_array($code, $cataloged, true),
        ));

        self::assertSame([], $uncataloged, 'these codes have an exit code but no catalogue row');
    }

    public function testNoTwoErrorCodesShareAnExitCode(): void
    {
        $codes = $this->catalogCodes();
        $exits = array_map(static fn (string $code): int => ExitCodes::for($code), $codes);

        self::assertSame(count($codes), count(array_unique($exits)));
    }

    /**
     * The numbers are a published contract (index + 10, append-only): reordering or inserting
     * would silently renumber every later code. These anchors pin the head, the middle and the
     * current tail, so an insertion cannot pass unnoticed — while a plain append, which shifts
     * nothing, stays free of test churn.
     */
    public function testTheNumbersAreStable(): void
    {
        self::assertSame(10, ExitCodes::for('E_ENTRY_UNBALANCED'));
        self::assertSame(45, ExitCodes::for('E_INPUT_INVALID'));
        self::assertSame(53, ExitCodes::for('E_AMOUNT_SCALE_MISMATCH'));
    }

    public function testAnUnknownCodeStaysTheUnknownError(): void
    {
        self::assertSame(1, ExitCodes::for('E_NOT_A_REAL_CODE'));
    }
}
