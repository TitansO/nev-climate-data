<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\NdcTarget;
use App\Repository\FundingRepository;
use App\Repository\NdcTargetRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * C3.1 : "Gap Detector" - GET /api/analytics/gaps. gap = besoin CDN
 * déclaré - montant réel reçu, jamais l'inverse (un gap positif = sous-
 * financé, négatif = déjà couvert au-delà du besoin déclaré). Restreint
 * strictement aux couples (pays[, secteur]) portant un
 * `NdcTarget::$declaredNeedUsd` non NULL (App\Repository\NdcTargetRepository::findAllWithDeclaredNeed()) -
 * jamais un besoin inventé pour un pays qui n'en a pas publié un
 * chiffrable, même raisonnement "available uniquement si réel" que
 * NdcAlignmentScoreService (C2.6).
 *
 * "by=sector"/"by=country-sector" ne peuvent produire de résultat que
 * pour une cible CDN sectorielle (`NdcTarget::$sector` non NULL) - à ce
 * jour (2026-09-22) les seules cibles chiffrées connues (NdcTargetFixtures,
 * Nigeria/Maroc) sont économie entière, donc ces deux modes renvoient une
 * liste vide tant qu'aucune cible sectorielle n'est saisie ; le calcul
 * reste correct et prêt pour quand ce sera le cas.
 */
final class GapDetectionService
{
    private const CACHE_TTL_SECONDS = 900;
    private const CACHE_KEY_PREFIX = 'analytics_gaps';

    /**
     * Seuils d'alerte sur le ratio de couverture (reçu / besoin déclaré) -
     * choix éditorial documenté ici plutôt qu'un standard externe : moins
     * d'un quart du besoin couvert = "critical", moins des trois quarts =
     * "moderate", le reste (y compris un pays déjà couvert au-delà de son
     * besoin déclaré) = "low".
     */
    private const SEVERITY_CRITICAL_MAX_RATIO = 0.25;
    private const SEVERITY_MODERATE_MAX_RATIO = 0.75;

    public function __construct(
        private readonly NdcTargetRepository $ndcTargetRepository,
        private readonly FundingRepository $fundingRepository,
        #[Autowire(service: 'cache.analytics')]
        private readonly CacheInterface $cache,
    ) {
    }

    /**
     * @return list<array{country: string, countryIsoCode: string, sector: ?string, declaredNeedUsd: float, receivedUsd: float, gapUsd: float, coverageRatio: float, severity: string}>
     */
    public function getGaps(string $by): array
    {
        if (!\in_array($by, ['country', 'sector', 'country-sector'], true)) {
            throw new BadRequestHttpException('Invalid value for parameter "by": must be one of country, sector, country-sector.');
        }

        return $this->cache->get(self::CACHE_KEY_PREFIX.'|by='.$by, function (ItemInterface $item) use ($by): array {
            $item->expiresAfter(self::CACHE_TTL_SECONDS);

            $targets = $this->ndcTargetRepository->findAllWithDeclaredNeed();
            $rows = match ($by) {
                'country' => $this->computeByCountry($targets),
                'sector' => $this->computeBySector($targets),
                default => $this->computeByCountrySector($targets),
            };

            usort($rows, static fn (array $a, array $b): int => $b['gapUsd'] <=> $a['gapUsd']);

            return $rows;
        });
    }

    /**
     * @param list<NdcTarget> $targets
     *
     * @return list<array{country: string, countryIsoCode: string, sector: ?string, declaredNeedUsd: float, receivedUsd: float, gapUsd: float, coverageRatio: float, severity: string}>
     */
    private function computeByCountry(array $targets): array
    {
        $rows = [];
        foreach ($targets as $target) {
            if (null !== $target->getSector()) {
                continue; // by=country compare une cible économie entière, pas sectorielle.
            }

            $country = $target->getCountry();
            $received = (float) ($this->fundingRepository->findTotalReceivedForCountry((int) $country->getId()) ?? '0');
            $rows[] = self::toRow($country->getName(), $country->getIsoCode(), null, (float) $target->getDeclaredNeedUsd(), $received);
        }

        return $rows;
    }

    /**
     * @param list<NdcTarget> $targets
     *
     * @return list<array{country: string, countryIsoCode: string, sector: ?string, declaredNeedUsd: float, receivedUsd: float, gapUsd: float, coverageRatio: float, severity: string}>
     */
    private function computeBySector(array $targets): array
    {
        // Plusieurs pays peuvent chacun déclarer un besoin sectoriel pour le
        // même secteur - agrégés en un seul besoin total par secteur avant
        // comparaison, jamais comparés pays par pays (by=country-sector
        // couvre déjà ce niveau de détail).
        $needBySectorId = [];
        $sectorNameById = [];
        foreach ($targets as $target) {
            $sector = $target->getSector();
            if (null === $sector) {
                continue;
            }
            $sectorId = (int) $sector->getId();
            $needBySectorId[$sectorId] = ($needBySectorId[$sectorId] ?? 0.0) + (float) $target->getDeclaredNeedUsd();
            $sectorNameById[$sectorId] = $sector->getName();
        }

        $rows = [];
        foreach ($needBySectorId as $sectorId => $declaredNeedUsd) {
            $received = (float) ($this->fundingRepository->findTotalReceivedForSector($sectorId) ?? '0');
            $rows[] = self::toRow(null, null, $sectorNameById[$sectorId], $declaredNeedUsd, $received);
        }

        return $rows;
    }

    /**
     * @param list<NdcTarget> $targets
     *
     * @return list<array{country: string, countryIsoCode: string, sector: ?string, declaredNeedUsd: float, receivedUsd: float, gapUsd: float, coverageRatio: float, severity: string}>
     */
    private function computeByCountrySector(array $targets): array
    {
        $rows = [];
        foreach ($targets as $target) {
            $sector = $target->getSector();
            if (null === $sector) {
                continue;
            }

            $country = $target->getCountry();
            $received = (float) ($this->fundingRepository->findTotalReceivedForCountryAndSector((int) $country->getId(), (int) $sector->getId()) ?? '0');
            $rows[] = self::toRow($country->getName(), $country->getIsoCode(), $sector->getName(), (float) $target->getDeclaredNeedUsd(), $received);
        }

        return $rows;
    }

    /**
     * @return array{country: ?string, countryIsoCode: ?string, sector: ?string, declaredNeedUsd: float, receivedUsd: float, gapUsd: float, coverageRatio: float, severity: string}
     */
    private static function toRow(?string $country, ?string $countryIsoCode, ?string $sector, float $declaredNeedUsd, float $receivedUsd): array
    {
        $gapUsd = $declaredNeedUsd - $receivedUsd;
        $coverageRatio = $declaredNeedUsd > 0.0 ? $receivedUsd / $declaredNeedUsd : 1.0;

        return [
            'country' => $country,
            'countryIsoCode' => $countryIsoCode,
            'sector' => $sector,
            'declaredNeedUsd' => $declaredNeedUsd,
            'receivedUsd' => $receivedUsd,
            'gapUsd' => $gapUsd,
            'coverageRatio' => round($coverageRatio, 4),
            'severity' => self::severityFor($coverageRatio),
        ];
    }

    private static function severityFor(float $coverageRatio): string
    {
        return match (true) {
            $coverageRatio < self::SEVERITY_CRITICAL_MAX_RATIO => 'critical',
            $coverageRatio < self::SEVERITY_MODERATE_MAX_RATIO => 'moderate',
            default => 'low',
        };
    }
}
