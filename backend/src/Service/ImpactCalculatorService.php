<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ImpactFactor;
use App\Entity\Sector;
use App\Repository\ImpactFactorRepository;

/**
 * C2.5 : "Impact Calculator" - POST /api/impact/estimate. Facteurs
 * statiques et documentés (App\Entity\ImpactFactor, F5-style "pas de
 * modèle entraîné"), jamais un chiffre ponctuel présenté comme certain :
 * chaque métrique renvoyée porte une fourchette basse/haute construite à
 * partir de `ImpactFactor::$uncertaintyPercent`.
 */
final class ImpactCalculatorService
{
    public function __construct(
        private readonly ImpactFactorRepository $impactFactorRepository,
    ) {
    }

    /**
     * @return array{available: bool, sector?: string, amountUsd?: float, estimates?: array<string, array{central: float, low: float, high: float}>, uncertaintyPercent?: float, methodologySource?: string}
     */
    public function estimate(Sector $sector, float $amountUsd): array
    {
        $factor = $this->impactFactorRepository->findOneBySectorId((int) $sector->getId());
        if (null === $factor) {
            return ['available' => false];
        }

        $estimates = [];
        if (null !== $factor->getUsdPerTco2Avoided()) {
            $estimates['co2AvoidedTonsPerYear'] = self::withRange($amountUsd / (float) $factor->getUsdPerTco2Avoided(), $factor);
        }
        if (null !== $factor->getUsdPerMwInstalled()) {
            $estimates['mwInstalled'] = self::withRange($amountUsd / (float) $factor->getUsdPerMwInstalled(), $factor);
        }
        if (null !== $factor->getUsdPerBeneficiary()) {
            $estimates['beneficiaries'] = self::withRange($amountUsd / (float) $factor->getUsdPerBeneficiary(), $factor);
        }

        return [
            'available' => true,
            'sector' => $sector->getName(),
            'amountUsd' => $amountUsd,
            'estimates' => $estimates,
            'uncertaintyPercent' => $factor->getUncertaintyPercent(),
            'methodologySource' => $factor->getMethodologySource(),
        ];
    }

    /**
     * @return array{central: float, low: float, high: float}
     */
    private static function withRange(float $central, ImpactFactor $factor): array
    {
        $margin = $central * ($factor->getUncertaintyPercent() / 100.0);

        return [
            'central' => round($central, 2),
            'low' => round(max(0.0, $central - $margin), 2),
            'high' => round($central + $margin, 2),
        ];
    }
}
