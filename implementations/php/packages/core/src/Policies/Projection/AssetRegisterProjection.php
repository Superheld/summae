<?php

declare(strict_types=1);

namespace Summae\Core\Policies\Projection;

use Summae\Core\Policies\Expansion\Assets\Asset;
use Summae\Core\Policies\Expansion\Assets\AssetRoute;
use Summae\Core\Port\AssetRepository;
use Summae\Core\Substrate\CalendarDate;

/**
 * Anlageverzeichnis (Pflicht auch bei EÜR, § 4 Abs. 3 S. 5 EStG).
 * Sortierung: Zugangsdatum, dann ID (deterministisch).
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

            $rows[] = $row;
        }

        return ['assets' => $rows];
    }
}
