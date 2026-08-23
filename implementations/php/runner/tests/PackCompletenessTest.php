<?php

declare(strict_types=1);

namespace Summae\Runner\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Quality-gate obligation: a shipped pack must be COMPLETE, not merely well-formed.
 *
 * `PackLibrarySchemaValidationTest` proves that every module parses and matches the format. It
 * cannot notice that a pack ships an account no statement assigns, or a tax code the DATEV export
 * cannot label. Neither can the conformance fixtures: they exercise mechanism with inline rule
 * data of their own, so a pack whose data is thin still passes everything. That gap produced three
 * separate defects found on 2026-08-23, all of the same shape — the engine was right, the shipped
 * product data was not:
 *
 *   - `de-euer` left four of its own accounts unassigned, among them the small-business revenue
 *     account, which is the single most likely account for a cash-basis filer to use;
 *   - `us-schedule-c` left one;
 *   - not one `de-ust` tax code carried a DATEV key, so every exported batch line lost its tax.
 *
 * The rules below are deliberately narrow: each states an obligation a pack author can satisfy,
 * and none of them guesses on the author's behalf.
 */
final class PackCompletenessTest extends TestCase
{
    /**
     * Mapping kinds that present profit and loss, and therefore have to account for EVERY revenue
     * and expense account the pack ships. A balance sheet legitimately touches none of them, which
     * is why the obligation is per kind rather than "every mapping".
     */
    private const PROFIT_KINDS = ['income-statement', 'cash-basis-categories'];

    /** Every mapping kind currently shipped. An unknown one must be classified, not ignored. */
    private const KNOWN_KINDS = ['income-statement', 'cash-basis-categories', 'balance-sheet'];

    public function testEveryProfitAndLossAccountIsAssignedInEveryStatement(): void
    {
        $violations = [];

        foreach ($this->packs() as $pack => $dir) {
            $accounts = $this->accountsOf($dir);
            $profitAccounts = [];
            foreach ($accounts as $account) {
                $number = $account['number'] ?? null;
                if (is_string($number) && in_array($account['type'] ?? null, ['revenue', 'expense'], true)) {
                    $profitAccounts[] = $number;
                }
            }

            if ($profitAccounts === []) {
                continue;
            }

            foreach ($this->mappingsOf($dir) as $file => $mapping) {
                $kind = is_string($mapping['kind'] ?? null) ? $mapping['kind'] : '';

                if (!in_array($kind, self::KNOWN_KINDS, true)) {
                    $violations[] = sprintf('%s: unknown mapping kind "%s" — classify it in PROFIT_KINDS or not', $file, $kind);
                    continue;
                }

                if (!in_array($kind, self::PROFIT_KINDS, true)) {
                    continue;
                }

                $covered = $this->coveredAccounts($mapping);
                $missing = array_values(array_diff($profitAccounts, $covered));

                if ($missing !== []) {
                    $violations[] = sprintf(
                        '%s (%s): %s assigns no position to %s',
                        $pack,
                        $file,
                        $kind,
                        implode(', ', $missing),
                    );
                }
            }
        }

        self::assertSame(
            [],
            $violations,
            "A statement that assigns some accounts but not others reports a total that is silently\n"
                . "incomplete, and the money surfaces under a raw account name instead of a position:\n"
                . implode("\n", $violations),
        );
    }

    /**
     * A pack that offers DATEV keys at all offers them for every tax code that books tax.
     *
     * DATEV is a German interchange format, so no pack is obliged to support it — demanding a key
     * from the `us` pack's sales tax would be exporting one jurisdiction's tooling into every
     * other. The obligation is therefore conditional, the same shape as `poolProRataInFirstYear`:
     * silence is a valid answer, half an answer is not. Once a pack declares one key, the rest
     * become mandatory, because the batch folds the tax line into the gross amount and rebuilds it
     * from the key — a code without one exports its gross with the tax silently gone.
     *
     * Scoped to `standard` on purpose. Only the plain output/input keys are unambiguous (2 and 3
     * for 7 % and 19 % output, 8 and 9 for input). Reverse charge and intra-community supply map
     * onto several DATEV keys depending on the underlying transaction, and this suite does not put
     * a guess into a shipped pack: a wrong key posts the wrong tax at the recipient, which is worse
     * than an absent one.
     */
    public function testStandardTaxCodesCarryADatevKey(): void
    {
        $violations = [];

        foreach ($this->packs() as $pack => $dir) {
            foreach (glob($dir . '/tax/*.json') ?: [] as $file) {
                /** @var array<string, mixed> $module */
                $module = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
                $data = is_array($module['data'] ?? null) ? $module['data'] : [];
                $taxCodes = is_array($data['taxCodes'] ?? null) ? $data['taxCodes'] : [];

                // Does this module speak DATEV at all? If not, it owes nothing here.
                $declaresAny = false;
                foreach ($taxCodes as $code) {
                    if (is_array($code) && is_string($code['datevBu'] ?? null)) {
                        $declaresAny = true;
                    }
                }

                if (!$declaresAny) {
                    continue;
                }

                foreach ($taxCodes as $code) {
                    if (!is_array($code)) {
                        continue;
                    }

                    $standardWithTax = false;
                    foreach (is_array($code['versions'] ?? null) ? $code['versions'] : [] as $version) {
                        if (
                            is_array($version)
                            && ($version['mechanism'] ?? 'standard') === 'standard'
                            && is_string($version['taxAccount'] ?? null)
                        ) {
                            $standardWithTax = true;
                        }
                    }

                    if ($standardWithTax && !is_string($code['datevBu'] ?? null)) {
                        $name = is_string($code['code'] ?? null) ? $code['code'] : '?';
                        $violations[] = sprintf('%s: tax code "%s" books tax but carries no datevBu', $pack, $name);
                    }
                }
            }
        }

        self::assertSame(
            [],
            $violations,
            "The DATEV batch folds the tax line into the gross amount and recreates it from the key.\n"
                . "Without a key the exported line carries the gross with no tax at all:\n"
                . implode("\n", $violations),
        );
    }

    /** @return array<string, string> pack name => directory */
    private function packs(): array
    {
        $root = dirname(__DIR__, 4) . '/pack-library';
        $packs = [];

        foreach (glob($root . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $packs[basename($dir)] = $dir;
        }

        return $packs;
    }

    /** @return list<array<string, mixed>> */
    private function accountsOf(string $dir): array
    {
        $accounts = [];

        foreach (glob($dir . '/accounts/*.json') ?: [] as $file) {
            /** @var array<string, mixed> $module */
            $module = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            $data = is_array($module['data'] ?? null) ? $module['data'] : [];

            foreach (is_array($data['accounts'] ?? null) ? $data['accounts'] : [] as $account) {
                if (is_array($account) && isset($account['number'])) {
                    /** @var array<string, mixed> $account */
                    $accounts[] = $account;
                }
            }
        }

        return $accounts;
    }

    /** @return array<string, array<string, mixed>> file name => mapping */
    private function mappingsOf(string $dir): array
    {
        $mappings = [];

        foreach (glob($dir . '/mappings/*.json') ?: [] as $file) {
            /** @var array<string, mixed> $module */
            $module = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            $data = is_array($module['data'] ?? null) ? $module['data'] : [];

            if (is_array($data['mapping'] ?? null)) {
                /** @var array<string, mixed> $mapping */
                $mapping = $data['mapping'];
                $mappings[basename($file)] = $mapping;
            }
        }

        return $mappings;
    }

    /**
     * Account numbers a mapping assigns, ranges expanded.
     *
     * @param array<string, mixed> $mapping
     *
     * @return list<string>
     */
    private function coveredAccounts(array $mapping): array
    {
        $covered = [];
        $this->collect($mapping, $covered);

        return array_values(array_unique($covered));
    }

    /**
     * @param mixed        $node
     * @param list<string> $covered
     */
    private function collect(mixed $node, array &$covered): void
    {
        if (!is_array($node)) {
            return;
        }

        if (isset($node['key']) && is_array($node['accounts'] ?? null)) {
            foreach ($node['accounts'] as $spec) {
                if (!is_array($spec)) {
                    continue;
                }

                foreach (is_array($spec['numbers'] ?? null) ? $spec['numbers'] : [] as $number) {
                    if (is_string($number)) {
                        $covered[] = $number;
                    }
                }

                $from = $spec['from'] ?? null;
                $to = $spec['to'] ?? null;
                if (is_string($from) && is_string($to)) {
                    for ($n = (int) $from; $n <= (int) $to; $n++) {
                        $covered[] = (string) $n;
                    }
                }
            }
        }

        foreach ($node as $child) {
            $this->collect($child, $covered);
        }
    }
}
