<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Enum\FundingType;
use App\Repository\FundingRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Server-side aggregates for the 3 analytics charts on visualizations.html
 * (A2.5). Controllers stay thin (App\Controller\AnalyticsController just
 * calls these three methods) and every aggregate is computed in SQL via
 * App\Repository\FundingRepository (SUM/GROUP BY), never by loading Funding
 * rows into PHP and summing them in a loop.
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
 * No query parameters exist on any of the three endpoints (visualizations.html
 * has no filter UI to drive them - see the A2.5/A2.6 report), so every cache
 * key here is a fixed string; nothing user-supplied ever enters a key.
 */
final class AnalyticsService
{
    /** 15 minutes, exactly as required by A2.5. */
    private const CACHE_TTL_SECONDS = 900;

    private const CACHE_KEY_FINANCING_TRENDS = 'analytics_financing_trends';
    private const CACHE_KEY_SECTOR_DISTRIBUTION = 'analytics_sector_distribution';
    private const CACHE_KEY_CO2_REDUCTION = 'analytics_co2_reduction';

    public function __construct(
        private readonly FundingRepository $fundingRepository,
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
     * See the A2.5/A2.6 report for the full reasoning: nothing in the
     * schema, fixtures, or project docs carries emissions data or an
     * emission-conversion factor, and nothing officially defines one. This
     * deliberately does not fabricate a number - it returns a structured
     * "not available" result, which is the honest answer given what the
     * data actually supports, and doubles as the real (not staged) case
     * A2.6's "Donnée non disponible" state is built to handle.
     *
     * @return array{available: false, data: null, reason: string}
     */
    public function getCo2Reduction(): array
    {
        return $this->cache->get(self::CACHE_KEY_CO2_REDUCTION, function (ItemInterface $item): array {
            $item->expiresAfter(self::CACHE_TTL_SECONDS);

            return [
                'available' => false,
                'data' => null,
                'reason' => 'Aucune donnée d\'émissions ni facteur de conversion CO2 n\'existe dans le schéma actuel (Funding/Sector/Source). Ce calcul est prévu au Volet B (pipeline de données réelles), pas au Volet A.',
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
}
