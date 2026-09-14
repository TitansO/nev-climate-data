<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Enum\FundingType;
use App\Entity\Enum\ValidationStatus;
use App\Repository\EmissionRepository;
use App\Repository\FundingRepository;
use App\Repository\SectorRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Server-side aggregates for the analytics charts on visualizations.html
 * (A2.5) and the Hero stats strip on index.html (A2.7). Controllers stay
 * thin (App\Controller\AnalyticsController just calls these methods) and
 * every aggregate is computed in SQL via App\Repository\FundingRepository
 * (SUM/GROUP BY/COUNT), never by loading Funding rows into PHP and summing
 * them in a loop.
 *
 * Each method is wrapped in the "cache.analytics" pool (a dedicated Redis
 * pool - see config/packages/cache.yaml - kept separate from the default
 * "cache.app" pool so this never shares storage with, or changes the
 * backend of, the login-throttling rate limiter). CacheInterface::get()
 * is Symfony's standard get-or-compute contract: a cache hit returns
 * immediately, a miss runs the callback, stores the result for
 * self::CACHE_TTL_SECONDS, and returns it - the exact HIT/MISS/store flow
 * A2.5 describes, expressed through the framework's own cache contract
 * rather than manual Redis calls.
 *
 * No query parameters exist on any of these endpoints (visualizations.html
 * has no filter UI to drive them - see the A2.5/A2.6 report), so every cache
 * key here is a fixed string; nothing user-supplied ever enters a key.
 */
final class AnalyticsService
{
    /** 15 minutes, exactly as required by A2.5. */
    private const CACHE_TTL_SECONDS = 900;

    private const CACHE_KEY_FINANCING_TRENDS = 'analytics_financing_trends';
    private const CACHE_KEY_SECTOR_DISTRIBUTION = 'analytics_sector_distribution';
    private const CACHE_KEY_COUNTRY_DISTRIBUTION = 'analytics_country_distribution';
    private const CACHE_KEY_CO2_REDUCTION = 'analytics_co2_reduction';
    private const CACHE_KEY_HERO_STATS = 'analytics_hero_stats';
    private const CACHE_KEY_DATA_FRESHNESS = 'analytics_data_freshness';

    // C1.1 : au-delà de ce nombre de jours sans nouvelle collecte, une
    // source est signalée comme "en retard" plutôt que simplement
    // "dernière collecte le...". Choisi un peu au-dessus du cycle
    // trimestriel (~91 jours, voir runbooks.md §4) de tous les
    // connecteurs Volet B, pour ne jamais signaler comme en retard une
    // source qui n'est simplement pas encore retombée sur sa prochaine
    // collecte planifiée.
    private const STALE_THRESHOLD_DAYS = 100;

    public function __construct(
        private readonly FundingRepository $fundingRepository,
        private readonly SectorRepository $sectorRepository,
        private readonly EmissionRepository $emissionRepository,
        #[Autowire(service: 'cache.analytics')]
        private readonly CacheInterface $cache,
    ) {
    }

    /**
     * @return list<array{period: int, public: float, private: float, multilateral: float, total: float}>
     */
    public function getFinancingTrends(): array
    {
        return $this->cache->get(self::CACHE_KEY_FINANCING_TRENDS, function (ItemInterface $item): array {
            $item->expiresAfter(self::CACHE_TTL_SECONDS);

            return $this->computeFinancingTrends();
        });
    }

    /**
     * @return list<array{sector: string, amount: float, percentage: float}>
     */
    public function getSectorDistribution(): array
    {
        return $this->cache->get(self::CACHE_KEY_SECTOR_DISTRIBUTION, function (ItemInterface $item): array {
            $item->expiresAfter(self::CACHE_TTL_SECONDS);

            return $this->computeSectorDistribution();
        });
    }

    /**
     * @return list<array{isoCode: string, country: string, amount: float, percentage: float}>
     */
    public function getCountryDistribution(): array
    {
        return $this->cache->get(self::CACHE_KEY_COUNTRY_DISTRIBUTION, function (ItemInterface $item): array {
            $item->expiresAfter(self::CACHE_TTL_SECONDS);

            return $this->computeCountryDistribution();
        });
    }

    /**
     * B3.2: year-over-year variation in total CO2 emissions (Mt), computed
     * only over countries with a *current* Emission row in both of the two
     * most recent years with any data - a country missing one of the two
     * years is excluded from both sides, never treated as a drop to zero
     * ("Absence ≠ zéro", cahier des charges 6.4). Positive = a real
     * reduction; negative = a real increase, reported honestly rather than
     * clamped. Falls back to the same {available: false} shape as before
     * when fewer than 2 years of data exist, or no country has data in
     * both of the 2 most recent years - see
     * docs/superpowers/specs/2026-09-07-b32-mercure-realtime-kpis-design.md.
     *
     * @return array{available: bool, data: array{reductionMt: float, latestYear: int, previousYear: int, countriesCompared: int}|null, reason: string|null}
     */
    public function getCo2Reduction(): array
    {
        return $this->cache->get(self::CACHE_KEY_CO2_REDUCTION, function (ItemInterface $item): array {
            $item->expiresAfter(self::CACHE_TTL_SECONDS);

            $years = $this->emissionRepository->distinctYearsWithCurrentData();
            if (count($years) < 2) {
                return [
                    'available' => false,
                    'data' => null,
                    'reason' => 'Moins de deux années de données d\'émissions disponibles pour calculer une variation.',
                ];
            }

            [$latestYear, $previousYear] = [$years[0], $years[1]];
            $latestTotals = $this->emissionRepository->totalsByYear($latestYear);
            $previousTotals = $this->emissionRepository->totalsByYear($previousYear);
            $commonCountryIds = array_intersect_key($latestTotals, $previousTotals);

            if ([] === $commonCountryIds) {
                return [
                    'available' => false,
                    'data' => null,
                    'reason' => sprintf('Aucun pays avec une donnée d\'émissions à la fois en %d et %d.', $previousYear, $latestYear),
                ];
            }

            $latestSum = array_sum(array_intersect_key($latestTotals, $commonCountryIds));
            $previousSum = array_sum(array_intersect_key($previousTotals, $commonCountryIds));

            return [
                'available' => true,
                'data' => [
                    'reductionMt' => round($previousSum - $latestSum, 3),
                    'latestYear' => $latestYear,
                    'previousYear' => $previousYear,
                    'countriesCompared' => count($commonCountryIds),
                ],
                'reason' => null,
            ];
        });
    }

    /**
     * A2.7. The 4 figures shown in index.html's Hero stats strip:
     * "Pays couverts", "Secteurs suivis", "Données de financement",
     * "Sources actives" - see the A2.7/A2.8 report for why each is defined
     * the way it is (some count Funding coverage, one counts the fixed
     * sector taxonomy). Every count runs in SQL (COUNT/COUNT DISTINCT),
     * never by loading rows into PHP.
     *
     * @return array{countriesCovered: int, sectorsTracked: int, fundingRecords: int, activeSources: int}
     */
    public function getHeroStats(): array
    {
        return $this->cache->get(self::CACHE_KEY_HERO_STATS, function (ItemInterface $item): array {
            $item->expiresAfter(self::CACHE_TTL_SECONDS);

            return [
                'countriesCovered' => $this->fundingRepository->countDistinctCountries(),
                'sectorsTracked' => $this->sectorRepository->count([]),
                // B3.3: only pipeline-validated rows, never A1.6 demo fixtures -
                // countDistinctCountries()/countDistinctSources() already filter
                // this themselves; count() builds its own WHERE from the array.
                'fundingRecords' => $this->fundingRepository->count(['isCurrent' => true, 'validationStatus' => ValidationStatus::Validated]),
                'activeSources' => $this->fundingRepository->countDistinctSources(),
            ];
        });
    }

    /**
     * C1.1 : fraîcheur des données, par source réelle (financements +
     * émissions) - la tuile du futur Dashboard consolidé. `sources` est
     * trié de la source la plus en retard à la plus fraîche (la plus
     * utile à surveiller en premier). `oldestCollectionAt` reprend le
     * `lastCollectedAt` de cette source la plus en retard (`null` s'il
     * n'existe aucune donnée réelle du tout - jamais une date fabriquée).
     *
     * @return array{sources: list<array{name: string, lastCollectedAt: string, daysSinceCollection: int}>, oldestCollectionAt: ?string, staleSourceCount: int}
     */
    public function getDataFreshness(): array
    {
        return $this->cache->get(self::CACHE_KEY_DATA_FRESHNESS, function (ItemInterface $item): array {
            $item->expiresAfter(self::CACHE_TTL_SECONDS);

            $now = new \DateTimeImmutable();
            $rows = [
                ...$this->fundingRepository->findSourceFreshnessAggregate(),
                ...$this->emissionRepository->findSourceFreshnessAggregate(),
            ];

            $sources = array_map(function (array $row) use ($now): array {
                $lastCollectedAt = $row['lastCollectionDate'] instanceof \DateTimeImmutable
                    ? $row['lastCollectionDate']
                    : new \DateTimeImmutable((string) $row['lastCollectionDate']);

                return [
                    'name' => $row['sourceName'],
                    'lastCollectedAt' => $lastCollectedAt->format('Y-m-d'),
                    'daysSinceCollection' => (int) $now->diff($lastCollectedAt)->days,
                ];
            }, $rows);

            usort($sources, static fn (array $a, array $b): int => $b['daysSinceCollection'] <=> $a['daysSinceCollection']);

            return [
                'sources' => $sources,
                'oldestCollectionAt' => $sources[0]['lastCollectedAt'] ?? null,
                'staleSourceCount' => count(array_filter(
                    $sources,
                    static fn (array $source): bool => $source['daysSinceCollection'] > self::STALE_THRESHOLD_DAYS,
                )),
            ];
        });
    }

    /**
     * @return list<array{period: int, public: float, private: float, multilateral: float, total: float}>
     */
    private function computeFinancingTrends(): array
    {
        $byYear = [];

        foreach ($this->fundingRepository->findFinancingTrendsAggregate() as $row) {
            $year = (int) $row['year'];
            $byYear[$year] ??= ['period' => $year, 'public' => 0.0, 'private' => 0.0, 'multilateral' => 0.0, 'total' => 0.0];

            $fundingType = $row['fundingType'] instanceof FundingType ? $row['fundingType']->value : (string) $row['fundingType'];
            $amount = (float) $row['total'];

            if (\array_key_exists($fundingType, $byYear[$year])) {
                $byYear[$year][$fundingType] = $amount;
            }
            $byYear[$year]['total'] += $amount;
        }

        ksort($byYear);

        return array_values($byYear);
    }

    /**
     * @return list<array{sector: string, amount: float, percentage: float}>
     */
    private function computeSectorDistribution(): array
    {
        $rows = $this->fundingRepository->findSectorDistributionAggregate();

        $grandTotal = array_sum(array_map(static fn (array $row): float => (float) $row['total'], $rows));

        return array_map(static function (array $row) use ($grandTotal): array {
            $amount = (float) $row['total'];

            return [
                'sector' => $row['sectorName'],
                'amount' => $amount,
                'percentage' => $grandTotal > 0.0 ? round($amount / $grandTotal * 100, 1) : 0.0,
            ];
        }, $rows);
    }

    /**
     * @return list<array{isoCode: string, country: string, amount: float, percentage: float}>
     */
    private function computeCountryDistribution(): array
    {
        $rows = $this->fundingRepository->findCountryDistributionAggregate();

        $grandTotal = array_sum(array_map(static fn (array $row): float => (float) $row['total'], $rows));

        return array_map(static function (array $row) use ($grandTotal): array {
            $amount = (float) $row['total'];

            return [
                'isoCode' => $row['isoCode'],
                'country' => $row['countryName'],
                'amount' => $amount,
                'percentage' => $grandTotal > 0.0 ? round($amount / $grandTotal * 100, 1) : 0.0,
            ];
        }, $rows);
    }
}
