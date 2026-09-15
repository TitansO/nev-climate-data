<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\CountryDistributionCriteria;
use App\Entity\Enum\FinancingTrendsGroupBy;
use App\Entity\Enum\FundingInstrument;
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
 * Most of these endpoints take no query parameters at all (visualizations.html
 * has no filter UI to drive them - see the A2.5/A2.6 report), so most cache
 * keys here are a fixed string. getCountryDistribution() (C1.2) and
 * getFinancingTrends() (C1.6) are the exceptions - each validated parameter
 * combination gets its own dedicated cache key, built from that method's own
 * DTO/enum, never from unvalidated raw user input.
 */
final class AnalyticsService
{
    /** 15 minutes, exactly as required by A2.5. */
    private const CACHE_TTL_SECONDS = 900;

    // "_v2", not "analytics_financing_trends": C1.6 changed the response
    // envelope for every call to this endpoint (added movingAverageTotal
    // alongside data) - confirmed live that reusing the old key let an
    // already-cached pre-C1.6 entry (a bare array) go straight back out
    // unwrapped for up to CACHE_TTL_SECONDS after deploying this change,
    // since a cache HIT never re-runs the code that would wrap it. A new
    // key guarantees a clean cutover instead of a stale-cache window.
    private const CACHE_KEY_FINANCING_TRENDS = 'analytics_financing_trends_v2';
    private const CACHE_KEY_SECTOR_DISTRIBUTION = 'analytics_sector_distribution';
    private const CACHE_KEY_COUNTRY_DISTRIBUTION = 'analytics_country_distribution';
    private const CACHE_KEY_CO2_REDUCTION = 'analytics_co2_reduction';
    private const CACHE_KEY_HERO_STATS = 'analytics_hero_stats';
    private const CACHE_KEY_DATA_FRESHNESS = 'analytics_data_freshness';
    private const CACHE_KEY_INSTRUMENT_DISTRIBUTION = 'analytics_instrument_distribution';

    // C1.1 : au-delà de ce nombre de jours sans nouvelle collecte, une
    // source est signalée comme "en retard" plutôt que simplement
    // "dernière collecte le...". Choisi un peu au-dessus du cycle
    // trimestriel (~91 jours, voir runbooks.md §4) de tous les
    // connecteurs Volet B, pour ne jamais signaler comme en retard une
    // source qui n'est simplement pas encore retombée sur sa prochaine
    // collecte planifiée.
    private const STALE_THRESHOLD_DAYS = 100;

    /**
     * C1.6: trailing window size for `movingAverageTotal` - 3 years is the
     * shortest window that actually smooths anything (a window of 1 would
     * just echo the raw total back). The first 1-2 periods of any series
     * necessarily average over fewer points than this (there's nothing
     * before them to include) rather than being omitted or padded with a
     * fabricated value.
     */
    private const MOVING_AVERAGE_WINDOW = 3;

    public function __construct(
        private readonly FundingRepository $fundingRepository,
        private readonly SectorRepository $sectorRepository,
        private readonly EmissionRepository $emissionRepository,
        #[Autowire(service: 'cache.analytics')]
        private readonly CacheInterface $cache,
    ) {
    }

    /**
     * C1.6: $groupBy is optional - omitted, `data` rows keep exactly the
     * pre-C1.6 shape (hardcoded public/private/multilateral keys). Passed,
     * `data` rows instead carry a generic `groups` object keyed by that
     * dimension's own labels (sector name / instrument / country ISO code
     * / fundingType). `movingAverageTotal` (a trailing
     * self::MOVING_AVERAGE_WINDOW-period average of each period's grand
     * total) is included either way - an additive field alongside `data`,
     * safe for any existing consumer that only reads the latter. Every
     * parameter combination (including "none") gets its own cache key
     * (self::CACHE_KEY_FINANCING_TRENDS, itself bumped to "_v2" by C1.6 -
     * see its own comment for why a *response-shape* change needs a new
     * key even when the request shape didn't change).
     *
     * @return array{data: list<array<string, mixed>>, movingAverageTotal: list<array{period: int, value: float}>}
     */
    public function getFinancingTrends(?FinancingTrendsGroupBy $groupBy = null): array
    {
        $cacheKey = null === $groupBy
            ? self::CACHE_KEY_FINANCING_TRENDS
            : self::CACHE_KEY_FINANCING_TRENDS.'|groupBy='.$groupBy->value;

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($groupBy): array {
            $item->expiresAfter(self::CACHE_TTL_SECONDS);

            $rows = null === $groupBy ? $this->computeFinancingTrends() : $this->computeFinancingTrendsGroupedBy($groupBy);

            return [
                'data' => $rows,
                'movingAverageTotal' => self::movingAverageOf($rows, self::MOVING_AVERAGE_WINDOW),
            ];
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
     * C1.7 : répartition par instrument financier ("part de
     * concessionnalité") + deux croisements (instrument x secteur,
     * instrument x pays) pour explorer, par exemple, quels secteurs sont
     * majoritairement financés par don plutôt que par prêt. `data` porte
     * les pourcentages (mêmes conventions que getSectorDistribution()) ;
     * `bySector`/`byCountry` sont des montants bruts, non pourcentés (une
     * cellule de croisement n'a pas de "total" naturel à rapporter à un
     * pourcentage sans choisir arbitrairement lequel des deux axes).
     *
     * @return array{
     *     data: list<array{instrument: string, amount: float, percentage: float}>,
     *     bySector: list<array{instrument: string, sector: string, amount: float}>,
     *     byCountry: list<array{instrument: string, isoCode: string, country: string, amount: float}>
     * }
     */
    public function getInstrumentDistribution(): array
    {
        return $this->cache->get(self::CACHE_KEY_INSTRUMENT_DISTRIBUTION, function (ItemInterface $item): array {
            $item->expiresAfter(self::CACHE_TTL_SECONDS);

            return $this->computeInstrumentDistribution();
        });
    }

    /**
     * C1.2: $criteria's sector/instrument/year/fundingType filters are all
     * optional - an all-null instance ($criteria->isEmpty()) keeps the
     * exact pre-C1.2 cache key, so existing unfiltered callers keep hitting
     * the same cached entry; any actual filter combination gets its own
     * dedicated key (CountryDistributionCriteria::toCacheKeyFragment()) so
     * two different filters can never collide or serve each other's data.
     *
     * @return list<array{isoCode: string, country: string, amount: float, percentage: float}>
     */
    public function getCountryDistribution(CountryDistributionCriteria $criteria): array
    {
        // "|", not ":" - Symfony's cache key validator (PSR-6) rejects any
        // key containing "{}()/\@:" (confirmed live: a ":" here threw
        // InvalidArgumentException on every single filtered request).
        $cacheKey = $criteria->isEmpty()
            ? self::CACHE_KEY_COUNTRY_DISTRIBUTION
            : self::CACHE_KEY_COUNTRY_DISTRIBUTION.'|'.$criteria->toCacheKeyFragment();

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($criteria): array {
            $item->expiresAfter(self::CACHE_TTL_SECONDS);

            return $this->computeCountryDistribution($criteria);
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
     * C1.6: same reshaping as computeFinancingTrends() (one row per year,
     * SQL-summed contributions folded together in PHP), generalized to an
     * arbitrary `groups` object instead of hardcoded public/private/
     * multilateral keys - see FundingRepository::findFinancingTrendsAggregateGroupedBy().
     *
     * @return list<array{period: int, groups: array<string, float>, total: float}>
     */
    private function computeFinancingTrendsGroupedBy(FinancingTrendsGroupBy $groupBy): array
    {
        $byYear = [];

        foreach ($this->fundingRepository->findFinancingTrendsAggregateGroupedBy($groupBy) as $row) {
            $year = (int) $row['year'];
            $byYear[$year] ??= ['period' => $year, 'groups' => [], 'total' => 0.0];

            $groupKey = $row['groupKey'] instanceof \BackedEnum ? $row['groupKey']->value : (string) $row['groupKey'];
            $amount = (float) $row['total'];

            $byYear[$year]['groups'][$groupKey] = $amount;
            $byYear[$year]['total'] += $amount;
        }

        ksort($byYear);

        return array_values($byYear);
    }

    /**
     * C1.6: trailing moving average of `total` across consecutive periods
     * ($rows must already be sorted chronologically, as both
     * computeFinancingTrends() and computeFinancingTrendsGroupedBy()
     * guarantee). The first ($window - 1) periods average over fewer than
     * $window points (whatever actually precedes them) rather than being
     * omitted or padded with a fabricated zero.
     *
     * @param list<array{period: int, total: float}> $rows
     *
     * @return list<array{period: int, value: float}>
     */
    private static function movingAverageOf(array $rows, int $window): array
    {
        return array_values(array_map(
            static function (int $index, array $row) use ($rows, $window): array {
                $windowRows = \array_slice($rows, max(0, $index - $window + 1), min($window, $index + 1));
                $average = array_sum(array_map(static fn (array $r): float => (float) $r['total'], $windowRows)) / count($windowRows);

                return ['period' => $row['period'], 'value' => round($average, 2)];
            },
            array_keys($rows),
            $rows,
        ));
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
     * @return array{
     *     data: list<array{instrument: string, amount: float, percentage: float}>,
     *     bySector: list<array{instrument: string, sector: string, amount: float}>,
     *     byCountry: list<array{instrument: string, isoCode: string, country: string, amount: float}>
     * }
     */
    private function computeInstrumentDistribution(): array
    {
        $rows = $this->fundingRepository->findInstrumentDistributionAggregate();
        $grandTotal = array_sum(array_map(static fn (array $row): float => (float) $row['total'], $rows));

        $data = array_map(static function (array $row) use ($grandTotal): array {
            $amount = (float) $row['total'];
            $instrument = $row['instrument'] instanceof FundingInstrument ? $row['instrument']->value : (string) $row['instrument'];

            return [
                'instrument' => $instrument,
                'amount' => $amount,
                'percentage' => $grandTotal > 0.0 ? round($amount / $grandTotal * 100, 1) : 0.0,
            ];
        }, $rows);

        $bySector = array_map(static function (array $row): array {
            $instrument = $row['instrument'] instanceof FundingInstrument ? $row['instrument']->value : (string) $row['instrument'];

            return ['instrument' => $instrument, 'sector' => $row['sectorName'], 'amount' => (float) $row['total']];
        }, $this->fundingRepository->findInstrumentSectorCrossTab());

        $byCountry = array_map(static function (array $row): array {
            $instrument = $row['instrument'] instanceof FundingInstrument ? $row['instrument']->value : (string) $row['instrument'];

            return ['instrument' => $instrument, 'isoCode' => $row['isoCode'], 'country' => $row['countryName'], 'amount' => (float) $row['total']];
        }, $this->fundingRepository->findInstrumentCountryCrossTab());

        return ['data' => $data, 'bySector' => $bySector, 'byCountry' => $byCountry];
    }

    /**
     * @return list<array{isoCode: string, country: string, amount: float, percentage: float}>
     */
    private function computeCountryDistribution(CountryDistributionCriteria $criteria): array
    {
        $rows = $this->fundingRepository->findCountryDistributionAggregate($criteria);

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
