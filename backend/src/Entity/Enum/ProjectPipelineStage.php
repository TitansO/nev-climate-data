<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * F4 (fondation du sprint C1-C3) : stade d'avancement d'un `Project` dans
 * le pipeline de financement - alimente le tableau Kanban de C2.3.
 * Ordre logique du cycle de vie ; `Rejected` peut survenir depuis
 * n'importe quel stade précédent (pas seulement `Fundraising`).
 */
enum ProjectPipelineStage: string
{
    case Idea = 'idea';
    case Scoping = 'scoping';
    case Structuring = 'structuring';
    case Fundraising = 'fundraising';
    case Financed = 'financed';
    case Rejected = 'rejected';
}
