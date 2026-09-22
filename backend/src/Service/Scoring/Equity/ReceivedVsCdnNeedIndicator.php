<?php

declare(strict_types=1);

namespace App\Service\Scoring\Equity;

use App\Entity\Country;
use App\Repository\FundingRepository;
use App\Repository\NdcTargetRepository;
use App\Service\Scoring\ScoreIndicatorInterface;

/**
 * C3.3 (0,3) : "part reçue vs part du besoin CDN" - même calcul que
 * App\Service\Scoring\NdcAlignment\ReceivedVsNeedRatioIndicator (C2.6),
 * réécrit ici pour un sujet Country plutôt que NdcTarget (EquityScoreService
 * calcule un score par pays, jamais par cible CDN). Neutre (0.5) quand ce
 * pays n'a pas de besoin CDN chiffré - même raisonnement que l'original :
 * jamais une pénalité pour une donnée que le pays n'a pas rendue publique.
 *
 * @implements ScoreIndicatorInterface<Country>
 */
final class ReceivedVsCdnNeedIndicator implements ScoreIndicatorInterface
{
    public function __construct(
        private readonly FundingRepository $fundingRepository,
        private readonly NdcTargetRepository $ndcTargetRepository,
    ) {
    }

    public function code(): string
    {
        return 'received_vs_cdn_need';
    }

    public function weight(): float
    {
        return 0.3;
    }

    public function compute(mixed $subject): float
    {
        $need = $this->declaredNeedFor($subject);
        if (null === $need) {
            return 0.5;
        }

        $received = (float) ($this->fundingRepository->findTotalReceivedForCountry((int) $subject->getId()) ?? '0');

        return min(1.0, $received / $need);
    }

    public function explain(mixed $subject): string
    {
        $need = $this->declaredNeedFor($subject);
        if (null === $need) {
            return 'Besoin de financement CDN non chiffré publiquement pour ce pays - ratio non évaluable.';
        }

        $received = (float) ($this->fundingRepository->findTotalReceivedForCountry((int) $subject->getId()) ?? '0');

        return \sprintf('Reçu réel : %s USD sur un besoin déclaré de %s USD.', number_format($received, 0, ',', ' '), number_format($need, 0, ',', ' '));
    }

    private function declaredNeedFor(Country $country): ?float
    {
        $target = $this->ndcTargetRepository->findOneByCountry((int) $country->getId());
        $need = $target?->getDeclaredNeedUsd();

        return null === $need || (float) $need <= 0.0 ? null : (float) $need;
    }
}
