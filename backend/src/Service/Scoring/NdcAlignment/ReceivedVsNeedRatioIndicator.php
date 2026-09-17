<?php

declare(strict_types=1);

namespace App\Service\Scoring\NdcAlignment;

use App\Entity\NdcTarget;
use App\Repository\FundingRepository;
use App\Service\Scoring\ScoreIndicatorInterface;

/**
 * C2.6 (0,3) : "ratio reçu/besoin déclaré". `declaredNeedUsd` n'est
 * chiffré que pour 2 des 12 pays de NdcTargetFixtures (Nigeria, Maroc) -
 * neutre (0.5) quand absent, jamais une pénalité pour une donnée que le
 * pays lui-même n'a pas rendue publique.
 *
 * @implements ScoreIndicatorInterface<NdcTarget>
 */
final class ReceivedVsNeedRatioIndicator implements ScoreIndicatorInterface
{
    public function __construct(
        private readonly FundingRepository $fundingRepository,
    ) {
    }

    public function code(): string
    {
        return 'received_vs_need_ratio';
    }

    public function weight(): float
    {
        return 0.3;
    }

    public function compute(mixed $subject): float
    {
        $need = $subject->getDeclaredNeedUsd();
        if (null === $need || (float) $need <= 0.0) {
            return 0.5;
        }

        $received = (float) ($this->fundingRepository->findTotalReceivedForCountry((int) $subject->getCountry()->getId()) ?? '0');

        return min(1.0, $received / (float) $need);
    }

    public function explain(mixed $subject): string
    {
        $need = $subject->getDeclaredNeedUsd();
        if (null === $need || (float) $need <= 0.0) {
            return 'Besoin de financement CDN non chiffré publiquement pour ce pays - ratio non évaluable.';
        }

        $received = (float) ($this->fundingRepository->findTotalReceivedForCountry((int) $subject->getCountry()->getId()) ?? '0');

        return \sprintf('Reçu réel : %s USD sur un besoin déclaré de %s USD.', number_format($received, 0, ',', ' '), number_format((float) $need, 0, ',', ' '));
    }
}
