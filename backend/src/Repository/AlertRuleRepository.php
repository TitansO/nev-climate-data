<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AlertRule;
use App\Entity\Enum\AlertRuleType;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AlertRule>
 */
class AlertRuleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AlertRule::class);
    }

    /**
     * @return list<AlertRule>
     */
    public function findByUser(User $user): array
    {
        return $this->findBy(['user' => $user], ['createdAt' => 'DESC']);
    }

    /**
     * Groupées par type pour App\Service\AlertEvaluationService : chaque
     * condition n'a besoin d'être calculée qu'une fois par exécution, puis
     * distribuée à tous les propriétaires actifs pour ce type, plutôt que
     * recalculée une fois par règle.
     *
     * @return list<AlertRule>
     */
    public function findActiveByType(AlertRuleType $ruleType): array
    {
        return $this->findBy(['ruleType' => $ruleType, 'active' => true]);
    }
}
