<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * Ajouté le 2026-09-28 avec la fusion des stades `Financed`/`Rejected` de
 * `ProjectPipelineStage` en un seul stade `Decision` : cette énumération
 * porte désormais l'issue réelle, affichée comme étiquette sur la carte du
 * Kanban plutôt que comme colonne. `null` sur `Project::$decisionOutcome`
 * tant qu'aucune issue n'a encore été choisie pour un projet au stade
 * `Decision`.
 */
enum ProjectDecisionOutcome: string
{
    case Financed = 'financed';
    case Rejected = 'rejected';
}
