<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ImpactFactorRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * C2.5 : facteur d'impact par secteur - combien de tCO2 évitées/an, de MW
 * installés, ou de bénéficiaires un montant investi produit dans ce
 * secteur (App\Service\ImpactCalculatorService). Chaque montant est
 * nullable : un secteur n'a pas forcément les 3 métriques (l'adaptation
 * n'évite pas d'émissions par définition, par exemple) - `null` signifie
 * "cette métrique ne s'applique pas à ce secteur", jamais une donnée
 * manquante à combler.
 *
 * Une seule ligne par secteur (contrainte d'unicité) - un facteur est
 * rafraîchi en place, jamais historisé (même raisonnement que `Score`,
 * F2) : c'est une donnée de référence méthodologique, pas un flux
 * sourcé à conserver version par version.
 */
#[ORM\Table(name: 'impact_factor')]
#[ORM\UniqueConstraint(name: 'uniq_impact_factor_sector', columns: ['sector_id'])]
#[ORM\Entity(repositoryClass: ImpactFactorRepository::class)]
class ImpactFactor
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: Sector::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Sector $sector;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, nullable: true)]
    private ?string $usdPerTco2Avoided = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 2, nullable: true)]
    private ?string $usdPerMwInstalled = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, nullable: true)]
    private ?string $usdPerBeneficiary = null;

    // ± ce pourcentage autour de l'estimation centrale - la fourchette
    // d'incertitude que le plan demande explicitement, jamais un chiffre
    // ponctuel présenté comme certain.
    #[ORM\Column(type: Types::FLOAT)]
    private float $uncertaintyPercent;

    // Référence méthodologique publique (ex : "IRENA Renewable Power
    // Generation Costs", "GCF Results Handbook") - texte libre, jamais un
    // lien direct (les rapports changent d'URL) ; voir
    // App\DataFixtures\ImpactFactorFixtures pour la réserve sur
    // l'exactitude de ces chiffres.
    #[ORM\Column(length: 255)]
    private string $methodologySource;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Sector $sector, float $uncertaintyPercent, string $methodologySource)
    {
        $this->sector = $sector;
        $this->uncertaintyPercent = $uncertaintyPercent;
        $this->methodologySource = $methodologySource;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSector(): Sector
    {
        return $this->sector;
    }

    public function getUsdPerTco2Avoided(): ?string
    {
        return $this->usdPerTco2Avoided;
    }

    public function setUsdPerTco2Avoided(?string $usdPerTco2Avoided): static
    {
        $this->usdPerTco2Avoided = $usdPerTco2Avoided;

        return $this;
    }

    public function getUsdPerMwInstalled(): ?string
    {
        return $this->usdPerMwInstalled;
    }

    public function setUsdPerMwInstalled(?string $usdPerMwInstalled): static
    {
        $this->usdPerMwInstalled = $usdPerMwInstalled;

        return $this;
    }

    public function getUsdPerBeneficiary(): ?string
    {
        return $this->usdPerBeneficiary;
    }

    public function setUsdPerBeneficiary(?string $usdPerBeneficiary): static
    {
        $this->usdPerBeneficiary = $usdPerBeneficiary;

        return $this;
    }

    public function getUncertaintyPercent(): float
    {
        return $this->uncertaintyPercent;
    }

    public function getMethodologySource(): string
    {
        return $this->methodologySource;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
