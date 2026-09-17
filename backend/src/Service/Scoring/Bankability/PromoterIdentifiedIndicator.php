<?php

declare(strict_types=1);

namespace App\Service\Scoring\Bankability;

use App\Entity\Project;
use App\Service\Scoring\ScoreIndicatorInterface;

/**
 * C2.4 (0,1) : "présence d'un porteur identifié". Project::$promoter est
 * NOT NULL en base (toujours une chaîne), donc le signal réel n'est pas
 * "renseigné ou non" mais "s'agit-il d'un nom réel ou d'un espace
 * réservé" - une courte liste de termes génériques plutôt qu'un modèle,
 * transparente et documentée ici comme la seule source de vérité.
 *
 * @implements ScoreIndicatorInterface<Project>
 */
final class PromoterIdentifiedIndicator implements ScoreIndicatorInterface
{
    private const MIN_LENGTH = 3;

    /** @var list<string> */
    private const PLACEHOLDER_TERMS = ['tbd', 'à déterminer', 'a determiner', 'unknown', 'n/a', 'inconnu'];

    public function code(): string
    {
        return 'promoter_identified';
    }

    public function weight(): float
    {
        return 0.1;
    }

    public function compute(mixed $subject): float
    {
        return self::isIdentified($subject->getPromoter()) ? 1.0 : 0.0;
    }

    public function explain(mixed $subject): string
    {
        return self::isIdentified($subject->getPromoter())
            ? \sprintf('Porteur identifié : "%s".', $subject->getPromoter())
            : 'Aucun porteur identifié (champ vide ou espace réservé).';
    }

    private static function isIdentified(string $promoter): bool
    {
        $normalized = mb_strtolower(trim($promoter));

        return mb_strlen($normalized) >= self::MIN_LENGTH && !\in_array($normalized, self::PLACEHOLDER_TERMS, true);
    }
}
