<?php

declare(strict_types=1);

namespace App\Service\Scoring\Transparency;

use App\Entity\Source;
use App\Repository\FundingRepository;
use App\Service\Scoring\ScoreIndicatorInterface;

/**
 * C3.6 (0,3) : "taux de champs renseignés sur ses lignes" - sur les 2
 * champs de App\Entity\Funding qui peuvent légitimement être absents
 * (`instrument` hors valeur par défaut "unknown", `originalAmount`), quelle
 * part des lignes courantes validées de cette source renseigne les deux.
 * Tout le reste du schéma est NOT NULL, donc toujours "complet" - inutile
 * de le compter. Neutre (0.5) si cette source n'a encore aucune ligne
 * réelle.
 *
 * @implements ScoreIndicatorInterface<Source>
 */
final class CompletenessIndicator implements ScoreIndicatorInterface
{
    public function __construct(
        private readonly FundingRepository $fundingRepository,
    ) {
    }

    public function code(): string
    {
        return 'completeness';
    }

    public function weight(): float
    {
        return 0.3;
    }

    public function compute(mixed $subject): float
    {
        $stats = $this->fundingRepository->findCompletenessStatsForSource((int) $subject->getId());

        return 0 === $stats['total'] ? 0.5 : $stats['complete'] / $stats['total'];
    }

    public function explain(mixed $subject): string
    {
        $stats = $this->fundingRepository->findCompletenessStatsForSource((int) $subject->getId());
        if (0 === $stats['total']) {
            return 'Aucune ligne de financement réelle pour cette source - complétude non évaluable.';
        }

        return \sprintf('%d lignes sur %d renseignent instrument et montant en devise locale.', $stats['complete'], $stats['total']);
    }
}
