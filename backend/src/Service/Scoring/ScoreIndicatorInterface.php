<?php

declare(strict_types=1);

namespace App\Service\Scoring;

use App\Entity\Enum\ScoreSubjectType;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * F3 (fondation du sprint C1-C3) : un indicateur pondéré contribuant à un
 * score composite (`App\Entity\Score`). Chaque tâche C2/C3 qui introduit
 * un score (bancabilité C2.4, alignement CDN C2.6, risque investissement
 * C2.8, équité C3.3, transparence C3.6...) fournit une ou plusieurs
 * classes implémentant cette interface plutôt que du code de scoring
 * dispersé - c'est ce qui rend chaque score auditable de la même façon
 * (voir `ScoreService::recompute()` et le `breakdown` de `Score`).
 *
 * `#[AutoconfigureTag]` sur l'interface : toute classe qui l'implémente
 * est automatiquement collectée par `ScoreService` via
 * `#[AutowireIterator]` - aucune configuration YAML à ajouter à chaque
 * nouvel indicateur.
 */
#[AutoconfigureTag('app.score_indicator')]
interface ScoreIndicatorInterface
{
    /** Identifiant stable de l'indicateur, unique au sein d'un scoreType (ex. "sector_coverage"). */
    public function code(): string;

    /** Le score composite auquel cet indicateur contribue (ex. "ndc_alignment"). */
    public function scoreType(): string;

    /** Le type de sujet que cet indicateur sait noter. */
    public function subjectType(): ScoreSubjectType;

    /** Poids de cet indicateur dans le score composite - entre 0 (exclu) et 1. */
    public function weight(): float;

    /**
     * Valeur normalisée 0.0-1.0 pour ce sujet, ou null si l'indicateur ne
     * peut pas être calculé (donnée manquante) - un indicateur non
     * calculable est exclu du score plutôt que de fausser la moyenne
     * pondérée avec une valeur devinée (voir ScoreService::recompute()).
     */
    public function compute(int $subjectId): ?float;

    /** Justification lisible de la valeur retournée par compute(), pour le breakdown audité. */
    public function explain(int $subjectId): string;
}
