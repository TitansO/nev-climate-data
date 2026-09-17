<?php

declare(strict_types=1);

namespace App\Service\Scoring\Bankability;

use App\Entity\Enum\FundingInstrument;
use App\Entity\Project;
use App\Repository\FundingRepository;
use App\Service\Scoring\ScoreIndicatorInterface;

/**
 * C2.4 (0,15) : "instrument réaliste pour le pays" - ce pays a-t-il déjà
 * réellement reçu ce type d'instrument ? Trois cas, jamais un simple
 * booléen : précédent réel (1.0), le pays a des financements réels mais
 * jamais avec cet instrument (0.3, moins certain mais pas disqualifiant -
 * un premier prêt garanti reste possible), et aucune donnée réelle du
 * tout pour ce pays (0.5, neutre - rien à en tirer). Instrument pas
 * encore arrêté (nullable, voir Project::$instrumentSought) : neutre
 * aussi, jamais une pénalité pour un projet simplement pas assez avancé.
 *
 * @implements ScoreIndicatorInterface<Project>
 */
final class RealisticInstrumentIndicator implements ScoreIndicatorInterface
{
    public function __construct(
        private readonly FundingRepository $fundingRepository,
    ) {
    }

    public function code(): string
    {
        return 'realistic_instrument';
    }

    public function weight(): float
    {
        return 0.15;
    }

    public function compute(mixed $subject): float
    {
        $instrument = $subject->getInstrumentSought();
        if (null === $instrument || FundingInstrument::Unknown === $instrument) {
            return 0.5;
        }

        $countryId = (int) $subject->getCountry()->getId();
        if ($this->fundingRepository->hasValidatedFundingForCountryAndInstrument($countryId, $instrument)) {
            return 1.0;
        }

        return null === $this->fundingRepository->findTotalReceivedForCountry($countryId) ? 0.5 : 0.3;
    }

    public function explain(mixed $subject): string
    {
        $instrument = $subject->getInstrumentSought();
        if (null === $instrument || FundingInstrument::Unknown === $instrument) {
            return 'Instrument recherché pas encore arrêté.';
        }

        $countryId = (int) $subject->getCountry()->getId();
        $countryName = $subject->getCountry()->getName();
        if ($this->fundingRepository->hasValidatedFundingForCountryAndInstrument($countryId, $instrument)) {
            return \sprintf('%s a déjà reçu un financement réel de type "%s".', $countryName, $instrument->value);
        }

        if (null === $this->fundingRepository->findTotalReceivedForCountry($countryId)) {
            return \sprintf('Aucun financement réel validé pour %s - précédent non évaluable.', $countryName);
        }

        return \sprintf('%s a des financements réels mais jamais de type "%s".', $countryName, $instrument->value);
    }
}
