<?php

declare(strict_types=1);

namespace App\Service\Scoring;

use App\Entity\Enum\ScoreSubjectType;
use App\Entity\Score;
use App\Repository\ScoreRepository;

/**
 * F3 (fondation du sprint C1-C3) : moteur de scoring générique - combine
 * une liste d'indicateurs (App\Service\Scoring\ScoreIndicatorInterface) en
 * un score 0-100 + grade A-E, persisté via App\Repository\ScoreRepository
 * (F2). Jamais de modèle entraîné : "l'explicabilité d'un score prime sur
 * sa sophistication" (plan §1) - une simple somme pondérée d'indicateurs
 * normalisés, chacun expliqué dans `breakdown`.
 *
 * Un score consommateur (BankabilityScoreService, NdcAlignmentScoreService,
 * ...) construit sa propre liste d'indicateurs pour son `$scoreType` et
 * appelle compute() - ScoreService lui-même ne connaît aucun indicateur
 * concret, seulement l'interface.
 */
final class ScoreService
{
    public function __construct(
        private readonly ScoreRepository $scoreRepository,
    ) {
    }

    /**
     * @param list<ScoreIndicatorInterface> $indicators
     */
    public function compute(ScoreSubjectType $subjectType, int $subjectId, string $scoreType, mixed $subject, array $indicators): Score
    {
        $breakdown = [];
        $weightedSum = 0.0;
        $totalWeight = 0.0;

        foreach ($indicators as $indicator) {
            $value = max(0.0, min(1.0, $indicator->compute($subject)));
            $weight = $indicator->weight();

            $breakdown[] = [
                'code' => $indicator->code(),
                'value' => round($value, 4),
                'weight' => $weight,
                'explanation' => $indicator->explain($subject),
            ];

            $weightedSum += $value * $weight;
            $totalWeight += $weight;
        }

        // totalWeight ne devrait jamais être 0 (chaque score consommateur
        // fournit une liste d'indicateurs à poids fixes) - gardé pour ne
        // jamais diviser par zéro si un score futur en décidait autrement.
        $normalized = $totalWeight > 0.0 ? $weightedSum / $totalWeight : 0.0;
        $value = round($normalized * 100, 2);

        return $this->scoreRepository->upsert($subjectType, $subjectId, $scoreType, $value, self::gradeFor($value), $breakdown);
    }

    /**
     * A : ≥80, B : ≥60, C : ≥40, D : ≥20, E : <20 - bornes rondes,
     * documentées ici comme la seule source de vérité (jamais
     * recalculées ailleurs).
     */
    public static function gradeFor(float $value): string
    {
        return match (true) {
            $value >= 80.0 => 'A',
            $value >= 60.0 => 'B',
            $value >= 40.0 => 'C',
            $value >= 20.0 => 'D',
            default => 'E',
        };
    }

    /**
     * Forme JSON partagée par tous les endpoints de score (C2.4, C2.6, et
     * les futurs C2.7/C2.8/C3.3/C3.6) - une seule fois ici plutôt que
     * dupliquée par contrôleur.
     *
     * @return array{subjectType: string, subjectId: int, scoreType: string, value: float, grade: string, breakdown: array<int, array{code: string, value: float, weight: float, explanation: string}>, computedAt: string}
     */
    public static function toArray(Score $score): array
    {
        return [
            'subjectType' => $score->getSubjectType()->value,
            'subjectId' => $score->getSubjectId(),
            'scoreType' => $score->getScoreType(),
            'value' => $score->getValue(),
            'grade' => $score->getGrade(),
            'breakdown' => $score->getBreakdown(),
            'computedAt' => $score->getComputedAt()->format(\DATE_ATOM),
        ];
    }
}
