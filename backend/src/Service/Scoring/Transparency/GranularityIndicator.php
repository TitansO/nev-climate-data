<?php

declare(strict_types=1);

namespace App\Service\Scoring\Transparency;

use App\Entity\Source;
use App\Service\Scoring\ScoreIndicatorInterface;

/**
 * C3.6 (0,2) : "granularité projet vs agrégat" - TOUJOURS neutre (0.5),
 * quelle que soit la source. App\Entity\Funding est une ligne agrégée
 * (un total par pays x secteur x année x source x type) pour TOUTE source
 * de ce schéma, sans exception (voir le docblock de LocalFinanceService
 * pour le même constat côté C3.2) - il n'existe aucun signal permettant
 * de distinguer une source qui publierait par activité individuelle d'une
 * autre qui n'agrège qu'au niveau national. Conservé comme indicateur à
 * part entière (plutôt que retiré de la pondération) pour que
 * `Score::$breakdown` reste honnête sur ce qui a été évalué vs. non
 * disponible, au lieu de silencieusement redistribuer son poids sur les
 * 4 autres.
 *
 * @implements ScoreIndicatorInterface<Source>
 */
final class GranularityIndicator implements ScoreIndicatorInterface
{
    public function code(): string
    {
        return 'granularity';
    }

    public function weight(): float
    {
        return 0.2;
    }

    public function compute(mixed $subject): float
    {
        return 0.5;
    }

    public function explain(mixed $subject): string
    {
        return 'Non évaluable : ce schéma n\'agrège les financements qu\'au niveau pays x secteur x année, quelle que soit la source.';
    }
}
