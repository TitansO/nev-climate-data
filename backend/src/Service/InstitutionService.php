<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Source;
use App\Repository\FundingRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * C1.4 : fiche institution (bailleur) - GET /api/institutions/{sourceId}.
 * Portefeuille (montant total, nombre d'enregistrements, pays/secteurs
 * touchés), répartition par secteur et par pays, évolution annuelle, et
 * deux qualificatifs demandés par le palier C1 :
 *   - "fiabilité" = Source::reliability, un champ déjà existant (jamais
 *     recalculé ici) ;
 *   - "transparence" = la fraîcheur de la dernière collecte réelle pour
 *     cette source (même notion que la tuile C1.1, scoped à une seule
 *     source) - une donnée réelle et vérifiable, jamais un score fabriqué.
 *
 * Même pool de cache dédié que AnalyticsService ("cache.analytics"), même
 * TTL (900s) - une fiche institution agrège les mêmes données (Funding),
 * au même coût de recalcul que les agrégats de A2.5.
 */
final class InstitutionService
{
    private const CACHE_TTL_SECONDS = 900;
    private const CACHE_KEY_PREFIX = 'institution_profile_';

    public function __construct(
        private readonly FundingRepository $fundingRepository,
        #[Autowire(service: 'cache.analytics')]
        private readonly CacheInterface $cache,
    ) {
    }

    /**
     * @return array{
     *     id: int, name: string, type: string, reliability: string,
     *     portfolio: array{totalAmountUsd: float, fundingRecordCount: int, countriesFundedCount: int, sectorsFundedCount: int}|null,
     *     sectorBreakdown: list<array{sector: string, amount: float, percentage: float}>,
     *     countryBreakdown: list<array{isoCode: string, country: string, amount: float, percentage: float}>,
     *     yearlyTrend: list<array{period: int, amount: float}>,
     *     transparency: array{lastCollectedAt: ?string, daysSinceCollection: ?int}
     * }
     */
    public function getProfile(Source $source): array
    {
        $sourceId = $source->getId();

        return $this->cache->get(self::CACHE_KEY_PREFIX.$sourceId, function (ItemInterface $item) use ($source, $sourceId): array {
            $item->expiresAfter(self::CACHE_TTL_SECONDS);

            $portfolioRow = $this->fundingRepository->findInstitutionPortfolioSummary($sourceId);

            return [
                'id' => $sourceId,
                'name' => $source->getName(),
                'type' => $source->getType()->value,
                'reliability' => $source->getReliability()->value,
                'portfolio' => null === $portfolioRow ? null : [
                    'totalAmountUsd' => (float) $portfolioRow['totalAmount'],
                    'fundingRecordCount' => $portfolioRow['fundingRecordCount'],
                    'countriesFundedCount' => $portfolioRow['countriesFundedCount'],
                    'sectorsFundedCount' => $portfolioRow['sectorsFundedCount'],
                ],
                'sectorBreakdown' => self::sectorBreakdownWithPercentages($this->fundingRepository->findInstitutionSectorBreakdown($sourceId)),
                'countryBreakdown' => self::countryBreakdownWithPercentages($this->fundingRepository->findInstitutionCountryBreakdown($sourceId)),
                'yearlyTrend' => array_map(
                    static fn (array $row): array => ['period' => (int) $row['year'], 'amount' => (float) $row['total']],
                    $this->fundingRepository->findInstitutionYearlyTrend($sourceId),
                ),
                'transparency' => self::transparencyFrom($this->fundingRepository->findLastCollectionDateForSource($sourceId)),
            ];
        });
    }

    /**
     * @param list<array{sectorId: int, sectorName: string, total: string}> $rows
     *
     * @return list<array{sector: string, amount: float, percentage: float}>
     */
    private static function sectorBreakdownWithPercentages(array $rows): array
    {
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
     * @param list<array{isoCode: string, countryName: string, total: string}> $rows
     *
     * @return list<array{isoCode: string, country: string, amount: float, percentage: float}>
     */
    private static function countryBreakdownWithPercentages(array $rows): array
    {
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

    /**
     * @return array{lastCollectedAt: ?string, daysSinceCollection: ?int}
     */
    private static function transparencyFrom(?\DateTimeImmutable $lastCollectedAt): array
    {
        if (null === $lastCollectedAt) {
            return ['lastCollectedAt' => null, 'daysSinceCollection' => null];
        }

        return [
            'lastCollectedAt' => $lastCollectedAt->format('Y-m-d'),
            'daysSinceCollection' => (int) (new \DateTimeImmutable())->diff($lastCollectedAt)->days,
        ];
    }
}
