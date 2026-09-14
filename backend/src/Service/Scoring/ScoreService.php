<?php

declare(strict_types=1);

namespace App\Service\Scoring;

use App\Entity\Enum\ScoreSubjectType;
use App\Entity\Score;
use App\Repository\ScoreRepository;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * F3 (fondation du sprint C1-C3) : moteur générique de scoring - combine
 * les indicateurs pondérés (`ScoreIndicatorInterface`) enregistrés pour un
 * (scoreType, subjectType) donné en un score 0-100 + grade A-E, persisté
 * via `ScoreRepository` (F2). Ne contient aucun indicateur concret : ceux-ci
 * arrivent avec chaque tâche C2/C3 (bancabilité, alignement CDN, risque
 * investissement, équité, transparence...). Voir le document de référence
 * pour le choix assumé de rester sur des indicateurs pondérés transparents
 * plutôt que du ML entraîné dans ce sprint.
 */
final class ScoreService
{
    /**
     * @param iterable<ScoreIndicatorInterface> $indicators
     */
    public function __construct(
        #[AutowireIterator('app.score_indicator')]
        private readonly iterable $indicators,
        private readonly ScoreRepository $scoreRepository,
    ) {
    }

    /**
     * Recalcule (et persiste) le score `scoreType` pour ce sujet, à partir
     * de tous les indicateurs enregistrés pour ce (scoreType, subjectType).
     * Retourne null si aucun indicateur applicable n'a pu être calculé
     * pour ce sujet (donnée manquante) - dans ce cas, aucune ligne n'est
     * créée ni modifiée : un score qu'on ne sait pas calculer honnêtement
     * ne doit jamais apparaître comme "0".
     */
    public function recompute(string $scoreType, ScoreSubjectType $subjectType, int $subjectId): ?Score
    {
        $weightedSum = 0.0;
        $totalWeight = 0.0;
        $breakdown = [];

        foreach ($this->indicators as $indicator) {
            if ($indicator->scoreType() !== $scoreType || $indicator->subjectType() !== $subjectType) {
                continue;
            }

            $raw = $indicator->compute($subjectId);
            if (null === $raw) {
                continue;
            }

            $weight = $indicator->weight();
            $weightedSum += $raw * $weight;
            $totalWeight += $weight;
            $breakdown[] = [
                'code' => $indicator->code(),
                'value' => $raw,
                'weight' => $weight,
                'explanation' => $indicator->explain($subjectId),
            ];
        }

        if ($totalWeight <= 0.0) {
            return null;
        }

        // Re-normalisé sur le poids des seuls indicateurs réellement
        // calculés (pas la somme théorique de tous les indicateurs
        // enregistrés) - un sujet pour lequel un seul indicateur sur trois
        // est calculable est noté sur cet indicateur seul, pas pénalisé
        // par une donnée manquante ailleurs.
        $normalized = $weightedSum / $totalWeight;
        $value = round($normalized * 100, 1);

        return $this->scoreRepository->upsert($subjectType, $subjectId, $scoreType, $value, self::gradeFor($value), $breakdown);
    }

    public static function gradeFor(float $value): string
    {
        return match (true) {
            $value >= 80 => 'A',
            $value >= 60 => 'B',
            $value >= 40 => 'C',
            $value >= 20 => 'D',
            default => 'E',
        };
    }
}
