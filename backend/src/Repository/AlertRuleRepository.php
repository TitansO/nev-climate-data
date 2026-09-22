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
     * Même raisonnement que NotificationRepository::findOneForUser() : la
     * propriété est vérifiée directement dans la requête, jamais après
     * coup en PHP - un id existant appartenant à un autre utilisateur se
     * comporte exactement comme un id inexistant (null), sans fuite.
     */
    public function findOneForUser(int $id, User $user): ?AlertRule
    {
        return $this->findOneBy(['id' => $id, 'user' => $user]);
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
