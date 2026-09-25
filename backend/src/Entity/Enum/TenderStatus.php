<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * A3.13 : statut d'un `Tender` (appel d'offres) par rapport à aujourd'hui -
 * jamais persisté (calculé à la volée depuis publishedAt/submissionDeadline,
 * voir App\Service\TenderService), même principe que OpportunityStatus
 * (C1.8) pour la cohérence entre les deux radars.
 */
enum TenderStatus: string
{
    case Open = 'open';
    case Upcoming = 'upcoming';
    case Closed = 'closed';
}
