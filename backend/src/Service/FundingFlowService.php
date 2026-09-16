<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\FundingFlowCriteria;
use App\Repository\FundingRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * C1.5 : "Opportunity Flow" Sankey - GET /api/analytics/funding-flows.
 * Deux sauts (bailleur -> pays, pays -> secteur), chacun agrégé en SQL par
 * FundingRepository (SUM/GROUP BY, jamais en boucle PHP). Le top-N par
 * couche + le regroupement "Autres" est en revanche une opération de mise
 * en forme de graphe, faite ici en PHP :
 *
 *   1. Chaque couche (bailleur, pays, secteur) est classée par son propre
 *      total agrégé, et seuls les $criteria->topN premiers gardent leur
 *      identité propre - le reste est fondu dans un unique nœud "Autres"
 *      pour cette couche (jamais un nœud "Autres" par ligne source, un
 *      seul agrégé).
 *   2. Un pays doit appartenir au même groupe (lui-même, ou "Autres pays")
 *      des deux côtés du diagramme (bailleur->pays ET pays->secteur) -
 *      sinon un même pays réel se scinderait en deux nœuds visuellement
 *      déconnectés. Le classement "top pays" est donc calculé une seule
 *      fois (sur le total agrégé du saut bailleur->pays, le plus complet
 *      des deux) et réutilisé pour les deux sauts.
 *   3. Un nœud "Autres X" n'apparaît que s'il regroupe réellement au
 *      moins une ligne exclue du top-N - jamais un nœud vide fabriqué
 *      "au cas où".
 */
final class FundingFlowService
{
    private const CACHE_TTL_SECONDS = 900;
    private const CACHE_KEY_PREFIX = 'analytics_funding_flows';

    public function __construct(
        private readonly FundingRepository $fundingRepository,
        #[Autowire(service: 'cache.analytics')]
        private readonly CacheInterface $cache,
    ) {
    }

    /**
     * @return array{
     *     nodes: list<array{id: string, label: string, layer: string}>,
     *     links: list<array{source: string, target: string, value: float}>
     * }
     */
    public function getFlows(FundingFlowCriteria $criteria): array
    {
        $cacheKey = self::CACHE_KEY_PREFIX.'|'.$criteria->toCacheKeyFragment();

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($criteria): array {
            $item->expiresAfter(self::CACHE_TTL_SECONDS);

            return $this->computeFlows($criteria);
        });
    }

    /**
     * @return array{nodes: list<array{id: string, label: string, layer: string}>, links: list<array{source: string, target: string, value: float}>}
     */
    private function computeFlows(FundingFlowCriteria $criteria): array
    {
        $sourceCountryRows = $this->fundingRepository->findSourceCountryFlowAggregate($criteria);
        $countrySectorRows = $this->fundingRepository->findCountryToSectorFlowAggregate($criteria);

        $sourceTotals = self::totalsBy($sourceCountryRows, static fn (array $row): string => 'source:'.$row['sourceId']);
        $countryTotals = self::totalsBy($sourceCountryRows, static fn (array $row): string => $row['isoCode']);
        $sectorTotals = self::totalsBy($countrySectorRows, static fn (array $row): string => 'sector:'.$row['sectorId']);

        $topSourceIds = self::topKeys($sourceTotals, $criteria->topN);
        $topCountryCodes = self::topKeys($countryTotals, $criteria->topN);
        $topSectorIds = self::topKeys($sectorTotals, $criteria->topN);

        // Counted from the distinct-entity totals above, not incremented
        // per flow row below: a single excluded source/sector can span
        // several rows (one per country/country it also funds), and
        // counting rows there would count that same excluded entity once
        // per row instead of once overall.
        $otherCounts = [
            'source' => \count($sourceTotals) - \count($topSourceIds),
            'country' => \count($countryTotals) - \count($topCountryCodes),
            'sector' => \count($sectorTotals) - \count($topSectorIds),
        ];

        $nodes = [];

        $sourceCountryLinks = [];
        foreach ($sourceCountryRows as $row) {
            $sourceKey = 'source:'.$row['sourceId'];
            $inTopSources = \in_array($sourceKey, $topSourceIds, true);
            $sourceNodeId = $inTopSources ? $sourceKey : 'source:other';
            $nodes[$sourceNodeId] = ['id' => $sourceNodeId, 'label' => $inTopSources ? $row['sourceName'] : null, 'layer' => 'source'];

            $inTopCountries = \in_array($row['isoCode'], $topCountryCodes, true);
            $countryNodeId = $inTopCountries ? 'country:'.$row['isoCode'] : 'country:other';
            $nodes[$countryNodeId] = ['id' => $countryNodeId, 'label' => $inTopCountries ? $row['countryName'] : null, 'layer' => 'country'];

            $linkKey = $sourceNodeId.'->'.$countryNodeId;
            $sourceCountryLinks[$linkKey] ??= ['source' => $sourceNodeId, 'target' => $countryNodeId, 'value' => 0.0];
            $sourceCountryLinks[$linkKey]['value'] += (float) $row['total'];
        }

        $countrySectorLinks = [];
        foreach ($countrySectorRows as $row) {
            $inTopCountries = \in_array($row['isoCode'], $topCountryCodes, true);
            $countryNodeId = $inTopCountries ? 'country:'.$row['isoCode'] : 'country:other';
            // Already counted from the source->country pass above unless
            // this country never appears there (shouldn't happen - every
            // Funding row has both a source and a sector - but guarded
            // rather than assumed.
            $nodes[$countryNodeId] ??= ['id' => $countryNodeId, 'label' => $inTopCountries ? $row['countryName'] : null, 'layer' => 'country'];

            $sectorKey = 'sector:'.$row['sectorId'];
            $inTopSectors = \in_array($sectorKey, $topSectorIds, true);
            $sectorNodeId = $inTopSectors ? $sectorKey : 'sector:other';
            $nodes[$sectorNodeId] = ['id' => $sectorNodeId, 'label' => $inTopSectors ? $row['sectorName'] : null, 'layer' => 'sector'];

            $linkKey = $countryNodeId.'->'.$sectorNodeId;
            $countrySectorLinks[$linkKey] ??= ['source' => $countryNodeId, 'target' => $sectorNodeId, 'value' => 0.0];
            $countrySectorLinks[$linkKey]['value'] += (float) $row['total'];
        }

        foreach (['source' => 'Autres bailleurs', 'country' => 'Autres pays', 'sector' => 'Autres secteurs'] as $layer => $label) {
            $otherId = $layer.':other';
            if (isset($nodes[$otherId])) {
                $nodes[$otherId]['label'] = \sprintf('%s (%d)', $label, $otherCounts[$layer]);
            }
        }

        return [
            'nodes' => array_values($nodes),
            'links' => array_values(array_merge($sourceCountryLinks, $countrySectorLinks)),
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, float> total amount keyed by $keyFn($row)
     */
    private static function totalsBy(array $rows, callable $keyFn): array
    {
        $totals = [];
        foreach ($rows as $row) {
            $key = $keyFn($row);
            $totals[$key] = ($totals[$key] ?? 0.0) + (float) $row['total'];
        }

        return $totals;
    }

    /**
     * @param array<string, float> $totals
     *
     * @return list<string> the $topN keys with the largest totals
     */
    private static function topKeys(array $totals, int $topN): array
    {
        arsort($totals);

        return array_slice(array_keys($totals), 0, $topN);
    }
}
