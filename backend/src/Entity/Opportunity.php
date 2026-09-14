<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\OpportunityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * F4 (fondation du sprint C1-C3) : un appel à projets / une fenêtre de
 * financement ouverte par un bailleur (`Source`). Sert C1.8 (Opportunity
 * Radar) et C2.2 (Matching Engine). Saisie manuelle pour ce sprint - pas
 * de connecteur automatique par bailleur (reporté post-sprint, voir le
 * document de référence). Jeu de démonstration explicitement étiqueté
 * (voir `OpportunityFixtures`).
 */
#[ORM\Table(name: 'opportunity')]
#[ORM\Index(columns: ['application_window_closes_at'], name: 'idx_opportunity_closes_at')]
#[ORM\Index(columns: ['source_id'], name: 'idx_opportunity_source')]
#[ORM\Index(columns: ['sector_id'], name: 'idx_opportunity_sector')]
#[ORM\Entity(repositoryClass: OpportunityRepository::class)]
class Opportunity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\ManyToOne(targetEntity: Source::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Source $source;

    // Nullable : certains appels à projets sont transversaux, ouverts à
    // plusieurs secteurs à la fois - `eligibilityCriteria` (texte libre)
    // porte alors le détail complet plutôt que de forcer un choix unique.
    #[ORM\ManyToOne(targetEntity: Sector::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Sector $sector = null;

    // Texte libre : pays éligibles, instruments proposés, plafond par
    // projet... Pas de matrice d'éligibilité structurée dans ce sprint
    // (C2.2 s'appuie dessus tel quel pour son scoring de correspondance,
    // sans la parser).
    #[ORM\Column(type: Types::TEXT)]
    private string $eligibilityCriteria;

    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 2)]
    private string $envelopeAmount;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $applicationWindowOpensAt = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $applicationWindowClosesAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $title,
        Source $source,
        string $eligibilityCriteria,
        string $envelopeAmount,
    ) {
        $this->title = $title;
        $this->source = $source;
        $this->eligibilityCriteria = $eligibilityCriteria;
        $this->envelopeAmount = $envelopeAmount;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getSource(): Source
    {
        return $this->source;
    }

    public function setSource(Source $source): static
    {
        $this->source = $source;

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

    public function getEligibilityCriteria(): string
    {
        return $this->eligibilityCriteria;
    }

    public function setEligibilityCriteria(string $eligibilityCriteria): static
    {
        $this->eligibilityCriteria = $eligibilityCriteria;

        return $this;
    }

    public function getEnvelopeAmount(): string
    {
        return $this->envelopeAmount;
    }

    public function setEnvelopeAmount(string $envelopeAmount): static
    {
        $this->envelopeAmount = $envelopeAmount;

        return $this;
    }

    public function getApplicationWindowOpensAt(): ?\DateTimeImmutable
    {
        return $this->applicationWindowOpensAt;
    }

    public function setApplicationWindowOpensAt(?\DateTimeImmutable $applicationWindowOpensAt): static
    {
        $this->applicationWindowOpensAt = $applicationWindowOpensAt;

        return $this;
    }

    public function getApplicationWindowClosesAt(): ?\DateTimeImmutable
    {
        return $this->applicationWindowClosesAt;
    }

    public function setApplicationWindowClosesAt(?\DateTimeImmutable $applicationWindowClosesAt): static
    {
        $this->applicationWindowClosesAt = $applicationWindowClosesAt;

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

    public function touch(): static
    {
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }
}
