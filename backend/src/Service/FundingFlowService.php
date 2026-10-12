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
 * Trois sauts (bailleur -> objectif climatique -> pays -> secteur), chacun
 * agrégé en SQL par FundingRepository (SUM/GROUP BY, jamais en boucle PHP).
 * Le top-N par couche + le regroupement "Autres" est en revanche une
 * opération de mise en forme de graphe, faite ici en PHP :
 *
 *   1. Chaque couche à cardinalité ouverte (bailleur, pays, secteur) est
 *      classée par son propre total agrégé, et seuls les $criteria->topN
 *      premiers gardent leur identité propre - le reste est fondu dans un
 *      unique nœud "Autres" pour cette couche (jamais un nœud "Autres" par
 *      ligne source, un seul agrégé). La couche "objectif" n'est PAS
 *      soumise à ce top-N : elle n'a que 2 valeurs possibles
 *      (Adaptation/Atténuation, voir objectiveKeyForSector() ci-dessous),
 *      jamais bucketée dans un nœud "Autres objectifs" qui n'aurait jamais
 *      de membre.
 *   2. Un pays doit appartenir au même groupe (lui-même, ou "Autres pays")
 *      des deux côtés où il apparaît (objectif->pays ET pays->secteur) -
 *      sinon un même pays réel se scinderait en deux nœuds visuellement
 *      déconnectés. Le classement "top pays" est donc calculé une seule
 *      fois (sur le total agrégé du saut pays->secteur, qui porte déjà
 *      tout ce qu'il faut) et réutilisé pour les deux sauts où le pays
 *      apparaît.
 *   3. Un nœud "Autres X" (ou un nœud "objectif") n'apparaît que s'il
 *      regroupe réellement au moins une ligne - jamais un nœud vide
 *      fabriqué "au cas où".
 *
 * Audit Prompt Maitre #3 (point 2, 2026-10-12) : la couche "objectif
 * climatique" est un PROXY APPROXIMATIF, pas une vraie classification -
 * voir le docblock de objectiveKeyForSector() ci-dessous pour sa règle
 * exacte et sa limite documentée. Le frontend (SankeyFlow.jsx / sa page
 * d'accueil) affiche un avertissement explicite à ce sujet - ne jamais
 * retirer ce couplage sans retirer l'avertissement en même temps.
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
        $sourceSectorRows = $this->fundingRepository->findSourceToSectorFlowAggregate($criteria);
        $countrySectorRows = $this->fundingRepository->findCountryToSectorFlowAggregate($criteria);

        $sourceTotals = self::totalsBy($sourceSectorRows, static fn (array $row): string => 'source:'.$row['sourceId']);
        $countryTotals = self::totalsBy($countrySectorRows, static fn (array $row): string => $row['isoCode']);
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

        $sourceObjectiveLinks = [];
        foreach ($sourceSectorRows as $row) {
            $sourceKey = 'source:'.$row['sourceId'];
            $inTopSources = \in_array($sourceKey, $topSourceIds, true);
            $sourceNodeId = $inTopSources ? $sourceKey : 'source:other';
            $nodes[$sourceNodeId] = ['id' => $sourceNodeId, 'label' => $inTopSources ? $row['sourceName'] : null, 'layer' => 'source'];

            $objectiveKey = self::objectiveKeyForSector($row['sectorName']);
            $objectiveNodeId = 'objective:'.$objectiveKey;
            $nodes[$objectiveNodeId] ??= ['id' => $objectiveNodeId, 'label' => self::OBJECTIVE_LABELS[$objectiveKey], 'layer' => 'objective'];

            $linkKey = $sourceNodeId.'->'.$objectiveNodeId;
            $sourceObjectiveLinks[$linkKey] ??= ['source' => $sourceNodeId, 'target' => $objectiveNodeId, 'value' => 0.0];
            $sourceObjectiveLinks[$linkKey]['value'] += (float) $row['total'];
        }

        $objectiveCountryLinks = [];
        $countrySectorLinks = [];
        foreach ($countrySectorRows as $row) {
            $inTopCountries = \in_array($row['isoCode'], $topCountryCodes, true);
            $countryNodeId = $inTopCountries ? 'country:'.$row['isoCode'] : 'country:other';
            $nodes[$countryNodeId] ??= ['id' => $countryNodeId, 'label' => $inTopCountries ? $row['countryName'] : null, 'layer' => 'country'];

            $sectorKey = 'sector:'.$row['sectorId'];
            $inTopSectors = \in_array($sectorKey, $topSectorIds, true);
            $sectorNodeId = $inTopSectors ? $sectorKey : 'sector:other';
            $nodes[$sectorNodeId] = ['id' => $sectorNodeId, 'label' => $inTopSectors ? $row['sectorName'] : null, 'layer' => 'sector'];

            $objectiveKey = self::objectiveKeyForSector($row['sectorName']);
            $objectiveNodeId = 'objective:'.$objectiveKey;
            $nodes[$objectiveNodeId] ??= ['id' => $objectiveNodeId, 'label' => self::OBJECTIVE_LABELS[$objectiveKey], 'layer' => 'objective'];

            $objectiveLinkKey = $objectiveNodeId.'->'.$countryNodeId;
            $objectiveCountryLinks[$objectiveLinkKey] ??= ['source' => $objectiveNodeId, 'target' => $countryNodeId, 'value' => 0.0];
            $objectiveCountryLinks[$objectiveLinkKey]['value'] += (float) $row['total'];

            $sectorLinkKey = $countryNodeId.'->'.$sectorNodeId;
            $countrySectorLinks[$sectorLinkKey] ??= ['source' => $countryNodeId, 'target' => $sectorNodeId, 'value' => 0.0];
            $countrySectorLinks[$sectorLinkKey]['value'] += (float) $row['total'];
        }

        foreach (['source' => 'Autres bailleurs', 'country' => 'Autres pays', 'sector' => 'Autres secteurs'] as $layer => $label) {
            $otherId = $layer.':other';
            if (isset($nodes[$otherId])) {
                $nodes[$otherId]['label'] = \sprintf('%s (%d)', $label, $otherCounts[$layer]);
            }
        }

        return [
            'nodes' => array_values($nodes),
            'links' => array_values(array_merge($sourceObjectiveLinks, $objectiveCountryLinks, $countrySectorLinks)),
        ];
    }

    private const OBJECTIVE_LABELS = ['adaptation' => 'Adaptation', 'mitigation' => 'Atténuation'];

    /**
     * Audit Prompt Maitre #3 (point 2, 2026-10-12) : PROXY APPROXIMATIF, pas
     * une vraie classification - "Adaptation" (le secteur) devient
     * l'objectif Adaptation, les 4 autres secteurs (Agriculture, Énergies
     * renouvelables, Transport durable, Foresterie) deviennent tous
     * l'objectif Atténuation par défaut. Le cadre CCNUCC (confirmé via la
     * formation NEV fournie pendant cet audit) définit Adaptation et
     * Atténuation comme deux finalités transversales du financement
     * climatique, indépendantes du secteur - un projet Agriculture avec une
     * composante adaptation réelle sera donc classé "Atténuation" à tort
     * ici. La classification correcte (par projet, via un modèle de
     * langage) existe déjà comme fonctionnalité distincte (C2.7) mais
     * reste bloquée tant que le service `ai/` n'est pas déployé en
     * production (même cause racine que les 2 autres fonctionnalités IA
     * bloquées identifiées lors de l'audit précédent). Ce proxy n'est
     * affiché qu'avec un avertissement explicite côté frontend - ne jamais
     * retirer l'un sans l'autre.
     */
    private static function objectiveKeyForSector(string $sectorName): string
    {
        return 'Adaptation' === $sectorName ? 'adaptation' : 'mitigation';
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
