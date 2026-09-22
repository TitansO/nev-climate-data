<?php

declare(strict_types=1);

namespace App\Service\Scoring\Equity;

use App\Entity\Country;
use App\Repository\CountryRepository;
use App\Repository\EmissionRepository;
use App\Repository\FundingRepository;
use App\Service\Scoring\ScoreIndicatorInterface;

/**
 * C3.3 (0,3) : "financement par tonne de CO2 (inversé)". Interprétation
 * assumée d'une formule non détaillée par le plan (à corriger si une
 * définition officielle existe) : un financement par tonne de CO2 émise
 * élevé PAR RAPPORT aux autres pays comparables signale une concentration
 * sur un nombre restreint de pays à faibles émissions plutôt qu'une
 * répartition équitable vis-à-vis des plus gros émetteurs - "inversé"
 * signifie donc qu'un ratio BAS (proche de 0 par rapport à la moyenne)
 * obtient la meilleure note, pas l'inverse. Émissions : dernière année
 * disponible (App\Repository\EmissionRepository), jamais cumulées sur
 * plusieurs années. Neutre (0.5) si ce pays n'a aucune donnée d'émission
 * courante.
 *
 * @implements ScoreIndicatorInterface<Country>
 */
final class FundingPerTonneInvertedIndicator implements ScoreIndicatorInterface
{
    private const MT_TO_TONNES = 1_000_000.0;

    /** @var array<int, float>|null */
    private ?array $latestYearEmissionsByCountryId = null;

    public function __construct(
        private readonly FundingRepository $fundingRepository,
        private readonly EmissionRepository $emissionRepository,
        private readonly CountryRepository $countryRepository,
    ) {
    }

    public function code(): string
    {
        return 'funding_per_tonne_inverted';
    }

    public function weight(): float
    {
        return 0.3;
    }

    public function compute(mixed $subject): float
    {
        $perTonne = $this->fundingPerTonneFor($subject);
        if (null === $perTonne) {
            return 0.5;
        }

        $poolAverage = $this->poolAverageFundingPerTonne();
        if (null === $poolAverage || $poolAverage <= 0.0) {
            return 0.5;
        }

        return 1.0 - min(1.0, $perTonne / $poolAverage);
    }

    public function explain(mixed $subject): string
    {
        $perTonne = $this->fundingPerTonneFor($subject);
        if (null === $perTonne) {
            return 'Aucune donnée d\'émission courante pour ce pays - ratio non évaluable.';
        }

        $poolAverage = $this->poolAverageFundingPerTonne();
        if (null === $poolAverage || $poolAverage <= 0.0) {
            return 'Aucun autre pays comparable disponible - ratio non évaluable.';
        }

        return \sprintf(
            '%.2f USD par tonne de CO2 émise (inversé), contre une moyenne de %.2f USD sur les pays comparables - plus bas est jugé plus équitable.',
            $perTonne,
            $poolAverage
        );
    }

    private function fundingPerTonneFor(Country $country): ?float
    {
        $emissionsByCountryId = $this->latestYearEmissions();
        $tonnesEmitted = $emissionsByCountryId[$country->getId()] ?? null;
        if (null === $tonnesEmitted || $tonnesEmitted <= 0.0) {
            return null;
        }

        $received = (float) ($this->fundingRepository->findTotalReceivedForCountry((int) $country->getId()) ?? '0');

        return $received / ($tonnesEmitted * self::MT_TO_TONNES);
    }

    private function poolAverageFundingPerTonne(): ?float
    {
        $pool = $this->countryRepository->findAllWithPopulationAndVulnerability();
        $values = array_filter(array_map($this->fundingPerTonneFor(...), $pool), static fn (?float $value): bool => null !== $value);

        return [] === $values ? null : array_sum($values) / \count($values);
    }

    /**
     * @return array<int, float> total valueMt de la dernière année disponible, par id de pays
     */
    private function latestYearEmissions(): array
    {
        if (null !== $this->latestYearEmissionsByCountryId) {
            return $this->latestYearEmissionsByCountryId;
        }

        $years = $this->emissionRepository->distinctYearsWithCurrentData();
        $this->latestYearEmissionsByCountryId = [] === $years ? [] : $this->emissionRepository->totalsByYear($years[0]);

        return $this->latestYearEmissionsByCountryId;
    }
}
