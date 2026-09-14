<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * F2 (fondation du sprint C1-C3) : type de l'objet noté par une ligne
 * `Score` (`App\Entity\Score`). Pays et institutions existent dès
 * aujourd'hui ; `Project` et `Funding` seront réellement notés à partir
 * de F4/C2.4 (bancabilité) et consorts - le type existe déjà pour que le
 * framework de scoring (F3) n'ait pas à évoluer quand ces scores
 * arriveront.
 */
enum ScoreSubjectType: string
{
    case Country = 'country';
    case Source = 'source';
    case Project = 'project';
    case Funding = 'funding';
}
