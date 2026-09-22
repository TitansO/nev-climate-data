<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\CountryRepository;
use App\Repository\FundingRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * C3.2 : "Local Finance Tracker" - GET /api/analytics/local-finance.
 * Objectif du plan : proxy par mots-clés du descriptif ("community",
 * "municipal"...) + instrument. Réalisable seulement à moitié dans ce
 * schéma : `Funding` (App\Entity\Funding) est une ligne AGRÉGÉE (un total
 * par pays x secteur x année x source x type), jamais une ligne par
 * activité/transaction individuelle - le titre/la description de chaque
 * activité brute existe un instant côté pipeline (le message Kafka
 * `nev.funding.raw`) avant d'être perdu à l'agrégation
 * (`pipeline/common/funding_validator.py::upsert_funding()`), jamais
 * persisté. Décision utilisateur (2026-09-22) : proxy réduit à son seul
 * signal disponible - `instrument = grant` ET montant sous
 * self::SMALL_AMOUNT_THRESHOLD_USD - jamais les mots-clés, faute de texte
 * à chercher. Assumé comme une estimation basse (un don plus important
 * peut très bien atteindre le niveau local ; l'inverse aussi) plutôt
 * qu'une mesure, voir `methodology` dans la réponse.
 */
final class LocalFinanceService
{
    private const CACHE_TTL_SECONDS = 900;
    private const CACHE_KEY_PREFIX = 'analytics_local_finance';

    /**
     * Seuil illustratif, pas une donnée sourcée : un don de moins d'1M USD
     * est plus vraisemblablement un projet d'échelle locale/communautaire
     * qu'un appui budgétaire ou un grand programme national. À ajuster si
     * une vraie granularité par projet devient disponible (voir le
     * docblock de la classe).
     */
    private const SMALL_AMOUNT_THRESHOLD_USD = 1_000_000.0;

    private const METHODOLOGY = 'Estimation par proxy : part des financements validés dont l\'instrument est un don ('
        .'grant) et le montant est inférieur à 1 000 000 USD - le seul signal disponible dans ce jeu de données '
        .'(aucune description d\'activité individuelle n\'est conservée après agrégation). Ne reflète pas une '
        .'vérification de destination réelle des fonds.';

    public function __construct(
        private readonly FundingRepository $fundingRepository,
        private readonly CountryRepository $countryRepository,
        #[Autowire(service: 'cache.analytics')]
        private readonly CacheInterface $cache,
    ) {
    }

    /**
     * @return array{data: list<array{country: string, countryIsoCode: string, totalUsd: float, estimatedLocalUsd: float, localSharePercent: float}>, methodology: string}
     */
    public function getLocalFinance(?string $countryIsoCode): array
    {
        $countryId = null;
        if (null !== $countryIsoCode) {
            $country = $this->countryRepository->findOneByIsoCode($countryIsoCode);
            if (null === $country) {
                throw new BadRequestHttpException('Unknown value for parameter "country": no such ISO code.');
            }
            $countryId = (int) $country->getId();
        }

        $cacheKey = self::CACHE_KEY_PREFIX.(null !== $countryIsoCode ? '|country='.$countryIsoCode : '');

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($countryId): array {
            $item->expiresAfter(self::CACHE_TTL_SECONDS);

            $rows = $this->fundingRepository->findLocalFinanceAggregate(self::SMALL_AMOUNT_THRESHOLD_USD, $countryId);

            $data = array_map(static function (array $row): array {
                $totalUsd = (float) $row['totalUsd'];
                $estimatedLocalUsd = (float) $row['estimatedLocalUsd'];

                return [
                    'country' => $row['countryName'],
                    'countryIsoCode' => $row['isoCode'],
                    'totalUsd' => $totalUsd,
                    'estimatedLocalUsd' => $estimatedLocalUsd,
                    'localSharePercent' => $totalUsd > 0.0 ? round(100 * $estimatedLocalUsd / $totalUsd, 2) : 0.0,
                ];
            }, $rows);

            return ['data' => $data, 'methodology' => self::METHODOLOGY];
        });
    }
}
