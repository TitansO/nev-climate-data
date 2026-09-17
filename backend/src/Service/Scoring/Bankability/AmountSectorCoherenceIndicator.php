<?php

declare(strict_types=1);

namespace App\Service\Scoring\Bankability;

use App\Entity\Project;
use App\Repository\FundingRepository;
use App\Service\Scoring\ScoreIndicatorInterface;

/**
 * C2.4 (0,2) : "cohérence montant/secteur vs comparables réels". Compare
 * le montant recherché du projet à la moyenne réelle des financements
 * validés du même secteur (App\Repository\FundingRepository::
 * findSectorAmountBenchmark()) - un montant du même ordre de grandeur
 * (ratio dans [0,1x ; 10x] de la moyenne) obtient le score plein ; au-delà,
 * décroissance logarithmique plutôt qu'un couperet, même raisonnement que
 * App\Service\ProjectMatchingService::amountScore() (C2.2).
 *
 * @implements ScoreIndicatorInterface<Project>
 */
final class AmountSectorCoherenceIndicator implements ScoreIndicatorInterface
{
    public function __construct(
        private readonly FundingRepository $fundingRepository,
    ) {
    }

    public function code(): string
    {
        return 'amount_sector_coherence';
    }

    public function weight(): float
    {
        return 0.2;
    }

    public function compute(mixed $subject): float
    {
        $benchmark = $this->fundingRepository->findSectorAmountBenchmark((int) $subject->getSector()->getId());
        if (null === $benchmark) {
            // Moins de 3 comparables réels pour ce secteur : pas de base
            // fiable pour juger la cohérence - neutre, jamais une
            // pénalité pour une absence de donnée hors du contrôle du
            // porteur de projet.
            return 0.5;
        }

        $average = (float) $benchmark['average'];
        if ($average <= 0.0) {
            return 0.5;
        }

        $ratio = (float) $subject->getAmountSought() / $average;
        if ($ratio >= 0.1 && $ratio <= 10.0) {
            return 1.0;
        }

        $distance = $ratio < 0.1 ? 0.1 / $ratio : $ratio / 10.0;

        return max(0.0, 1.0 - log10($distance));
    }

    public function explain(mixed $subject): string
    {
        $benchmark = $this->fundingRepository->findSectorAmountBenchmark((int) $subject->getSector()->getId());
        if (null === $benchmark) {
            return \sprintf('Moins de 3 financements réels validés pour le secteur "%s" - comparable non disponible.', $subject->getSector()->getName());
        }

        return \sprintf(
            'Montant demandé : %s USD. Moyenne réelle du secteur "%s" (%d financements validés) : %s USD.',
            number_format((float) $subject->getAmountSought(), 0, ',', ' '),
            $subject->getSector()->getName(),
            $benchmark['count'],
            number_format((float) $benchmark['average'], 0, ',', ' ')
        );
    }
}
