<?php

declare(strict_types=1);

namespace App\Service\Scoring\Transparency;

use App\Entity\Source;
use App\Repository\FundingRepository;
use App\Service\Scoring\ScoreIndicatorInterface;

/**
 * C3.6 (0,2) : "fraîcheur moyenne" - normalisée linéairement entre 0 jour
 * (1.0) et self::STALE_THRESHOLD_DAYS (0.0), même seuil que
 * App\Service\AnalyticsService::STALE_THRESHOLD_DAYS (C1.1, ~100 jours,
 * un peu au-dessus du cycle trimestriel des connecteurs Volet B) - dupliqué
 * ici plutôt qu'importé pour ne pas coupler cet indicateur de scoring à un
 * service d'agrégats analytics qui n'a aucune autre raison de le connaître.
 * Neutre (0.5) si cette source n'a jamais eu de collecte réelle.
 *
 * @implements ScoreIndicatorInterface<Source>
 */
final class FreshnessIndicator implements ScoreIndicatorInterface
{
    private const STALE_THRESHOLD_DAYS = 100;

    public function __construct(
        private readonly FundingRepository $fundingRepository,
    ) {
    }

    public function code(): string
    {
        return 'freshness';
    }

    public function weight(): float
    {
        return 0.2;
    }

    public function compute(mixed $subject): float
    {
        $days = $this->daysSinceLastCollection($subject);

        return null === $days ? 0.5 : max(0.0, 1.0 - $days / self::STALE_THRESHOLD_DAYS);
    }

    public function explain(mixed $subject): string
    {
        $days = $this->daysSinceLastCollection($subject);

        return null === $days
            ? 'Aucune collecte réelle connue pour cette source - fraîcheur non évaluable.'
            : \sprintf('Dernière collecte réelle il y a %d jour(s).', $days);
    }

    private function daysSinceLastCollection(Source $source): ?int
    {
        $lastCollectionDate = $this->fundingRepository->findLastCollectionDateForSource((int) $source->getId());

        return null === $lastCollectionDate ? null : $lastCollectionDate->diff(new \DateTimeImmutable())->days;
    }
}
