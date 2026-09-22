<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\AlertRuleType;
use App\Repository\AlertRuleRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * C3.8 : "moteur de règles" - une ligne = un utilisateur qui souhaite être
 * notifié d'une condition (App\Entity\Enum\AlertRuleType). Volontairement
 * sans filtre de portée (pays/secteur) : chaque condition est évaluée
 * globalement par App\Service\AlertEvaluationService, une règle "gap
 * critique" notifie son propriétaire pour TOUT gap critique détecté - le
 * plan ne demande pas de règles finement scopées, seulement les 4
 * conditions elles-mêmes.
 */
#[ORM\Entity(repositoryClass: AlertRuleRepository::class)]
class AlertRule
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $user;

    #[ORM\Column(type: Types::STRING, enumType: AlertRuleType::class, length: 30)]
    private AlertRuleType $ruleType;

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $user, AlertRuleType $ruleType)
    {
        $this->user = $user;
        $this->ruleType = $ruleType;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getRuleType(): AlertRuleType
    {
        return $this->ruleType;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
