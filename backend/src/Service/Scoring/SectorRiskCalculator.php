<?php

declare(strict_types=1);

namespace App\Service\Scoring;

use App\Entity\Sector;
use App\Repository\FundingRepository;

/**
 * C2.8 (Investment Risk Score - composante "risque secteur") - même
 * raisonnement et même forme de retour que App\Service\Scoring\
 * CountryRiskCalculator : deux composantes issues de données réelles,
 * normalisées 0.0 (aucun risque) à 1.0 (risque maximal), moyennées à
 * poids égal (le plan ne fixe pas de pondération propre à C2.8, voir le
 * docblock de CountryRiskCalculator pour le même choix). Une composante
 * sans donnée suffisante est omise de la moyenne, jamais remplacée par
 * une valeur neutre fabriquée.
 */
final class SectorRiskCalculator
{
    /**
     * Nombre d'années de données réelles au-delà duquel la maturité est
     * considérée maximale (risque nul de ce point de vue) - seuil choisi,
     * documenté ici comme la seule source de vérité.
     */
    private const YEARS_OF_HISTORY_AT_FULL_MATURITY = 11;

    public function __construct(
        private readonly FundingRepository $fundingRepository,
    ) {
    }

    /**
     * @return array{value: float, components: list<array{code: string, value: float, explanation: string}>}
     */
    public function calculate(Sector $sector): array
    {
        $components = [];

        $maturity = $this->maturityRisk($sector->getId());
        if (null !== $maturity) {
            $components[] = $maturity;
        }

        $dependency = $this->instrumentDependencyRisk($sector->getId());
        if (null !== $dependency) {
            $components[] = $dependency;
        }

        if ([] === $components) {
            return ['value' => 0.5, 'components' => [['code' => 'no_data', 'value' => 0.5, 'explanation' => 'Aucune donnée réelle disponible pour ce secteur - risque non évaluable, valeur neutre.']]];
        }

        $average = array_sum(array_column($components, 'value')) / \count($components);

        return ['value' => $average, 'components' => $components];
    }

    /**
     * "Maturité du secteur" : moins un secteur a d'historique réel, plus
     * le risque est élevé - décroissance linéaire jusqu'à
     * YEARS_OF_HISTORY_AT_FULL_MATURITY années, jamais un couperet.
     *
     * @return array{code: string, value: float, explanation: string}|null null si aucune donnée réelle n'existe pour ce secteur
     */
    private function maturityRisk(int $sectorId): ?array
    {
        $rows = $this->fundingRepository->findYearlyTotalsForSector($sectorId);
        if ([] === $rows) {
            return null;
        }

        $years = \count($rows);
        $risk = max(0.0, 1.0 - ($years - 1) / (self::YEARS_OF_HISTORY_AT_FULL_MATURITY - 1));

        return [
            'code' => 'sector_maturity',
            'value' => $risk,
            'explanation' => \sprintf('%d année(s) de financement réel connu pour ce secteur (seuil de pleine maturité : %d ans).', $years, self::YEARS_OF_HISTORY_AT_FULL_MATURITY),
        ];
    }

    /**
     * "Dépendance à un instrument" : indice de Herfindahl-Hirschman sur
     * les instruments réels de ce secteur - même technique que
     * CountryRiskCalculator::donorConcentration(), un secteur dépendant
     * d'un seul type d'instrument est plus vulnérable à sa disparition.
     *
     * @return array{code: string, value: float, explanation: string}|null null si aucun financement réel n'existe pour ce secteur
     */
    private function instrumentDependencyRisk(int $sectorId): ?array
    {
        $rows = $this->fundingRepository->findInstrumentTotalsForSector($sectorId);
        if ([] === $rows) {
            return null;
        }

        $totals = array_map(static fn (array $row): float => (float) $row['total'], $rows);
        $grandTotal = array_sum($totals);
        if ($grandTotal <= 0.0) {
            return null;
        }

        $hhi = array_sum(array_map(static fn (float $total): float => ($total / $grandTotal) ** 2, $totals));

        return [
            'code' => 'instrument_dependency',
            'value' => $hhi,
            'explanation' => \sprintf('Indice de concentration des instruments (HHI) sur %d type(s) réel(s) : %.2f.', \count($rows), $hhi),
        ];
    }
}
