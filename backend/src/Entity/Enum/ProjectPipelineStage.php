<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * F4 (fondation du sprint C1-C3) : stade d'avancement d'un `Project` dans
 * le pipeline de financement - alimente le tableau Kanban de C2.3.
 * Ordre logique du cycle de vie. `Decision` fusionne les anciens stades
 * `Financed`/`Rejected` (2026-09-28) : l'issue réelle (financé ou rejeté)
 * est désormais portée par `Project::$decisionOutcome`, une étiquette sur
 * la carte plutôt qu'une colonne à part - un projet rejeté peut être
 * atteint depuis n'importe quel stade précédent, pas seulement
 * `Fundraising`. Un projet dont l'issue est `Financed` quitte ensuite la
 * colonne `Decision` pour `Execution` (suivi post-financement, voir
 * `Project::$executionStatus` - `en_cours`/`cloturé`) ; un projet `Rejected`
 * reste dans `Decision`, qui devient alors son stade terminal.
 */
enum ProjectPipelineStage: string
{
    case Idea = 'idea';
    case Scoping = 'scoping';
    case Structuring = 'structuring';
    case Fundraising = 'fundraising';
    case Decision = 'decision';
    case Execution = 'execution';
}
