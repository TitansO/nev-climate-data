<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\ScoreSubjectType;
use App\Repository\ScoreRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * F2 (fondation du sprint C1-C3) : table générique de score, partagée par
 * tous les scores C2/C3 (bancabilité, alignement CDN, classification,
 * risque investissement, équité, transparence...) plutôt qu'une table par
 * score - voir `App\Service\Scoring\ScoreService` (F3) pour le moteur qui
 * l'alimente et `docs/superpowers/plans/2026-09-10-c1-c3-implementation-plan.md`.
 *
 * Une seule ligne courante par (subjectType, subjectId, scoreType) - pas
 * d'historisation SCD2 comme `Funding`/`Emission` : un score est une
 * évaluation recalculée à la demande, à chaque appel d'un endpoint de
 * score (`ScoreService::compute()`, appelé par le service consommateur
 * concerné - EquityScoreService, NdcAlignmentScoreService, etc. - jamais
 * par un recalcul de masse planifié), pas un flux de données sourcées à
 * conserver version par version. `breakdown`
 * (JSONB) rend chaque score auditable : le détail par indicateur
 * (poids, valeur normalisée, explication) plutôt qu'un simple nombre.
 */
#[ORM\Table(name: 'score')]
#[ORM\UniqueConstraint(name: 'uniq_score_subject_type', columns: ['subject_type', 'subject_id', 'score_type'])]
#[ORM\Index(columns: ['subject_type', 'subject_id'], name: 'idx_score_subject')]
#[ORM\Entity(repositoryClass: ScoreRepository::class)]
class Score
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, enumType: ScoreSubjectType::class, length: 20)]
    private ScoreSubjectType $subjectType;

    #[ORM\Column]
    private int $subjectId;

    // Volontairement une chaîne libre, pas un enum : de nouveaux types de
    // score arrivent à chaque tâche C2/C3 (bankability, ndc_alignment,
    // investment_risk, equity, transparency...) - un enum obligerait une
    // migration de mapping à chaque ajout, alors que la table elle-même
    // n'a besoin de rien changer.
    #[ORM\Column(length: 50)]
    private string $scoreType;

    // 0-100, jamais négatif ni au-delà - voir ScoreService::recompute().
    #[ORM\Column(type: Types::FLOAT)]
    private float $value;

    // A-E, voir ScoreService::gradeFor().
    #[ORM\Column(length: 1)]
    private string $grade;

    /** @var array<int, array{code: string, value: float, weight: float, explanation: string}> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $breakdown;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $computedAt;

    // C3.8 : valeur juste avant le dernier refresh(), pour détecter une
    // dégradation (App\Service\AlertEvaluationService) sans réintroduire
    // une historisation complète (voir le docblock de la classe) - une
    // seule valeur précédente suffit à l'alerte "score dégradé". Reste
    // NULL tant qu'un score n'a pas été recalculé au moins une fois après
    // l'ajout de ce champ.
    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $previousValue = null;

    /**
     * @param array<int, array{code: string, value: float, weight: float, explanation: string}> $breakdown
     */
    public function __construct(
        ScoreSubjectType $subjectType,
        int $subjectId,
        string $scoreType,
        float $value,
        string $grade,
        array $breakdown,
    ) {
        $this->subjectType = $subjectType;
        $this->subjectId = $subjectId;
        $this->scoreType = $scoreType;
        $this->value = $value;
        $this->grade = $grade;
        $this->breakdown = $breakdown;
        $this->computedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSubjectType(): ScoreSubjectType
    {
        return $this->subjectType;
    }

    public function getSubjectId(): int
    {
        return $this->subjectId;
    }

    public function getScoreType(): string
    {
        return $this->scoreType;
    }

    public function getValue(): float
    {
        return $this->value;
    }

    public function getGrade(): string
    {
        return $this->grade;
    }

    /** @return array<int, array{code: string, value: float, weight: float, explanation: string}> */
    public function getBreakdown(): array
    {
        return $this->breakdown;
    }

    public function getComputedAt(): \DateTimeImmutable
    {
        return $this->computedAt;
    }

    public function getPreviousValue(): ?float
    {
        return $this->previousValue;
    }

    /**
     * Recalcule cette même ligne en place - un score n'est jamais
     * historisé, seulement rafraîchi (voir le docblock de la classe).
     *
     * @param array<int, array{code: string, value: float, weight: float, explanation: string}> $breakdown
     */
    public function refresh(float $value, string $grade, array $breakdown): static
    {
        $this->previousValue = $this->value;
        $this->value = $value;
        $this->grade = $grade;
        $this->breakdown = $breakdown;
        $this->computedAt = new \DateTimeImmutable();

        return $this;
    }
}
