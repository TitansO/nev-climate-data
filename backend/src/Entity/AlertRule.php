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
// Audit post-C3 2026-10-02 (P-09) : index unique partiel (WHERE active =
// true), pas une contrainte plate - une règle désactivée reste en base
// (historique préservé, voir update() dans App\Controller\AlertRuleController)
// et une nouvelle règle active du même type peut légitimement être créée
// ensuite ; seul "au plus une règle ACTIVE par (utilisateur, type)" doit
// être garanti. Avant cette contrainte, AlertRuleController::create()
// vérifiait l'absence d'une règle active par une simple boucle PHP avant
// l'insertion (check-then-insert, sans verrou) - confirmé en direct
// pendant l'audit : 15 créations concurrentes du même ruleType ont produit
// 2 lignes actives dupliquées. Même motif que la contrainte partielle déjà
// établie sur Funding.php (connue limitation Doctrine/DBAL : le
// comparateur de schéma Postgres ne relit pas la clause WHERE d'un index
// partiel, donc `doctrine:schema:validate` la signale systématiquement
// "not in sync" même juste après une migration qui l'a appliquée
// correctement - faux positif permanent, ne pas "corriger" en relançant
// migrations:diff).
#[ORM\UniqueConstraint(
    name: 'uniq_alert_rule_user_type_active',
    columns: ['user_id', 'rule_type'],
    options: ['where' => 'active = true'],
)]
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
