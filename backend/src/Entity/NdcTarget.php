<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\NdcTargetRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * F7 (fondation du sprint C1-C3) : la cible de réduction d'émissions et le
 * besoin de financement déclarés par un pays dans sa Contribution
 * Déterminée au niveau National (CDN/NDC). Sert C2.6 (alignement CDN),
 * C3.1 (détection d'écart) et C3.3 (score d'équité).
 *
 * `sector` nullable : la plupart des CDN fixent une cible économie
 * entière plutôt que sectorielle - `null` signifie "cible économie
 * entière", pas une donnée manquante.
 */
#[ORM\Table(name: 'ndc_target')]
#[ORM\Index(columns: ['country_id'], name: 'idx_ndc_target_country')]
#[ORM\Index(columns: ['sector_id'], name: 'idx_ndc_target_sector')]
#[ORM\Entity(repositoryClass: NdcTargetRepository::class)]
class NdcTarget
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Country::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Country $country;

    #[ORM\ManyToOne(targetEntity: Sector::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Sector $sector = null;

    // Pourcentage de réduction visé (vs scénario de référence / année de
    // base selon la CDN du pays - la méthode de calcul varie d'un pays à
    // l'autre et n'est pas normalisée ici). Nullable : certaines CDN
    // n'expriment pas leur cible en pourcentage.
    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $reductionTargetPercent = null;

    #[ORM\Column]
    private int $targetYear;

    // Montant total déclaré par le pays comme nécessaire pour mettre en
    // oeuvre sa CDN (conditionnelle ou non) - nullable quand ce montant
    // n'est pas chiffré publiquement dans la CDN elle-même.
    #[ORM\Column(type: Types::DECIMAL, precision: 18, scale: 2, nullable: true)]
    private ?string $declaredNeedUsd = null;

    // true : la cible/le besoin dépend d'un appui financier international
    // (le cas le plus fréquent, et le chiffre le plus souvent cité dans
    // la presse) ; false : engagement inconditionnel, financé sur
    // ressources propres.
    #[ORM\Column(options: ['default' => true])]
    private bool $conditional = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Country $country, int $targetYear)
    {
        $this->country = $country;
        $this->targetYear = $targetYear;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCountry(): Country
    {
        return $this->country;
    }

    public function setCountry(Country $country): static
    {
        $this->country = $country;

        return $this;
    }

    public function getSector(): ?Sector
    {
        return $this->sector;
    }

    public function setSector(?Sector $sector): static
    {
        $this->sector = $sector;

        return $this;
    }

    public function getReductionTargetPercent(): ?float
    {
        return $this->reductionTargetPercent;
    }

    public function setReductionTargetPercent(?float $reductionTargetPercent): static
    {
        $this->reductionTargetPercent = $reductionTargetPercent;

        return $this;
    }

    public function getTargetYear(): int
    {
        return $this->targetYear;
    }

    public function setTargetYear(int $targetYear): static
    {
        $this->targetYear = $targetYear;

        return $this;
    }

    public function getDeclaredNeedUsd(): ?string
    {
        return $this->declaredNeedUsd;
    }

    public function setDeclaredNeedUsd(?string $declaredNeedUsd): static
    {
        $this->declaredNeedUsd = $declaredNeedUsd;

        return $this;
    }

    public function isConditional(): bool
    {
        return $this->conditional;
    }

    public function setConditional(bool $conditional): static
    {
        $this->conditional = $conditional;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
