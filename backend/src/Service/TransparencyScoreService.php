<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Enum\ScoreSubjectType;
use App\Entity\Score;
use App\Entity\Source;
use App\Repository\SourceRepository;
use App\Service\Scoring\ScoreService;
use App\Service\Scoring\Transparency\CompletenessIndicator;
use App\Service\Scoring\Transparency\DescriptivePresenceIndicator;
use App\Service\Scoring\Transparency\FreshnessIndicator;
use App\Service\Scoring\Transparency\GranularityIndicator;
use App\Service\Scoring\Transparency\IatiPublicationIndicator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

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

    // Audit pre-production 2026-09-22 (P1) : getRanking() etait le seul
    // agregat de AnalyticsController sans cache - meme N+1 qu'EquityScoreService,
    // meme correctif (TTL/pool identiques aux autres agregats analytics).
    private const CACHE_TTL_SECONDS = 900;
    private const CACHE_KEY = 'analytics_transparency_ranking';

    public function __construct(
        private readonly ScoreService $scoreService,
        private readonly SourceRepository $sourceRepository,
        private readonly CompletenessIndicator $completenessIndicator,
        private readonly IatiPublicationIndicator $iatiPublicationIndicator,
        private readonly FreshnessIndicator $freshnessIndicator,
        private readonly GranularityIndicator $granularityIndicator,
        private readonly DescriptivePresenceIndicator $descriptivePresenceIndicator,
        #[Autowire(service: 'cache.analytics')]
        private readonly CacheInterface $cache,
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

    /**
     * "Classement des bailleurs" (front C3.6, jamais construit lors du
     * premier passage) - jamais `null` par bailleur (voir scoreFor()), donc
     * TOUS les bailleurs réels sont classés ici, contrairement à
     * EquityScoreService::getRanking() qui exclut les pays sans donnée.
     *
     * @return list<array{sourceId: int, sourceName: string, value: float, grade: string}>
     */
    public function getRanking(): array
    {
        return $this->cache->get(self::CACHE_KEY, function (ItemInterface $item): array {
            $item->expiresAfter(self::CACHE_TTL_SECONDS);

            $ranking = array_map(
                function (Source $source): array {
                    $score = $this->scoreFor($source);

                    return ['sourceId' => $source->getId(), 'sourceName' => $source->getName(), 'value' => $score->getValue(), 'grade' => $score->getGrade()];
                },
                $this->sourceRepository->findAll()
            );

            usort($ranking, static fn (array $a, array $b): int => $b['value'] <=> $a['value']);

            return $ranking;
        });
    }
}
