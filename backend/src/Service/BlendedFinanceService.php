<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Enum\FundingInstrument;
use App\Entity\Sector;

/**
 * C3.4 : "Blended Finance Simulator" - POST /api/blended/simulate. Modèle
 * paramétrique simple (jamais un modèle entraîné, même esprit que
 * ImpactCalculatorService/C2.5) : un ratio de levier de base par secteur,
 * ajusté par un multiplicateur selon le mélange d'instruments publics
 * utilisé (`instrumentMix`, un pourcentage par instrument sommant à 1.0).
 *
 * IMPORTANT - fiabilité des chiffres : les deux tables ci-dessous sont des
 * ORDRES DE GRANDEUR illustratifs inspirés de la littérature OCDE sur la
 * finance mixte (blended finance - "leverage ratios" par secteur et par
 * instrument généralement cités dans les rapports OCDE/Convergence Blended
 * Finance), PAS une extraction vérifiée ligne à ligne d'une publication
 * précise - même statut que ImpactFactorFixtures. `country` accepté mais
 * sans effet sur le calcul, aucun facteur d'ajustement par pays n'existe.
 */
final class BlendedFinanceService
{
    /**
     * Capital privé mobilisé par dollar public, avant ajustement par
     * instrument - plus un secteur est perçu comme rentable/mature
     * (énergie renouvelable), plus il attire de capital privé pour un même
     * effort public ; l'adaptation, structurellement peu rentable, en
     * attire le moins.
     *
     * @var array<string, float>
     */
    private const SECTOR_BASE_LEVERAGE = [
        'Renewable Energy' => 3.5,
        'Sustainable Transport' => 2.8,
        'Forestry' => 1.8,
        'Agriculture' => 1.5,
        'Adaptation' => 1.2,
    ];

    private const DEFAULT_SECTOR_BASE_LEVERAGE = 1.5;

    /**
     * Multiplicateur appliqué au levier de base selon l'instrument public
     * utilisé - une garantie est l'instrument le plus catalytique (elle
     * mobilise du capital privé sans décaisser tant que le risque ne se
     * matérialise pas), un don le moins (il se substitue au capital privé
     * plutôt que de le co-investir).
     *
     * @var array<string, float>
     */
    private const INSTRUMENT_MULTIPLIER = [
        'guarantee' => 1.4,
        'equity' => 1.2,
        'results_based' => 1.1,
        'loan_concessional' => 1.0,
        'loan_non_concessional' => 0.8,
        'grant' => 0.6,
        'unknown' => 1.0,
    ];

    /**
     * @param array<string, float> $instrumentMix pourcentage (0.0-1.0) par valeur de App\Entity\Enum\FundingInstrument, sommant à 1.0
     *
     * @return array{sector: string, publicAmountUsd: float, leverageRatio: float, privateAmountMobilizedUsd: float, totalMobilizedUsd: float, waterfall: list<array{stage: string, amountUsd: float}>, methodologySource: string}
     */
    public function simulate(Sector $sector, float $publicAmountUsd, array $instrumentMix): array
    {
        $baseLeverage = self::SECTOR_BASE_LEVERAGE[$sector->getName()] ?? self::DEFAULT_SECTOR_BASE_LEVERAGE;
        $instrumentMultiplier = self::weightedInstrumentMultiplier($instrumentMix);
        $leverageRatio = round($baseLeverage * $instrumentMultiplier, 2);

        $privateAmountUsd = round($publicAmountUsd * $leverageRatio, 2);
        $totalUsd = round($publicAmountUsd + $privateAmountUsd, 2);

        return [
            'sector' => $sector->getName(),
            'publicAmountUsd' => $publicAmountUsd,
            'leverageRatio' => $leverageRatio,
            'privateAmountMobilizedUsd' => $privateAmountUsd,
            'totalMobilizedUsd' => $totalUsd,
            'waterfall' => [
                ['stage' => 'Financement public', 'amountUsd' => $publicAmountUsd],
                ['stage' => 'Capital privé mobilisé', 'amountUsd' => $privateAmountUsd],
                ['stage' => 'Total mobilisé', 'amountUsd' => $totalUsd],
            ],
            'methodologySource' => 'Ratios illustratifs inspirés de la littérature OCDE sur la finance mixte '
                .'(ordre de grandeur, non vérifié ligne à ligne) - jamais un modèle entraîné.',
        ];
    }

    /**
     * @param array<string, float> $instrumentMix
     */
    private static function weightedInstrumentMultiplier(array $instrumentMix): float
    {
        if ([] === $instrumentMix) {
            return self::INSTRUMENT_MULTIPLIER[FundingInstrument::Unknown->value];
        }

        $weightedSum = 0.0;
        foreach ($instrumentMix as $instrument => $share) {
            $weightedSum += $share * (self::INSTRUMENT_MULTIPLIER[$instrument] ?? self::INSTRUMENT_MULTIPLIER[FundingInstrument::Unknown->value]);
        }

        return $weightedSum;
    }
}
