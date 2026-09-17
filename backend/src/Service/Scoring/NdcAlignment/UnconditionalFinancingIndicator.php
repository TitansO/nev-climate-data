<?php

declare(strict_types=1);

namespace App\Service\Scoring\NdcAlignment;

use App\Entity\NdcTarget;
use App\Service\Scoring\ScoreIndicatorInterface;

/**
 * C2.6 (0,2) : "présence de financement inconditionnel vs conditionnel".
 * `Funding` ne porte aucun champ de conditionnalité (seul `FundingType` -
 * public/privé/multilatéral - existe, une distinction différente) : le
 * seul signal réel disponible dans ce schéma est `NdcTarget::$conditional`
 * - la cible elle-même, pas les financements reçus. Interprétation
 * retenue, documentée ici explicitement : une CDN inconditionnelle
 * (financée sur ressources propres, `conditional = false`) traduit un
 * alignement/une appropriation plus forts qu'une CDN dont l'ambition
 * dépend d'un appui international - score plein pour `false`, nul pour
 * `true`. À noter : NdcTargetFixtures documente que ses 12 lignes sont
 * TOUTES `conditional = true` (la cible la plus souvent citée dans la
 * presse pour chaque pays est presque toujours la cible conditionnelle,
 * la plus ambitieuse) - ce composant vaudra donc 0 pour tous les pays du
 * jeu de démonstration actuel, un résultat réel et attendu, pas un bug.
 *
 * @implements ScoreIndicatorInterface<NdcTarget>
 */
final class UnconditionalFinancingIndicator implements ScoreIndicatorInterface
{
    public function code(): string
    {
        return 'unconditional_financing';
    }

    public function weight(): float
    {
        return 0.2;
    }

    public function compute(mixed $subject): float
    {
        return $subject->isConditional() ? 0.0 : 1.0;
    }

    public function explain(mixed $subject): string
    {
        return $subject->isConditional()
            ? 'Cible CDN conditionnelle - ambition dépendante d\'un appui financier international.'
            : 'Cible CDN inconditionnelle - engagement financé sur ressources propres.';
    }
}
