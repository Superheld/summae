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

    /**
     * A result-allocation account belongs to the position that carries the result — and to no other.
     *
     * The appropriation of profit is a resolution, not a calculation, so summae does not post it on
     * anyone's behalf: the decision arrives as an ordinary entry, `result_allocation` account against
     * retained earnings or a distribution liability (`datenformat.md`, F-CORE-024/SF-25). The balance
     * sheet then subtracts that account's balance from the position with `includesNetIncome`, which
     * is what makes the position mean "the result not yet appropriated".
     *
     * That only works if the mapping puts the account there. Both shipped packs put it somewhere
     * else, and neither the schema nor a single fixture could see it, because a fixture brings a
     * mapping of its own that gets it right. The effect was that the appropriation entry cancelled
     * itself out INSIDE the equity position it had wrongly landed in: `de-bilanz` claimed 2000–2499
     * wholesale, which swallowed 2300 next to the retained-earnings account 2100, and
     * `us-gaap-balance-sheet` claimed 3000–3999, swallowing Income Summary 3300 next to Retained
     * Earnings 3100. Book the resolution correctly and the balance sheet did not move — the result
     * stayed labelled as this year's for every year that followed, and the only visible effect of a
     * correct entry was a new zero row.
     *
     * `E_MAPPING_OVERLAP` is why the repair is not "add the account": a wholesale range has to be cut
     * around it, which is presumably how it was missed in the first place.
     */
    public function testResultAllocationAccountsSitInThePositionThatCarriesTheResult(): void
    {
        $violations = [];

        foreach ($this->packs() as $pack => $dir) {
            $allocationAccounts = [];
            foreach ($this->accountsOf($dir) as $account) {
                $number = $account['number'] ?? null;
                if (is_string($number) && ($account['subtype'] ?? null) === 'result_allocation') {
                    $allocationAccounts[] = $number;
                }
            }

            if ($allocationAccounts === []) {
                continue;
            }

            foreach ($this->mappingsOf($dir) as $file => $mapping) {
                if (($mapping['kind'] ?? null) !== 'balance-sheet') {
                    continue;
                }

                $positions = [];
                $this->collectPositions($mapping, $positions);

                $result = null;
                foreach ($positions as $position) {
                    if ($position['includesNetIncome']) {
                        $result = $position['key'];
                    }
                }

                if ($result === null) {
                    $violations[] = sprintf(
                        '%s (%s): balance sheet has no position with includesNetIncome — the result lands nowhere',
                        $pack,
                        $file,
                    );
                    continue;
                }

                foreach ($allocationAccounts as $account) {
                    $claiming = [];
                    foreach ($positions as $position) {
                        if (in_array($account, $position['accounts'], true)) {
                            $claiming[] = $position['key'];
                        }
                    }

                    if ($claiming === [$result]) {
                        continue;
                    }

                    $violations[] = sprintf(
                        '%s (%s): %s is claimed by %s, not by %s which carries the result',
                        $pack,
                        $file,
                        $account,
                        $claiming === [] ? 'no position' : implode(', ', $claiming),
                        $result,
                    );
                }
            }
        }

        self::assertSame(
            [],
            $violations,
            "An appropriation entry booked against a result-allocation account outside the result\n"
                . "position cancels itself out and leaves the balance sheet reporting an appropriated\n"
                . "result as unappropriated:\n"
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

    /**
     * Every position node of a mapping, with the account numbers it claims. `coveredAccounts`
     * answers "does the mapping cover this account at all"; the rule above needs to know *which*
     * position does.
     *
     * @param mixed                                                                 $node
     * @param list<array{key: string, accounts: list<string>, includesNetIncome: bool}> $positions
     */
    private function collectPositions(mixed $node, array &$positions): void
    {
        if (!is_array($node)) {
            return;
        }

        $key = $node['key'] ?? null;
        if (is_string($key) && (is_array($node['accounts'] ?? null) || ($node['includesNetIncome'] ?? null) === true)) {
            $covered = [];
            $this->collect(['key' => $key, 'accounts' => is_array($node['accounts'] ?? null) ? $node['accounts'] : []], $covered);
            $positions[] = [
                'key' => $key,
                'accounts' => array_values(array_unique($covered)),
                'includesNetIncome' => ($node['includesNetIncome'] ?? null) === true,
            ];
        }

        foreach ($node as $child) {
            $this->collectPositions($child, $positions);
        }
    }
}
