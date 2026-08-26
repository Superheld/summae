<?php

declare(strict_types=1);

namespace Summae\Core\Composition;

use Summae\Core\DomainError;
use Summae\Core\Policies\Expansion\Tax\TaxMechanisms;
use Summae\Core\Substrate\CanonicalJson;

/**
 * Pack resolver (`resolvePack`) — pure resolution of a manifest against a
 * module store into a `ResolvedPack`. Structurally identical counterpart to the
 * Node side (`pack-resolver.ts`) for byte parity. Design:
 * `_bauflow-pack-gate01/design/module-manifest-resolver.md` (§ 3/§ 4).
 *
 * No new engine capability: the resolver selects, checks and folds existing
 * module data into exactly the `ruleModules` bundle that `TenantFactory` consumes
 * hand-fed. Fails loudly (exactly one `E_PACK_*`/`E_POLICY_*`).
 */
final class PackResolver
{
    private const array MODULE_KINDS = ['accounts', 'tax', 'mapping', 'depreciation', 'policy', 'assetAccounts', 'productionCost', 'constraint', 'resultAppropriation'];
    private const array ASSET_ACCOUNT_KEYS = [
        'acquisitionCounterAccount',
        'depreciationExpenseAccount',
        'gwgExpenseAccount',
        'disposalProceedsAccount',
        'disposalLossAccount',
    ];
    private const array ROUNDING_MODES = ['halfUpAwayFromZero', 'halfEven'];
    private const array TAX_GRANULARITIES = ['perVoucher', 'perLine'];

    /**
     * Pick a manifest out of a store by id, optionally pinned to a version.
     *
     * A published `(id, version)` names one bundle for good, so a library may hold several
     * versions of the same pack side by side and an old pin keeps resolving what it always
     * resolved. A request without a version means "current", and current is the **highest**
     * version by code point — the same rule module references already follow. Picking the
     * first match instead would make the answer depend on directory iteration order, which
     * is not the same on two machines.
     *
     * @param list<array<mixed>> $manifests
     *
     * @return array<mixed>
     */
    public static function findManifest(array $manifests, ?string $id, ?string $version = null): array
    {
        $candidates = [];
        foreach ($manifests as $manifest) {
            if (
                self::str($manifest['id'] ?? null) === $id
                && ($version === null || self::str($manifest['version'] ?? null) === $version)
            ) {
                $candidates[] = $manifest;
            }
        }

        if ($candidates === []) {
            throw new DomainError('E_PACK_UNRESOLVED_REF', sprintf(
                'Manifest not found: %s%s',
                $id ?? '',
                $version === null ? '' : '@' . $version,
            ));
        }

        usort(
            $candidates,
            static fn (array $a, array $b): int => strcmp(self::str($a['version'] ?? null) ?? '', self::str($b['version'] ?? null) ?? ''),
        );

        return $candidates[count($candidates) - 1];
    }

    /**
     * @param array<mixed>       $manifest
     * @param list<array<mixed>> $moduleSource
     *
     * @return array<mixed> ResolvedPack
     */
    public static function resolve(array $manifest, array $moduleSource): array
    {
        // 1. Effective module list: overrides (remove/replace) in array order.
        /** @var list<array<mixed>> $effective */
        $effective = [];
        foreach (is_array($manifest['modules'] ?? null) ? $manifest['modules'] : [] as $module) {
            if (is_array($module)) {
                $effective[] = $module;
            }
        }
        foreach (is_array($manifest['overrides'] ?? null) ? $manifest['overrides'] : [] as $override) {
            if (!is_array($override)) {
                continue;
            }
            $ref = is_array($override['ref'] ?? null) ? $override['ref'] : [];
            $idx = self::findRefIndex($effective, $ref);
            if ($idx === null) {
                throw new DomainError('E_PACK_INCOHERENT', 'Override does not match');
            }
            $op = $override['op'] ?? null;
            if ($op === 'remove') {
                array_splice($effective, $idx, 1);
            } elseif ($op === 'replace') {
                $with = is_array($override['with'] ?? null) ? $override['with'] : null;
                if ($with === null) {
                    throw new DomainError('E_PACK_INCOHERENT', 'replace override without "with"');
                }
                $effective[$idx] = $with;
            } else {
                throw new DomainError('E_PACK_INCOHERENT', 'Unknown override operation');
            }
        }

        // 2. Resolve module references (version missing → highest per codepoint).
        /** @var list<array<mixed>> $resolved */
        $resolved = [];
        foreach ($effective as $ref) {
            $kind = self::str($ref['kind'] ?? null);
            $id = self::str($ref['id'] ?? null);
            $version = self::str($ref['version'] ?? null);
            $candidates = [];
            foreach ($moduleSource as $module) {
                if (
                    self::str($module['kind'] ?? null) === $kind
                    && self::str($module['id'] ?? null) === $id
                    && ($version === null || self::str($module['version'] ?? null) === $version)
                ) {
                    $candidates[] = $module;
                }
            }
            if ($candidates === []) {
                throw new DomainError('E_PACK_UNRESOLVED_REF', sprintf('Module not found: %s|%s', $kind ?? '', $id ?? ''));
            }
            usort(
                $candidates,
                static fn (array $a, array $b): int => strcmp(self::str($a['version'] ?? null) ?? '', self::str($b['version'] ?? null) ?? ''),
            );
            $resolved[] = $candidates[count($candidates) - 1];
        }

        // unknown kind → INCOHERENT
        foreach ($resolved as $module) {
            if (!in_array(self::str($module['kind'] ?? null), self::MODULE_KINDS, true)) {
                throw new DomainError('E_PACK_INCOHERENT', 'Unknown module kind');
            }
        }

        // 3. Dependency DAG: missing dependsOn reference → UNRESOLVED_REF (before cycle).
        $present = [];
        foreach ($resolved as $module) {
            $present[self::moduleKey($module)] = true;
        }
        foreach ($resolved as $module) {
            foreach (is_array($module['dependsOn'] ?? null) ? $module['dependsOn'] : [] as $dep) {
                if (!is_array($dep)) {
                    continue;
                }
                $key = self::refKey(self::str($dep['kind'] ?? null) ?? '', self::str($dep['id'] ?? null) ?? '');
                if (!isset($present[$key])) {
                    throw new DomainError('E_PACK_UNRESOLVED_REF', 'dependsOn points to an unlisted module: ' . $key);
                }
            }
        }
        $sorted = self::topoSort($resolved, $present);

        // 4. Fold (topological); collisions → INCOHERENT.
        /** @var list<array<mixed>> $accounts */
        $accounts = [];
        $accountNumbers = [];
        /** @var list<array<mixed>> $taxCodes */
        $taxCodes = [];
        $taxCodeCodes = [];
        /** @var list<array<mixed>> $mappings */
        $mappings = [];
        $mappingIds = [];
        /** @var array<mixed>|null $assetAccounts */
        $assetAccounts = null;
        /** @var array<mixed>|null $depreciation */
        $depreciation = null;
        /** @var array<mixed>|null $productionCost */
        $productionCost = null;
        $resultAppropriation = null;
        /** @var list<array<mixed>> $dimensionRules */
        $dimensionRules = [];
        /** @var array<mixed>|null $packPolicyModule */
        $packPolicyModule = null;

        foreach ($sorted as $module) {
            $data = is_array($module['data'] ?? null) ? $module['data'] : [];
            switch (self::str($module['kind'] ?? null)) {
                case 'accounts':
                    foreach (is_array($data['accounts'] ?? null) ? $data['accounts'] : [] as $account) {
                        if (!is_array($account)) {
                            continue;
                        }
                        $number = self::str($account['number'] ?? null) ?? '';
                        if (isset($accountNumbers[$number])) {
                            throw new DomainError('E_PACK_INCOHERENT', 'Duplicate account number: ' . $number);
                        }
                        $accountNumbers[$number] = true;
                        $accounts[] = $account;
                    }
                    break;
                case 'tax':
                    foreach (is_array($data['taxCodes'] ?? null) ? $data['taxCodes'] : [] as $taxCode) {
                        if (!is_array($taxCode)) {
                            continue;
                        }
                        $code = self::str($taxCode['code'] ?? null) ?? '';
                        if (isset($taxCodeCodes[$code])) {
                            throw new DomainError('E_PACK_INCOHERENT', 'Duplicate taxCode.code: ' . $code);
                        }
                        $taxCodeCodes[$code] = true;
                        $taxCodes[] = $taxCode;
                    }
                    break;
                case 'mapping':
                    $mapping = is_array($data['mapping'] ?? null) ? $data['mapping'] : null;
                    if ($mapping === null) {
                        break;
                    }
                    $id = self::str($mapping['id'] ?? null) ?? '';
                    if (isset($mappingIds[$id])) {
                        throw new DomainError('E_PACK_INCOHERENT', 'Duplicate mapping.id: ' . $id);
                    }
                    $mappingIds[$id] = true;
                    $mappings[] = $mapping;
                    break;
                case 'assetAccounts':
                    $assetAccounts = $data;
                    break;
                case 'depreciation':
                    $depreciation = $data;
                    break;
                case 'productionCost':
                    $productionCost = $data;
                    break;
                case 'resultAppropriation':
                    $resultAppropriation = $data;
                    break;
                case 'constraint':
                    // Constraints add up rather than replace: two modules may each contribute rules,
                    // and a later one silently winning would make the pack order significant.
                    foreach (is_array($data['dimensionRules'] ?? null) ? array_values($data['dimensionRules']) : [] as $rule) {
                        if (is_array($rule)) {
                            $dimensionRules[] = $rule;
                        }
                    }
                    break;
                case 'policy':
                    if ($packPolicyModule !== null) {
                        throw new DomainError('E_PACK_INCOHERENT', 'More than one policy module');
                    }
                    $packPolicyModule = is_array($data['packPolicy'] ?? null) ? $data['packPolicy'] : [];
                    break;
            }
        }

        // 5. Referential integrity.
        // I1: taxAccount (+ inputTaxAccount on reverse_charge) exists.
        foreach ($taxCodes as $taxCode) {
            foreach (is_array($taxCode['versions'] ?? null) ? $taxCode['versions'] : [] as $version) {
                if (!is_array($version)) {
                    continue;
                }
                $taxAccount = self::str($version['taxAccount'] ?? null);
                if ($taxAccount !== null && !isset($accountNumbers[$taxAccount])) {
                    throw new DomainError('E_PACK_UNRESOLVED_REF', 'taxAccount without account (I1): ' . $taxAccount);
                }
                if (TaxMechanisms::mechanismFor(self::str($version['mechanism'] ?? null) ?? 'standard')->requiresInputTaxAccount()) {
                    $inputTaxAccount = self::str($version['inputTaxAccount'] ?? null);
                    if ($inputTaxAccount !== null && !isset($accountNumbers[$inputTaxAccount])) {
                        throw new DomainError('E_PACK_UNRESOLVED_REF', 'inputTaxAccount without account (I1): ' . $inputTaxAccount);
                    }
                }
            }
        }
        // I3: all five assetAccounts.*Account (+ perClass) exist.
        if ($assetAccounts !== null) {
            $default = is_array($assetAccounts['default'] ?? null) ? $assetAccounts['default'] : [];
            foreach (self::ASSET_ACCOUNT_KEYS as $key) {
                $number = self::str($default[$key] ?? null);
                if ($number === null || !isset($accountNumbers[$number])) {
                    throw new DomainError('E_PACK_UNRESOLVED_REF', 'assetAccounts.' . $key . ' without account (I3)');
                }
            }
            $perClass = is_array($assetAccounts['perClass'] ?? null) ? $assetAccounts['perClass'] : [];
            foreach ($perClass as $cls) {
                if (!is_array($cls)) {
                    continue;
                }
                foreach ($cls as $value) {
                    $number = self::str($value);
                    if ($number !== null && !isset($accountNumbers[$number])) {
                        throw new DomainError('E_PACK_UNRESOLVED_REF', 'assetAccounts.perClass without account (I3): ' . $number);
                    }
                }
            }
        }
        // I8: the appropriation module names accounts that exist — the allocation account and
        // every target it offers. A target pointing at nothing would fail at posting time, deep
        // inside an operation, instead of when the pack is composed.
        if ($resultAppropriation !== null) {
            $allocation = self::str($resultAppropriation['allocationAccount'] ?? null);
            if ($allocation === null || !isset($accountNumbers[$allocation])) {
                throw new DomainError('E_PACK_UNRESOLVED_REF', 'resultAppropriation.allocationAccount without account (I8)');
            }
            $targets = is_array($resultAppropriation['targets'] ?? null) ? $resultAppropriation['targets'] : [];
            if ($targets === []) {
                throw new DomainError('E_PACK_INCOHERENT', 'resultAppropriation without a single target (I8)');
            }
            foreach ($targets as $name => $target) {
                $number = is_array($target) ? self::str($target['account'] ?? null) : null;
                if ($number === null || !isset($accountNumbers[$number])) {
                    throw new DomainError('E_PACK_UNRESOLVED_REF', 'resultAppropriation.targets.' . (string) $name . ' without account (I8)');
                }
            }
        }
        // I2: every mapping selector hits >= 1 account.
        $numbers = array_keys($accountNumbers);
        foreach ($mappings as $mapping) {
            self::checkMappingSelectors($mapping, $accountNumbers, $numbers);
        }
        // I4: every taxCode referenced by the manifest is provided by a tax module.
        foreach (is_array($manifest['taxCodes'] ?? null) ? $manifest['taxCodes'] : [] as $code) {
            if (is_string($code) && !isset($taxCodeCodes[$code])) {
                throw new DomainError('E_PACK_UNRESOLVED_REF', 'Manifest taxCode without tax module (I4): ' . $code);
            }
        }

        // 6. packPolicy: manifest copy == resolved policy module + value ranges.
        $manifestPolicy = is_array($manifest['packPolicy'] ?? null) ? $manifest['packPolicy'] : [];
        $effectivePolicy = $packPolicyModule ?? $manifestPolicy;
        self::validatePolicyValues($effectivePolicy);
        if ($packPolicyModule !== null && !self::samePolicy($manifestPolicy, $packPolicyModule)) {
            throw new DomainError('E_POLICY_INVALID', 'Manifest packPolicy deviates from the policy module');
        }

        $manifestId = self::str($manifest['id'] ?? null) ?? '';
        $manifestVersion = self::str($manifest['version'] ?? null) ?? '';
        $taxCodeList = is_array($manifest['taxCodes'] ?? null)
            ? array_values($manifest['taxCodes'])
            : array_map(static fn (array $t): string => self::str($t['code'] ?? null) ?? '', $taxCodes);

        $profile = [
            'id' => $manifestId,
            'name' => self::str($manifest['name'] ?? null) ?? $manifestId,
            'version' => $manifestVersion,
            'chartOfAccounts' => $manifestId . '-coa',
            'taxCodes' => $taxCodeList,
            'mappings' => array_map(static fn (array $m): string => self::str($m['id'] ?? null) ?? '', $mappings),
            'defaults' => is_array($manifest['defaults'] ?? null) ? $manifest['defaults'] : [],
        ];

        $resolvedPack = [
            'id' => $manifestId,
            'version' => $manifestVersion,
            'chartOfAccounts' => ['accounts' => $accounts],
            'taxCodes' => $taxCodes,
            'mappings' => $mappings,
            'assetAccounts' => $assetAccounts,
            'depreciation' => $depreciation,
            'productionCost' => $productionCost,
            'resultAppropriation' => $resultAppropriation,
            'dimensionRules' => $dimensionRules,
            'packPolicy' => $effectivePolicy,
            'profile' => $profile,
        ];
        $resolvedPack['contentDigest'] = self::contentDigest($resolvedPack);

        return $resolvedPack;
    }

    /**
     * Fingerprint of everything a resolution produced.
     *
     * `version` is written by hand and can therefore lag behind, or stay put while the modules
     * underneath it move — which is exactly how one version number came to name several different
     * bundles. This one is derived, so nobody can forget to change it: two resolutions carrying the
     * same digest are the same pack, and the same version with two digests is a broken promise
     * somebody can now see. The version is part of the input, so a pure relabel changes it too.
     *
     * @param array<mixed> $resolvedPack without the digest itself
     */
    private static function contentDigest(array $resolvedPack): string
    {
        return hash('sha256', CanonicalJson::encode($resolvedPack));
    }

    /**
     * Converts a ResolvedPack into the TenantFactory's `ruleModules` bundle.
     *
     * @param array<mixed> $pack
     *
     * @return array<string, mixed>
     */
    public static function ruleModulesFromResolved(array $pack): array
    {
        $profile = is_array($pack['profile'] ?? null) ? $pack['profile'] : [];
        $coa = is_array($pack['chartOfAccounts'] ?? null) ? $pack['chartOfAccounts'] : [];

        // assetAccounts: resolver I3 validates the `default` form ({default:{accounts}}); the AssetService
        // reads the accounts flat → unpack to the flat form here (pack-path parity with the inline path).
        $aa = is_array($pack['assetAccounts'] ?? null) ? $pack['assetAccounts'] : [];
        $assetAccounts = is_array($aa['default'] ?? null) ? $aa['default'] : $aa;
        // depreciation data (gwgThresholds, usefulLife) the AssetService reads top-level → spread.
        /** @var array<string, mixed> $depreciation */
        $depreciation = is_array($pack['depreciation'] ?? null) ? $pack['depreciation'] : [];

        return [
            // The identity travels with the bundle so a tenant can say which pack it runs on
            // (systemDescription / F-IO-007). Everything else here is rules; this is provenance.
            'pack' => [
                'id' => $pack['id'] ?? null,
                'version' => $pack['version'] ?? null,
                'contentDigest' => $pack['contentDigest'] ?? null,
            ],
            'profiles' => [$profile],
            'chartsOfAccounts' => [[
                'id' => self::str($profile['chartOfAccounts'] ?? null) ?? '',
                'accounts' => is_array($coa['accounts'] ?? null) ? $coa['accounts'] : [],
            ]],
            'taxCodes' => is_array($pack['taxCodes'] ?? null) ? $pack['taxCodes'] : [],
            'mappings' => is_array($pack['mappings'] ?? null) ? $pack['mappings'] : [],
            'assetAccounts' => $assetAccounts,
            ...$depreciation,
            // Not spread like depreciation: the CostingService reads it under its own key, because
            // "treatments" is a word another module could plausibly want too.
            'productionCost' => is_array($pack['productionCost'] ?? null) ? $pack['productionCost'] : null,
            // The appropriation plug: which account the resolution books against, and which targets
            // the jurisdiction offers. A pack that stays silent simply does not support the operation.
            'resultAppropriation' => is_array($pack['resultAppropriation'] ?? null) ? $pack['resultAppropriation'] : null,
            // The first constraint plug: which accounts may not be posted without which dimension.
            'dimensionRules' => is_array($pack['dimensionRules'] ?? null) ? array_values($pack['dimensionRules']) : [],
            'packPolicy' => is_array($pack['packPolicy'] ?? null) ? $pack['packPolicy'] : [],
        ];
    }

    /**
     * @param list<array<mixed>> $list
     * @param array<mixed>       $ref
     */
    private static function findRefIndex(array $list, array $ref): ?int
    {
        $kind = self::str($ref['kind'] ?? null);
        $id = self::str($ref['id'] ?? null);
        foreach ($list as $idx => $entry) {
            if (self::str($entry['kind'] ?? null) === $kind && self::str($entry['id'] ?? null) === $id) {
                return $idx;
            }
        }

        return null;
    }

    /**
     * Kahn-style topological sort; stable tie-break per (kind|id) codepoint.
     *
     * @param list<array<mixed>> $modules
     * @param array<string, bool>        $present
     *
     * @return list<array<mixed>>
     */
    private static function topoSort(array $modules, array $present): array
    {
        $out = [];
        $done = [];
        $remaining = $modules;
        while ($remaining !== []) {
            $ready = [];
            foreach ($remaining as $module) {
                $ok = true;
                foreach (is_array($module['dependsOn'] ?? null) ? $module['dependsOn'] : [] as $dep) {
                    if (!is_array($dep)) {
                        continue;
                    }
                    $key = self::refKey(self::str($dep['kind'] ?? null) ?? '', self::str($dep['id'] ?? null) ?? '');
                    if (!isset($present[$key])) {
                        continue;
                    }
                    if (!isset($done[$key])) {
                        $ok = false;
                        break;
                    }
                }
                if ($ok) {
                    $ready[] = $module;
                }
            }
            if ($ready === []) {
                throw new DomainError('E_PACK_INCOHERENT', 'Dependency cycle');
            }
            usort($ready, static fn (array $a, array $b): int => strcmp(self::moduleKey($a), self::moduleKey($b)));
            $next = $ready[0];
            $out[] = $next;
            $done[self::moduleKey($next)] = true;
            foreach ($remaining as $i => $module) {
                if (self::moduleKey($module) === self::moduleKey($next)) {
                    array_splice($remaining, $i, 1);
                    break;
                }
            }
        }

        return $out;
    }

    /**
     * @param array<mixed> $selector
     */
    private static function selectorText(array $selector): string
    {
        if (is_array($selector['numbers'] ?? null)) {
            $numbers = array_filter($selector['numbers'], is_string(...));

            return 'numbers ' . implode(', ', $numbers);
        }

        return sprintf('range %s–%s', self::str($selector['from'] ?? null) ?? '?', self::str($selector['to'] ?? null) ?? '?');
    }

    /**
     * @param array<mixed>     $mapping
     * @param array<int|string, bool>  $accountNumbers
     * @param list<int|string>         $numbers
     */
    private static function checkMappingSelectors(array $mapping, array $accountNumbers, array $numbers): void
    {
        $mappingId = self::str($mapping['id'] ?? null) ?? '?';

        $visit = static function (array $position) use (&$visit, $accountNumbers, $numbers, $mappingId): void {
            foreach (is_array($position['accounts'] ?? null) ? $position['accounts'] : [] as $selector) {
                if (!is_array($selector)) {
                    continue;
                }
                $hits = 0;
                if (is_array($selector['numbers'] ?? null)) {
                    foreach ($selector['numbers'] as $n) {
                        if (is_string($n) && isset($accountNumbers[$n])) {
                            $hits++;
                        }
                    }
                } else {
                    $from = self::str($selector['from'] ?? null);
                    $to = self::str($selector['to'] ?? null);
                    if ($from !== null && $to !== null) {
                        foreach ($numbers as $n) {
                            // PHP casts numeric array keys to int → back to string before strcmp.
                            $ns = (string) $n;
                            if (strcmp($ns, $from) >= 0 && strcmp($ns, $to) <= 0) {
                                $hits++;
                            }
                        }
                    }
                }
                if ($hits === 0) {
                    // Naming all three is the difference between a five-minute fix and bisecting
                    // the pack: the message used to say only that "a" selector somewhere hit
                    // nothing, in a bundle that can hold hundreds of them across several mappings.
                    $positionKey = self::str($position['key'] ?? null) ?? '?';
                    $selectorText = self::selectorText($selector);

                    throw new DomainError(
                        'E_PACK_UNRESOLVED_REF',
                        sprintf(
                            'Mapping "%s", position "%s": %s hits no account (I2). '
                            . 'Every selector must match at least one account of the chart the pack ships.',
                            $mappingId,
                            $positionKey,
                            $selectorText,
                        ),
                        ['mapping' => $mappingId, 'position' => $positionKey, 'selector' => $selectorText],
                    );
                }
            }
            foreach (is_array($position['children'] ?? null) ? $position['children'] : [] as $child) {
                if (is_array($child)) {
                    $visit($child);
                }
            }
        };
        foreach (is_array($mapping['positions'] ?? null) ? $mapping['positions'] : [] as $position) {
            if (is_array($position)) {
                $visit($position);
            }
        }
    }

    /**
     * @param array<mixed> $policy
     */
    private static function validatePolicyValues(array $policy): void
    {
        $roundingMode = self::str($policy['roundingMode'] ?? null);
        if ($roundingMode === null || !in_array($roundingMode, self::ROUNDING_MODES, true)) {
            throw new DomainError('E_POLICY_INVALID', 'Invalid roundingMode');
        }
        $granularity = self::str($policy['taxRoundingGranularity'] ?? null);
        if ($granularity === null || !in_array($granularity, self::TAX_GRANULARITIES, true)) {
            throw new DomainError('E_POLICY_INVALID', 'Invalid taxRoundingGranularity');
        }
        $scale = $policy['currencyScale'] ?? null;
        if (!is_int($scale) || $scale < 0 || $scale > 4) {
            throw new DomainError('E_POLICY_INVALID', 'currencyScale outside 0–4');
        }
    }

    /**
     * @param array<mixed> $a
     * @param array<mixed> $b
     */
    private static function samePolicy(array $a, array $b): bool
    {
        return ($a['roundingMode'] ?? null) === ($b['roundingMode'] ?? null)
            && ($a['taxRoundingGranularity'] ?? null) === ($b['taxRoundingGranularity'] ?? null)
            && ($a['currencyScale'] ?? null) === ($b['currencyScale'] ?? null);
    }

    /**
     * @param array<mixed> $module
     */
    private static function moduleKey(array $module): string
    {
        return self::refKey(self::str($module['kind'] ?? null) ?? '', self::str($module['id'] ?? null) ?? '');
    }

    private static function refKey(string $kind, string $id): string
    {
        return $kind . '|' . $id;
    }

    private static function str(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
