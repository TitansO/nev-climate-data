<?php

declare(strict_types=1);

namespace App\Service\Scoring\Bankability;

use App\Entity\Project;
use App\Service\Scoring\ScoreIndicatorInterface;

/**
 * C2.4 (0,25) : "complétude du dossier". Project n'a pas de notion de
 * pièces jointes/documents dans ce sprint - les seuls signaux structurés
 * de complétude sont un instrument recherché déjà arrêté (nullable par
 * conception, voir Project::$instrumentSought) et une description
 * suffisamment développée pour ne pas être un simple placeholder (tous
 * les autres champs sont NOT NULL en base, donc toujours "présents" sans
 * jamais renseigner sur leur qualité réelle).
 *
 * @implements ScoreIndicatorInterface<Project>
 */
final class CompletenessIndicator implements ScoreIndicatorInterface
{
    private const SUBSTANTIVE_DESCRIPTION_LENGTH = 150;

    public function code(): string
    {
        return 'completeness';
    }

    public function weight(): float
    {
        return 0.25;
    }

    public function compute(mixed $subject): float
    {
        $value = 0.0;
        if (null !== $subject->getInstrumentSought()) {
            $value += 0.5;
        }
        if (mb_strlen(trim($subject->getDescription())) >= self::SUBSTANTIVE_DESCRIPTION_LENGTH) {
            $value += 0.5;
        }

        return $value;
    }

    public function explain(mixed $subject): string
    {
        $hasInstrument = null !== $subject->getInstrumentSought() ? 'oui' : 'non';
        $descriptionLength = mb_strlen(trim($subject->getDescription()));

        return \sprintf('Instrument recherché arrêté : %s. Description : %d caractères (seuil : %d).', $hasInstrument, $descriptionLength, self::SUBSTANTIVE_DESCRIPTION_LENGTH);
    }
}
