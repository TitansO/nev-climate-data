<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Country;
use App\Entity\Enum\ProjectPipelineStage;
use App\Entity\Enum\ScoreSubjectType;
use App\Entity\Project;
use App\Entity\Score;
use App\Service\Scoring\CountryRiskCalculator;
use App\Service\Scoring\PrecomputedIndicator;
use App\Service\Scoring\ScoreIndicatorInterface;
use App\Service\Scoring\ScoreService;
use App\Service\Scoring\SectorRiskCalculator;

/**
 * C2.8 : "Investment Risk Score" - GET /api/countries/{iso}/scores/
 * investment-risk et GET /api/projects/{id}/scores/investment-risk.
 * "Risque pays x risque secteur x risque projet" (plan) : pour un pays
 * seul, le risque pays de App\Service\Scoring\CountryRiskCalculator
 * (déjà construit pour C2.4) ; pour un projet, les trois composantes
 * combinées à poids égal (le plan ne fixe pas de pondération propre à
 * C2.8, même choix que CountryRiskCalculator/SectorRiskCalculator).
 *
 * Grade A-E orienté "plus haut = plus sûr" (comme tous les autres scores
 * de cette plateforme, jamais l'inverse) : chaque composante de risque
 * (0 = sûr, 1 = risqué) est inversée avant d'être passée à ScoreService.
 * Aucun indice souverain tiers (le plan l'exclut explicitement pour ce
 * sprint) - uniquement des indicateurs ouverts calculés sur les données
 * réelles de la plateforme.
 */
final class InvestmentRiskScoreService
{
    private const SCORE_TYPE = 'investment_risk';

    /**
     * "Risque projet" : proxy par stade du pipeline (plus un projet est
     * avancé, moins il est risqué) - aucune autre donnée de risque projet
     * n'existe dans ce schéma. Depuis la fusion 2026-09-28 de "Financed"/
     * "Rejected" en un seul stade "Decision" (voir ProjectPipelineStage),
     * ce stade seul ne suffit plus à distinguer les deux issues : le calcul
     * ci-dessous (scoreForProject()) affine "decision" via
     * Project::$decisionOutcome quand il est renseigné, cette valeur par
     * défaut ne servant que tant qu'aucune issue n'a encore été choisie.
     * "Execution" (financé, en cours ou clôturé) reprend le risque minimal
     * qu'avait l'ancien "Financed".
     *
     * @var array<string, float>
     */
    private const STAGE_RISK = [
        'idea' => 1.0,
        'scoping' => 0.8,
        'structuring' => 0.6,
        'fundraising' => 0.4,
        'decision' => 0.5,
        'execution' => 0.1,
    ];

    /**
     * Affine STAGE_RISK['decision'] une fois l'issue connue : un projet
     * rejeté reste au risque maximal (par définition pas un investissement
     * viable), un projet financé (mais pas encore déplacé en Execution)
     * hérite du même risque minimal que "execution".
     *
     * @var array<string, float>
     */
    private const DECISION_OUTCOME_RISK = [
        'financed' => 0.1,
        'rejected' => 1.0,
    ];

    public function __construct(
        private readonly ScoreService $scoreService,
        private readonly CountryRiskCalculator $countryRiskCalculator,
        private readonly SectorRiskCalculator $sectorRiskCalculator,
    ) {
    }

    public function scoreForCountry(Country $country): Score
    {
        $result = $this->countryRiskCalculator->calculate($country);

        return $this->scoreService->compute(
            ScoreSubjectType::Country,
            (int) $country->getId(),
            self::SCORE_TYPE,
            $country,
            self::invertedIndicatorsFrom($result['components'])
        );
    }

    public function scoreForProject(Project $project): Score
    {
        $countryResult = $this->countryRiskCalculator->calculate($project->getCountry());
        $sectorResult = $this->sectorRiskCalculator->calculate($project->getSector());
        $stageRisk = self::STAGE_RISK[$project->getPipelineStage()->value] ?? 0.5;
        if (ProjectPipelineStage::Decision === $project->getPipelineStage() && null !== $project->getDecisionOutcome()) {
            $stageRisk = self::DECISION_OUTCOME_RISK[$project->getDecisionOutcome()->value];
        }

        $indicators = [
            new PrecomputedIndicator(
                'country_risk',
                1 / 3,
                1.0 - $countryResult['value'],
                \sprintf('Risque pays (%.2f) : %s', $countryResult['value'], self::summarize($countryResult['components']))
            ),
            new PrecomputedIndicator(
                'sector_risk',
                1 / 3,
                1.0 - $sectorResult['value'],
                \sprintf('Risque secteur (%.2f) : %s', $sectorResult['value'], self::summarize($sectorResult['components']))
            ),
            new PrecomputedIndicator(
                'project_stage_risk',
                1 / 3,
                1.0 - $stageRisk,
                null !== $project->getDecisionOutcome()
                    ? \sprintf('Stade du pipeline : "%s" (issue : "%s").', $project->getPipelineStage()->value, $project->getDecisionOutcome()->value)
                    : \sprintf('Stade du pipeline : "%s".', $project->getPipelineStage()->value)
            ),
        ];

        return $this->scoreService->compute(
            ScoreSubjectType::Project,
            (int) $project->getId(),
            self::SCORE_TYPE,
            $project,
            $indicators
        );
    }

    /**
     * @param list<array{code: string, value: float, explanation: string}> $components
     *
     * @return list<ScoreIndicatorInterface>
     */
    private static function invertedIndicatorsFrom(array $components): array
    {
        $weight = 1.0 / \count($components);

        return array_map(
            static fn (array $component): ScoreIndicatorInterface => new PrecomputedIndicator($component['code'], $weight, 1.0 - $component['value'], $component['explanation']),
            $components
        );
    }

    /**
     * @param list<array{code: string, value: float, explanation: string}> $components
     */
    private static function summarize(array $components): string
    {
        return implode(' ', array_map(static fn (array $component): string => $component['explanation'], $components));
    }
}
