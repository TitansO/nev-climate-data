<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\FundingInstrument;
use App\Entity\Enum\ProjectDecisionOutcome;
use App\Entity\Enum\ProjectExecutionStatus;
use App\Entity\Enum\ProjectPipelineStage;
use App\Repository\ProjectRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * F4 (fondation du sprint C1-C3) : un projet climat à la recherche de
 * financement - pas un `Funding` (qui est un flux déjà décaissé et
 * réel). Sert C1.8 (Opportunity Radar), C2.2 (Matching Engine), C2.3
 * (Project Pipeline / Kanban), C2.4 (Bankability Score), C2.5 (Impact
 * Calculator). Jeu de démonstration explicitement étiqueté (voir
 * `ProjectFixtures`) - aucune source réelle de projets n'existe encore.
 */
#[ORM\Table(name: 'project')]
#[ORM\Index(columns: ['pipeline_stage'], name: 'idx_project_pipeline_stage')]
#[ORM\Index(columns: ['country_id'], name: 'idx_project_country')]
#[ORM\Index(columns: ['sector_id'], name: 'idx_project_sector')]
#[ORM\Entity(repositoryClass: ProjectRepository::class)]
class Project
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\ManyToOne(targetEntity: Country::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Country $country;

    #[ORM\ManyToOne(targetEntity: Sector::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Sector $sector;

    // Nullable : un projet en stade "idea" n'a souvent pas encore arrêté
    // l'instrument recherché (voir FundingInstrument, F1).
    #[ORM\Column(type: Types::STRING, enumType: FundingInstrument::class, length: 25, nullable: true)]
    private ?FundingInstrument $instrumentSought = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 2)]
    private string $amountSought;

    #[ORM\Column(type: Types::STRING, enumType: ProjectPipelineStage::class, length: 20, options: ['default' => 'idea'])]
    private ProjectPipelineStage $pipelineStage = ProjectPipelineStage::Idea;

    // Étiquette affichée sur la carte quand pipelineStage vaut Decision (ou
    // Execution, où elle reste "financed" par construction) - null tant que
    // le stade Decision n'a pas encore reçu d'issue (voir 2026-09-28,
    // fusion des anciens stades Financed/Rejected).
    #[ORM\Column(type: Types::STRING, enumType: ProjectDecisionOutcome::class, length: 20, nullable: true)]
    private ?ProjectDecisionOutcome $decisionOutcome = null;

    // Étiquette affichée sur la carte quand pipelineStage vaut Execution -
    // null tant que le projet n'a pas atteint ce stade (voir 2026-09-28).
    #[ORM\Column(type: Types::STRING, enumType: ProjectExecutionStatus::class, length: 20, nullable: true)]
    private ?ProjectExecutionStatus $executionStatus = null;

    // C2.7 : classe de taxonomie verte simplifiée ('mitigation'/
    // 'adaptation'/'both'/'not_relevant', voir ai/gemini_client.py::
    // TAXONOMY_CLASSES - jamais un enum PHP dupliqué ici, le service `ai/`
    // reste la seule source de vérité de cette énumération). `null` tant
    // que POST /api/projects/{id}/classify n'a jamais été appelé pour ce
    // projet - jamais une classe fabriquée par défaut.
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $taxonomyClass = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $description;

    // "Porteur" : nom du promoteur (personne, entreprise ou institution),
    // pas une relation vers `User` - un porteur de projet n'est pas
    // nécessairement un utilisateur de la plateforme.
    #[ORM\Column(length: 255)]
    private string $promoter;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $title,
        Country $country,
        Sector $sector,
        string $amountSought,
        string $description,
        string $promoter,
    ) {
        $this->title = $title;
        $this->country = $country;
        $this->sector = $sector;
        $this->amountSought = $amountSought;
        $this->description = $description;
        $this->promoter = $promoter;
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

    public function getCountry(): Country
    {
        return $this->country;
    }

    public function setCountry(Country $country): static
    {
        $this->country = $country;

        return $this;
    }

    public function getSector(): Sector
    {
        return $this->sector;
    }

    public function setSector(Sector $sector): static
    {
        $this->sector = $sector;

        return $this;
    }

    public function getInstrumentSought(): ?FundingInstrument
    {
        return $this->instrumentSought;
    }

    public function setInstrumentSought(?FundingInstrument $instrumentSought): static
    {
        $this->instrumentSought = $instrumentSought;

        return $this;
    }

    public function getAmountSought(): string
    {
        return $this->amountSought;
    }

    public function setAmountSought(string $amountSought): static
    {
        $this->amountSought = $amountSought;

        return $this;
    }

    public function getPipelineStage(): ProjectPipelineStage
    {
        return $this->pipelineStage;
    }

    public function setPipelineStage(ProjectPipelineStage $pipelineStage): static
    {
        $this->pipelineStage = $pipelineStage;
        $this->touch();

        return $this;
    }

    public function getDecisionOutcome(): ?ProjectDecisionOutcome
    {
        return $this->decisionOutcome;
    }

    public function setDecisionOutcome(?ProjectDecisionOutcome $decisionOutcome): static
    {
        $this->decisionOutcome = $decisionOutcome;
        $this->touch();

        return $this;
    }

    public function getExecutionStatus(): ?ProjectExecutionStatus
    {
        return $this->executionStatus;
    }

    public function setExecutionStatus(?ProjectExecutionStatus $executionStatus): static
    {
        $this->executionStatus = $executionStatus;
        $this->touch();

        return $this;
    }

    public function getTaxonomyClass(): ?string
    {
        return $this->taxonomyClass;
    }

    public function setTaxonomyClass(?string $taxonomyClass): static
    {
        $this->taxonomyClass = $taxonomyClass;

        return $this;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getPromoter(): string
    {
        return $this->promoter;
    }

    public function setPromoter(string $promoter): static
    {
        $this->promoter = $promoter;

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
