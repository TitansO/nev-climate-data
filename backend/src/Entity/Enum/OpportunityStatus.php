<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * C1.8 : statut d'un `Opportunity` par rapport à aujourd'hui - jamais
 * persisté (calculé à la volée depuis applicationWindowOpensAt/
 * ClosesAt, voir App\Service\OpportunityService), reflète exactement les 3
 * cas de lecture que OpportunityFixtures documente déjà : déjà fermée,
 * ouverte, ou pas encore ouverte.
 */
enum OpportunityStatus: string
{
    case Open = 'open';
    case Upcoming = 'upcoming';
    case Closed = 'closed';
}
