<?php

declare(strict_types=1);

namespace App\Service\Scoring\Transparency;

use App\Entity\Source;
use App\Service\Scoring\ScoreIndicatorInterface;

/**
 * C3.6 (0,1) : "présence de descriptif" - TOUJOURS neutre (0.5), quelle
 * que soit la source. Ni App\Entity\Source ni App\Entity\Funding ne
 * portent de champ de texte libre dans ce schéma (voir le docblock de
 * LocalFinanceService, même constat pour C3.2) - conservé comme indicateur
 * à part entière plutôt que retiré, même raisonnement que
 * GranularityIndicator.
 *
 * @implements ScoreIndicatorInterface<Source>
 */
final class DescriptivePresenceIndicator implements ScoreIndicatorInterface
{
    public function code(): string
    {
        return 'descriptive_presence';
    }

    public function weight(): float
    {
        return 0.1;
    }

    public function compute(mixed $subject): float
    {
        return 0.5;
    }

    public function explain(mixed $subject): string
    {
        return 'Non évaluable : aucun champ de texte libre par financement n\'existe dans ce schéma.';
    }
}
