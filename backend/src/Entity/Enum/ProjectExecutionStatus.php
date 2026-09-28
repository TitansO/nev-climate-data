<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * Ajouté le 2026-09-28 : état d'exécution d'un projet une fois `Financed`
 * (voir `ProjectDecisionOutcome`) et déplacé au stade
 * `ProjectPipelineStage::Execution` - affiché comme étiquette sur sa carte
 * du Kanban. `null` sur `Project::$executionStatus` tant qu'un projet n'a
 * pas atteint ce stade.
 */
enum ProjectExecutionStatus: string
{
    case InProgress = 'in_progress';
    case Closed = 'closed';
}
