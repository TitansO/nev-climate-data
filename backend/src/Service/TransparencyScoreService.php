<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Enum\ScoreSubjectType;
use App\Entity\Score;
use App\Entity\Source;
use App\Service\Scoring\ScoreService;
use App\Service\Scoring\Transparency\CompletenessIndicator;
use App\Service\Scoring\Transparency\DescriptivePresenceIndicator;
use App\Service\Scoring\Transparency\FreshnessIndicator;
use App\Service\Scoring\Transparency\GranularityIndicator;
use App\Service\Scoring\Transparency\IatiPublicationIndicator;

/**
 * C3.6 : "Transparency Score" - GET /api/institutions/{sourceId}/scores/
 * transparency. 5 indicateurs pondérés (voir chacun sous
 * App\Service\Scoring\Transparency\) : complétude (0,3), publication IATI
 * (0,2), fraîcheur (0,2), granularité (0,2), présence de descriptif (0,1) -
 * les 2 derniers toujours neutres, faute de signal disponible dans ce
 * schéma (voir leurs docblocks respectifs). Jamais `null` : contrairement
 * à C2.6/C3.3, une `Source` existe toujours indépendamment d'avoir des
 * financements réels ou non - CompletenessIndicator/FreshnessIndicator
 * gèrent eux-mêmes ce cas (neutre 0.5) plutôt que de rendre le score
 * entier indisponible.
 */
final class TransparencyScoreService
{
    private const SCORE_TYPE = 'transparency';

    public function __construct(
        private readonly ScoreService $scoreService,
        private readonly CompletenessIndicator $completenessIndicator,
        private readonly IatiPublicationIndicator $iatiPublicationIndicator,
        private readonly FreshnessIndicator $freshnessIndicator,
        private readonly GranularityIndicator $granularityIndicator,
        private readonly DescriptivePresenceIndicator $descriptivePresenceIndicator,
    ) {
    }

    public function scoreFor(Source $source): Score
    {
        return $this->scoreService->compute(
            ScoreSubjectType::Source,
            (int) $source->getId(),
            self::SCORE_TYPE,
            $source,
            [
                $this->completenessIndicator,
                $this->iatiPublicationIndicator,
                $this->freshnessIndicator,
                $this->granularityIndicator,
                $this->descriptivePresenceIndicator,
            ]
        );
    }
}
