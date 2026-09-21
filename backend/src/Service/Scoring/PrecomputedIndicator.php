<?php

declare(strict_types=1);

namespace App\Service\Scoring;

/**
 * C2.8 : adapte une valeur déjà calculée (par ex. un composant de
 * App\Service\Scoring\CountryRiskCalculator ou SectorRiskCalculator) en
 * ScoreIndicatorInterface, pour la faire passer par App\Service\Scoring\
 * ScoreService::compute() sans dupliquer sa logique de somme pondérée +
 * persistance. `compute()`/`explain()` ignorent `$subject` : la valeur et
 * l'explication sont déjà connues au moment de la construction.
 *
 * @implements ScoreIndicatorInterface<mixed>
 */
final class PrecomputedIndicator implements ScoreIndicatorInterface
{
    public function __construct(
        private readonly string $code,
        private readonly float $weight,
        private readonly float $value,
        private readonly string $explanation,
    ) {
    }

    public function code(): string
    {
        return $this->code;
    }

    public function weight(): float
    {
        return $this->weight;
    }

    public function compute(mixed $subject): float
    {
        return $this->value;
    }

    public function explain(mixed $subject): string
    {
        return $this->explanation;
    }
}
