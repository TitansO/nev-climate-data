<?php

declare(strict_types=1);

namespace App\Service\Scoring\Bankability;

use App\Entity\Project;
use App\Service\Scoring\CountryRiskCalculator;
use App\Service\Scoring\ScoreIndicatorInterface;

/**
 * C2.4 (0,3) : "risque pays (réutilise C2.8) inversé" - le plan demande
 * explicitement de réutiliser le calcul de risque pays plutôt que d'en
 * refaire un propre à la bancabilité. `compute()` renvoie 1 - risque : un
 * ScoreIndicatorInterface est toujours "plus haut = plus favorable", alors
 * que CountryRiskCalculator renvoie "plus haut = plus risqué" - l'inversion
 * a lieu ici, pas dans le calculateur partagé.
 *
 * @implements ScoreIndicatorInterface<Project>
 */
final class InvertedCountryRiskIndicator implements ScoreIndicatorInterface
{
    public function __construct(
        private readonly CountryRiskCalculator $countryRiskCalculator,
    ) {
    }

    public function code(): string
    {
        return 'country_risk_inverted';
    }

    public function weight(): float
    {
        return 0.3;
    }

    public function compute(mixed $subject): float
    {
        return 1.0 - $this->countryRiskCalculator->calculate($subject->getCountry())['value'];
    }

    public function explain(mixed $subject): string
    {
        $result = $this->countryRiskCalculator->calculate($subject->getCountry());
        $componentSummaries = array_map(static fn (array $component): string => $component['explanation'], $result['components']);

        return \sprintf('Risque pays (%.2f, inversé ci-dessus) : %s', $result['value'], implode(' ', $componentSummaries));
    }
}
