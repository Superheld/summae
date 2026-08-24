<?php

declare(strict_types=1);

namespace Summae\Core\Policies\Projection;

use Summae\Core\Policies\Expansion\Assets\Asset;
use Summae\Core\Policies\Expansion\Assets\AssetRoute;
use Summae\Core\Port\AssetRepository;
use Summae\Core\Substrate\CalendarDate;

/**
 * Asset register (a jurisdiction may mandate it even under cash-basis accounting).
 * Sorting: acquisition date, then ID (deterministic).
 */
final readonly class AssetRegisterProjection
{
    public function __construct(
        private AssetRepository $assets,
    ) {
    }

    /**
     * @param array<string, mixed> $params asOf?
     *
     * @return array{assets: list<array<string, mixed>>}
     */
    public function compute(array $params): array
    {
        $asOf = is_string($params['asOf'] ?? null) ? CalendarDate::of($params['asOf']) : null;

        $assets = $this->assets->all();
        usort($assets, static function (Asset $a, Asset $b): int {
            $byDate = $a->acquiredOn->compareTo($b->acquiredOn);

            return $byDate !== 0 ? $byDate : $a->id->compareTo($b->id);
        });

        $rows = [];

        foreach ($assets as $asset) {
            if ($asOf !== null && $asset->acquiredOn->isAfter($asOf)) {
                continue;
            }

            $row = $asset->jsonSerialize();
            $row['accumulatedDepreciation'] = $asset->accumulatedDepreciationAt($asOf)->jsonSerialize();
            $row['bookValue'] = $asset->bookValueAt($asOf)->jsonSerialize();

            if ($asset->route === AssetRoute::Capitalize) {
                $row['depreciationSchedule'] = $asset->scheduleSummary();
            }

            // The additional allowance, from the register rather than only from a booking's answer
            // (F-AST-005). bookSpecialDepreciation reports what is left AFTER it ran; before it ran,
            // nothing said whether the asset had elected the allowance at all — so a screen had to
            // offer the form on every capitalised row and let the engine refuse the ones that had not.
            $remaining = $asset->specialDepreciationRemaining();
            $row['specialDepreciation'] = [
                'elected' => $asset->specialDepreciationBudget !== null,
                'allowance' => $asset->specialDepreciationBudget?->jsonSerialize(),
                'remaining' => $remaining?->jsonSerialize(),
            ];

            $rows[] = $row;
        }

        return ['assets' => $rows];
    }
}
