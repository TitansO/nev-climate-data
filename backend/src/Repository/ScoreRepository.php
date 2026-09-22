<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Enum\ScoreSubjectType;
use App\Entity\Score;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Score>
 */
class ScoreRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Score::class);
    }

    public function findOneBySubjectAndType(ScoreSubjectType $subjectType, int $subjectId, string $scoreType): ?Score
    {
        return $this->findOneBy([
            'subjectType' => $subjectType,
            'subjectId' => $subjectId,
            'scoreType' => $scoreType,
        ]);
    }

    /**
     * F2/F3 : recalcule (ou crée) la ligne courante pour ce
     * (subjectType, subjectId, scoreType) - un score n'a jamais plus
     * d'une ligne courante (voir `Score`'s docblock, contrainte
     * `uniq_score_subject_type`).
     *
     * @param array<int, array{code: string, value: float, weight: float, explanation: string}> $breakdown
     */
    public function upsert(
        ScoreSubjectType $subjectType,
        int $subjectId,
        string $scoreType,
        float $value,
        string $grade,
        array $breakdown,
    ): Score {
        $existing = $this->findOneBySubjectAndType($subjectType, $subjectId, $scoreType);
        $entityManager = $this->getEntityManager();

        if ($existing !== null) {
            $existing->refresh($value, $grade, $breakdown);
            $entityManager->flush();

            return $existing;
        }

        $score = new Score($subjectType, $subjectId, $scoreType, $value, $grade, $breakdown);
        $entityManager->persist($score);
        $entityManager->flush();

        return $score;
    }

    /**
     * C3.8 (alerte "score dégradé") : scores dont la valeur a chuté d'au
     * moins `$thresholdPoints` points (sur 100) depuis leur `previousValue`
     * (App\Entity\Score::refresh()). `previousValue IS NOT NULL` exclut de
     * fait tout score qui n'a encore jamais été recalculé depuis l'ajout de
     * ce champ - il n'y a alors rien à comparer, jamais une dégradation
     * fabriquée depuis une valeur absente.
     *
     * @return list<Score>
     */
    public function findDegraded(float $thresholdPoints): array
    {
        return $this->createQueryBuilder('score')
            ->andWhere('score.previousValue IS NOT NULL')
            ->andWhere('score.previousValue - score.value >= :threshold')
            ->setParameter('threshold', $thresholdPoints)
            ->getQuery()
            ->getResult();
    }
}
