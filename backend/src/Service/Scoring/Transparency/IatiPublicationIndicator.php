<?php

declare(strict_types=1);

namespace App\Service\Scoring\Transparency;

use App\Entity\Source;
use App\Service\Scoring\ScoreIndicatorInterface;

/**
 * C3.6 (0,2) : "publication IATI oui/non". App\Entity\Source ne porte pas
 * de champ dédié - proxy par le nom de la source, qui identifie
 * explicitement "IATI Datastore" comme origine pour les connecteurs
 * concernés (GCF, BAD - voir SourceFixtures) : un signal réel, pas
 * inventé, mais fragile si une future source publiait sur IATI sous un
 * nom qui ne le mentionne pas.
 *
 * @implements ScoreIndicatorInterface<Source>
 */
final class IatiPublicationIndicator implements ScoreIndicatorInterface
{
    public function code(): string
    {
        return 'iati_publication';
    }

    public function weight(): float
    {
        return 0.2;
    }

    public function compute(mixed $subject): float
    {
        return self::publishesOnIati($subject) ? 1.0 : 0.0;
    }

    public function explain(mixed $subject): string
    {
        return self::publishesOnIati($subject)
            ? 'Publie ses données sur le registre IATI Datastore.'
            : 'Aucune publication connue sur le registre IATI Datastore.';
    }

    private static function publishesOnIati(Source $source): bool
    {
        return str_contains(mb_strtolower($source->getName()), 'iati');
    }
}
