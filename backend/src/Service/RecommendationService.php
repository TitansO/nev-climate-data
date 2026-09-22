<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Country;
use App\Repository\CountryRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * C3.7 : "Recommendation Engine" - GET /api/recommendations. "Pas
 * d'apprentissage" (plan) : une agrégation transparente et pondérée de 4
 * signaux déjà calculés par C2.6/C2.8/C3.1/C3.3, jamais un modèle entraîné
 * - chaque recommandation reste justifiable ligne par ligne via
 * `signals`/`justification`.
 *
 * Périmètre : les pays du "pool équité" (App\Repository\CountryRepository::findAllWithPopulationAndVulnerability(),
 * ~12/54 pays NEV - CountryEquityDataFixtures/NdcTargetFixtures) plutôt que
 * les 54 pays de la plateforme. Un pays hors de ce pool n'a ni score
 * d'équité ni d'alignement CDN ni de cible CDN chiffrée (C3.1) : seul le
 * risque investissement (C2.8, universel) resterait disponible, ce qui ne
 * suffit pas à établir une priorité ("où allouer le prochain euro" suppose
 * un signal de besoin, pas seulement de sécurité) - même raisonnement
 * "available uniquement si réel" que NdcAlignmentScoreService/EquityScoreService.
 *
 * Chaque signal est ramené à "plus haut = priorité plus forte" avant
 * moyenne : écart de couverture CDN (C3.1, 1 - ratio reçu/besoin), écart
 * d'équité (C3.3, 1 - valeur/100), écart d'alignement CDN (C2.6,
 * 1 - valeur/100), sécurité de l'investissement (C2.8, valeur/100 - à
 * besoin égal, on priorise le pari le plus sûr). Un pays ne disposant que
 * d'un sous-ensemble de ces signaux (C3.1 ne couvre que 2/12 pays,
 * Nigeria/Maroc) est noté sur la moyenne des seuls signaux disponibles,
 * jamais complétée par une valeur neutre fabriquée - poids égal (0,25
 * chacun quand tous disponibles) faute de pondération fixée par le plan.
 */
final class RecommendationService
{
    private const CACHE_TTL_SECONDS = 900;
    private const CACHE_KEY = 'analytics_recommendations';

    public function __construct(
        private readonly CountryRepository $countryRepository,
        private readonly GapDetectionService $gapDetectionService,
        private readonly EquityScoreService $equityScoreService,
        private readonly NdcAlignmentScoreService $ndcAlignmentScoreService,
        private readonly InvestmentRiskScoreService $investmentRiskScoreService,
        #[Autowire(service: 'cache.analytics')]
        private readonly CacheInterface $cache,
    ) {
    }

    /**
     * @return list<array{country: string, countryIsoCode: string, priorityScore: float, signals: array{needGap: ?float, equityGap: ?float, ndcGap: ?float, investmentSafety: ?float}, justification: string}>
     */
    public function getRecommendations(): array
    {
        return $this->cache->get(self::CACHE_KEY, function (ItemInterface $item): array {
            $item->expiresAfter(self::CACHE_TTL_SECONDS);

            $gapByIso = [];
            foreach ($this->gapDetectionService->getGaps('country') as $row) {
                $gapByIso[$row['countryIsoCode']] = $row;
            }

            $rows = [];
            foreach ($this->countryRepository->findAllWithPopulationAndVulnerability() as $country) {
                $row = $this->recommendationFor($country, $gapByIso[$country->getIsoCode()] ?? null);
                if (null !== $row) {
                    $rows[] = $row;
                }
            }

            usort($rows, static fn (array $a, array $b): int => $b['priorityScore'] <=> $a['priorityScore']);

            return $rows;
        });
    }

    /**
     * @param ?array{declaredNeedUsd: float, receivedUsd: float, coverageRatio: float} $gapRow
     *
     * @return ?array{country: string, countryIsoCode: string, priorityScore: float, signals: array{needGap: ?float, equityGap: ?float, ndcGap: ?float, investmentSafety: ?float}, justification: string}
     */
    private function recommendationFor(Country $country, ?array $gapRow): ?array
    {
        $needGap = null !== $gapRow ? max(0.0, min(1.0, 1.0 - $gapRow['coverageRatio'])) : null;

        $equityScore = $this->equityScoreService->scoreFor($country);
        $equityGap = null !== $equityScore ? 1.0 - $equityScore->getValue() / 100 : null;

        $ndcScore = $this->ndcAlignmentScoreService->scoreFor($country);
        $ndcGap = null !== $ndcScore ? 1.0 - $ndcScore->getValue() / 100 : null;

        $investmentSafety = $this->investmentRiskScoreService->scoreForCountry($country)->getValue() / 100;

        $signals = array_filter([$needGap, $equityGap, $ndcGap, $investmentSafety], static fn (?float $value): bool => null !== $value);
        if ([] === $signals) {
            return null;
        }

        return [
            'country' => $country->getName(),
            'countryIsoCode' => $country->getIsoCode(),
            'priorityScore' => round(array_sum($signals) / \count($signals), 4),
            'signals' => [
                'needGap' => $needGap,
                'equityGap' => $equityGap,
                'ndcGap' => $ndcGap,
                'investmentSafety' => $investmentSafety,
            ],
            'justification' => self::justify($country->getName(), $needGap, $equityGap, $ndcGap, $investmentSafety),
        ];
    }

    private static function justify(string $countryName, ?float $needGap, ?float $equityGap, ?float $ndcGap, float $investmentSafety): string
    {
        $parts = [];
        if (null !== $needGap) {
            $parts[] = \sprintf('%.0f%% du besoin CDN déclaré reste non couvert', $needGap * 100);
        }
        if (null !== $equityGap) {
            $parts[] = \sprintf('score d\'équité à %.0f%%', (1.0 - $equityGap) * 100);
        }
        if (null !== $ndcGap) {
            $parts[] = \sprintf('alignement CDN à %.0f%%', (1.0 - $ndcGap) * 100);
        }
        $parts[] = \sprintf('sécurité d\'investissement à %.0f%%', $investmentSafety * 100);

        return \sprintf('%s : %s.', $countryName, implode(', ', $parts));
    }
}
