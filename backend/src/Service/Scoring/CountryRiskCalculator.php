<?php

declare(strict_types=1);

namespace App\Service\Scoring;

use App\Entity\Country;
use App\Repository\FundingRepository;
use App\Repository\NdcTargetRepository;

/**
 * C2.8 (Investment Risk Score - composante "risque pays") : construite
 * maintenant, en avance sur C2.8 lui-même, uniquement pour que C2.4
 * (bancabilité) puisse réellement "réutiliser le risque pays" comme le
 * demande le plan, plutôt que de fabriquer une valeur neutre à sa place.
 * Ne remplace pas C2.8 : ce dernier exposera ses propres endpoints
 * (`GET /api/countries/{iso}/scores/investment-risk`,
 * `GET /api/projects/{id}/scores/investment-risk`) qui appelleront ce
 * même calculateur - un seul calcul de risque pays, pas deux.
 *
 * Trois composantes issues d'indicateurs ouverts (aucune donnée tierce
 * payante), chacune normalisée 0.0 (aucun risque) à 1.0 (risque maximal),
 * moyennées à poids égal - le plan ne fixe pas de pondération propre à
 * C2.8 (contrairement à C2.4, dont les poids sont donnés explicitement) :
 * une moyenne simple est le choix le plus honnête tant qu'aucune
 * pondération n'a été validée. Une composante sans donnée suffisante est
 * omise de la moyenne, jamais remplacée par une valeur neutre fabriquée.
 */
final class CountryRiskCalculator
{
    /**
     * CV (coefficient de variation) au-delà duquel le risque de
     * volatilité est considéré maximal - seuil choisi, pas une constante
     * universelle (documenté ici pour rester la seule source de vérité).
     */
    private const VOLATILITY_CV_AT_MAX_RISK = 1.5;

    public function __construct(
        private readonly FundingRepository $fundingRepository,
        private readonly NdcTargetRepository $ndcTargetRepository,
    ) {
    }

    /**
     * @return array{value: float, components: list<array{code: string, value: float, explanation: string}>}
     */
    public function calculate(Country $country): array
    {
        $components = [];

        $volatility = $this->flowVolatility($country->getId());
        if (null !== $volatility) {
            $components[] = $volatility;
        }

        $concentration = $this->donorConcentration($country->getId());
        if (null !== $concentration) {
            $components[] = $concentration;
        }

        $ndcGap = $this->ndcGap($country);
        if (null !== $ndcGap) {
            $components[] = $ndcGap;
        }

        if ([] === $components) {
            return ['value' => 0.5, 'components' => [['code' => 'no_data', 'value' => 0.5, 'explanation' => 'Aucune donnée réelle disponible pour ce pays - risque non évaluable, valeur neutre.']]];
        }

        $average = array_sum(array_column($components, 'value')) / \count($components);

        return ['value' => $average, 'components' => $components];
    }

    /**
     * @return array{code: string, value: float, explanation: string}|null null si moins de 2 années de données réelles (une volatilité n'a pas de sens sur un seul point)
     */
    private function flowVolatility(int $countryId): ?array
    {
        $rows = $this->fundingRepository->findYearlyTotalsForCountry($countryId);
        if (\count($rows) < 2) {
            return null;
        }

        $totals = array_map(static fn (array $row): float => (float) $row['total'], $rows);
        $mean = array_sum($totals) / \count($totals);
        if ($mean <= 0.0) {
            return null;
        }

        $variance = array_sum(array_map(static fn (float $total): float => ($total - $mean) ** 2, $totals)) / \count($totals);
        $coefficientOfVariation = sqrt($variance) / $mean;
        $risk = min(1.0, $coefficientOfVariation / self::VOLATILITY_CV_AT_MAX_RISK);

        return [
            'code' => 'flow_volatility',
            'value' => $risk,
            'explanation' => \sprintf('Coefficient de variation des flux annuels réels sur %d ans : %.2f.', \count($totals), $coefficientOfVariation),
        ];
    }

    /**
     * Indice de Herfindahl-Hirschman (somme des parts au carré) sur les
     * bailleurs réels de ce pays - déjà naturellement 0-1 (1/n si
     * parfaitement diversifié entre n bailleurs, 1.0 si un seul).
     *
     * @return array{code: string, value: float, explanation: string}|null null si aucun financement réel n'existe pour ce pays
     */
    private function donorConcentration(int $countryId): ?array
    {
        $rows = $this->fundingRepository->findSourceTotalsForCountry($countryId);
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
            'code' => 'donor_concentration',
            'value' => $hhi,
            'explanation' => \sprintf('Indice de concentration des bailleurs (HHI) sur %d bailleur(s) réel(s) : %.2f.', \count($rows), $hhi),
        ];
    }

    /**
     * @return array{code: string, value: float, explanation: string}|null null si aucune CDN chiffrée n'existe pour ce pays (F7 : ~12/54 pays)
     */
    private function ndcGap(Country $country): ?array
    {
        $ndcTarget = $this->ndcTargetRepository->findOneByCountry((int) $country->getId());
        if (null === $ndcTarget || null === $ndcTarget->getDeclaredNeedUsd()) {
            return null;
        }

        $need = (float) $ndcTarget->getDeclaredNeedUsd();
        if ($need <= 0.0) {
            return null;
        }

        $received = (float) ($this->fundingRepository->findTotalReceivedForCountry((int) $country->getId()) ?? '0');
        $gap = max(0.0, 1.0 - ($received / $need));

        return [
            'code' => 'ndc_gap',
            'value' => $gap,
            'explanation' => \sprintf('Écart entre besoin CDN déclaré (%s USD) et financement réel reçu (%s USD).', number_format($need, 0, ',', ' '), number_format($received, 0, ',', ' ')),
        ];
    }
}
