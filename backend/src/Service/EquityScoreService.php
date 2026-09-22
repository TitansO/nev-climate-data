<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\CountryDistributionCriteria;
use App\Entity\Country;
use App\Entity\Enum\ScoreSubjectType;
use App\Entity\Score;
use App\Repository\CountryRepository;
use App\Repository\FundingRepository;
use Symfony\Component\HttpFoundation\InputBag;
use App\Service\Scoring\Equity\FundingPerTonneInvertedIndicator;
use App\Service\Scoring\Equity\PerCapitaVsVulnerabilityIndicator;
use App\Service\Scoring\Equity\ReceivedVsCdnNeedIndicator;
use App\Service\Scoring\ScoreService;

/**
 * C3.3 : "Equity Score" - GET /api/analytics/equity (classement + indice
 * global) et GET /api/countries/{iso}/scores/equity (par pays). 3
 * indicateurs pondérés (voir chacun sous App\Service\Scoring\Equity\) :
 * financement/habitant vs vulnérabilité (0,4), part reçue vs besoin CDN
 * (0,3), financement/tCO2 inversé (0,3).
 *
 * `null` (jamais un score fabriqué) quand ce pays n'a ni population ni
 * indice de vulnérabilité (App\Repository\CountryRepository::findAllWithPopulationAndVulnerability(),
 * voir CountryEquityDataFixtures : ~12/54 pays NEV) - même raisonnement
 * que NdcAlignmentScoreService (C2.6) : une valeur neutre laisserait
 * croire à une équité vérifiée et moyenne, alors qu'on n'a simplement
 * aucune donnée de population/vulnérabilité pour ce pays.
 */
final class EquityScoreService
{
    private const SCORE_TYPE = 'equity';

    public function __construct(
        private readonly ScoreService $scoreService,
        private readonly CountryRepository $countryRepository,
        private readonly FundingRepository $fundingRepository,
        private readonly PerCapitaVsVulnerabilityIndicator $perCapitaVsVulnerabilityIndicator,
        private readonly ReceivedVsCdnNeedIndicator $receivedVsCdnNeedIndicator,
        private readonly FundingPerTonneInvertedIndicator $fundingPerTonneInvertedIndicator,
    ) {
    }

    public function scoreFor(Country $country): ?Score
    {
        if (null === $country->getPopulation() || null === $country->getVulnerabilityIndex()) {
            return null;
        }

        return $this->scoreService->compute(
            ScoreSubjectType::Country,
            (int) $country->getId(),
            self::SCORE_TYPE,
            $country,
            [
                $this->perCapitaVsVulnerabilityIndicator,
                $this->receivedVsCdnNeedIndicator,
                $this->fundingPerTonneInvertedIndicator,
            ]
        );
    }

    /**
     * Classement de tous les pays disposant d'un score d'équité complet,
     * plus un indice de concentration type Gini calculé sur la
     * répartition réelle des financements entre TOUS les pays financés
     * (pas seulement ceux notés) - une mesure de concentration globale,
     * distincte du classement par pays.
     *
     * @return array{ranking: list<array{country: string, countryIsoCode: string, value: float, grade: string}>, giniIndex: float}
     */
    public function getRanking(): array
    {
        $pool = $this->countryRepository->findAllWithPopulationAndVulnerability();

        $ranking = [];
        foreach ($pool as $country) {
            $score = $this->scoreFor($country);
            if (null === $score) {
                continue;
            }
            $ranking[] = [
                'country' => $country->getName(),
                'countryIsoCode' => $country->getIsoCode(),
                'value' => $score->getValue(),
                'grade' => $score->getGrade(),
            ];
        }

        usort($ranking, static fn (array $a, array $b): int => $b['value'] <=> $a['value']);

        return ['ranking' => $ranking, 'giniIndex' => $this->computeGiniIndex()];
    }

    /**
     * Coefficient de Gini (0 = répartition parfaitement égale entre tous
     * les pays financés, 1 = un seul pays concentre tout) sur les montants
     * réels reçus par pays - formule standard sur données discrètes
     * triées : G = (2 * Σ(i * x_i) / (n * Σx_i)) - (n + 1) / n, i étant le
     * rang (1-indexé) une fois les montants triés croissants.
     */
    private function computeGiniIndex(): float
    {
        $amounts = array_values(array_map(
            static fn (array $row): float => (float) $row['total'],
            $this->fundingRepository->findCountryDistributionAggregate(CountryDistributionCriteria::fromQuery(new InputBag()))
        ));
        sort($amounts);
        $n = \count($amounts);
        $total = array_sum($amounts);

        if ($n < 2 || $total <= 0.0) {
            return 0.0;
        }

        $weightedSum = 0.0;
        foreach ($amounts as $index => $amount) {
            $weightedSum += ($index + 1) * $amount;
        }

        return round((2 * $weightedSum) / ($n * $total) - ($n + 1) / $n, 4);
    }
}
