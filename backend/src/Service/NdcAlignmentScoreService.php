<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Country;
use App\Entity\Enum\ScoreSubjectType;
use App\Entity\Score;
use App\Repository\NdcTargetRepository;
use App\Service\Scoring\NdcAlignment\ReceivedVsNeedRatioIndicator;
use App\Service\Scoring\NdcAlignment\SectorCoverageIndicator;
use App\Service\Scoring\NdcAlignment\UnconditionalFinancingIndicator;
use App\Service\Scoring\ScoreService;

/**
 * C2.6 : "NDC Alignment" - GET /api/countries/{iso}/scores/ndc-alignment.
 * 3 indicateurs pondérés (voir chacun sous App\Service\Scoring\
 * NdcAlignment\) : couverture des secteurs prioritaires (0,5), ratio
 * reçu/besoin (0,3), conditionnalité (0,2).
 *
 * `null` (jamais un score fabriqué) quand ce pays n'a pas de CDN chiffrée
 * dans F7 (NdcTargetFixtures : ~12/54 pays NEV) - un score de 0 ou une
 * valeur neutre laisserait croire qu'on a vérifié l'alignement de ce pays
 * et qu'il est mauvais/moyen, alors qu'on n'a simplement aucune CDN à
 * comparer.
 */
final class NdcAlignmentScoreService
{
    private const SCORE_TYPE = 'ndc_alignment';

    public function __construct(
        private readonly NdcTargetRepository $ndcTargetRepository,
        private readonly ScoreService $scoreService,
        private readonly SectorCoverageIndicator $sectorCoverageIndicator,
        private readonly ReceivedVsNeedRatioIndicator $receivedVsNeedRatioIndicator,
        private readonly UnconditionalFinancingIndicator $unconditionalFinancingIndicator,
    ) {
    }

    public function scoreFor(Country $country): ?Score
    {
        $ndcTarget = $this->ndcTargetRepository->findOneByCountry((int) $country->getId());
        if (null === $ndcTarget) {
            return null;
        }

        return $this->scoreService->compute(
            ScoreSubjectType::Country,
            (int) $country->getId(),
            self::SCORE_TYPE,
            $ndcTarget,
            [
                $this->sectorCoverageIndicator,
                $this->receivedVsNeedRatioIndicator,
                $this->unconditionalFinancingIndicator,
            ]
        );
    }
}
