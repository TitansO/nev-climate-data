<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Enum\ScoreSubjectType;
use App\Entity\Project;
use App\Entity\Score;
use App\Service\Scoring\Bankability\AmountSectorCoherenceIndicator;
use App\Service\Scoring\Bankability\CompletenessIndicator;
use App\Service\Scoring\Bankability\InvertedCountryRiskIndicator;
use App\Service\Scoring\Bankability\PromoterIdentifiedIndicator;
use App\Service\Scoring\Bankability\RealisticInstrumentIndicator;
use App\Service\Scoring\ScoreService;

/**
 * C2.4 : "Bankability Score" - GET /api/projects/{id}/scores/bankability.
 * 5 indicateurs pondérés (voir chacun sous App\Service\Scoring\Bankability\)
 * combinés par App\Service\Scoring\ScoreService (F3) : complétude (0,25),
 * porteur identifié (0,1), cohérence montant/secteur (0,2), instrument
 * réaliste pour le pays (0,15), risque pays inversé (0,3).
 */
final class BankabilityScoreService
{
    private const SCORE_TYPE = 'bankability';

    public function __construct(
        private readonly ScoreService $scoreService,
        private readonly CompletenessIndicator $completenessIndicator,
        private readonly PromoterIdentifiedIndicator $promoterIdentifiedIndicator,
        private readonly AmountSectorCoherenceIndicator $amountSectorCoherenceIndicator,
        private readonly RealisticInstrumentIndicator $realisticInstrumentIndicator,
        private readonly InvertedCountryRiskIndicator $invertedCountryRiskIndicator,
    ) {
    }

    public function scoreFor(Project $project): Score
    {
        return $this->scoreService->compute(
            ScoreSubjectType::Project,
            (int) $project->getId(),
            self::SCORE_TYPE,
            $project,
            [
                $this->completenessIndicator,
                $this->promoterIdentifiedIndicator,
                $this->amountSectorCoherenceIndicator,
                $this->realisticInstrumentIndicator,
                $this->invertedCountryRiskIndicator,
            ]
        );
    }
}
