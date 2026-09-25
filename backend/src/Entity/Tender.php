<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\TenderCategory;
use App\Repository\TenderRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A3.13 : un appel d'offres (marché de prestation) - distinct
 * d'`Opportunity` (F4, "appel à projets"). La différence n'est pas
 * cosmétique : dans un `Opportunity`, l'argent va DU bailleur VERS le
 * porteur de projet (qui candidate avec sa propre idée) ; dans un
 * `Tender`, l'argent va DE l'organisme émetteur VERS le prestataire
 * retenu (qui candidate avec une offre technique/financière pour livrer
 * une prestation définie par l'émetteur - étude, équipement, travaux,
 * services). Saisie manuelle comme `Opportunity`, même raisonnement
 * (voir `TenderFixtures`) - aucun connecteur automatique par émetteur.
 *
 * `issuingOrganization` est un champ texte libre plutôt qu'une référence
 * vers `Source` : les émetteurs d'appels d'offres réels dépassent
 * largement les quelques bailleurs suivis comme `Source` par ce projet
 * (ministères nationaux, ONG, bureaux d'études, entreprises publiques...) -
 * forcer une FK vers `Source` fausserait la donnée plutôt que de
 * simplement rester incomplète, même choix que `Opportunity::
 * eligibilityCriteria` pour la même raison.
 */
#[ORM\Table(name: 'tender')]
#[ORM\Index(columns: ['submission_deadline'], name: 'idx_tender_deadline')]
#[ORM\Index(columns: ['sector_id'], name: 'idx_tender_sector')]
#[ORM\Entity(repositoryClass: TenderRepository::class)]
class Tender
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(length: 255)]
    private string $issuingOrganization;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $referenceNumber = null;

    #[ORM\Column(type: Types::STRING, enumType: TenderCategory::class, length: 20)]
    private TenderCategory $category;

    #[ORM\ManyToOne(targetEntity: Sector::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Sector $sector = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $description;

    #[ORM\Column(type: Types::TEXT)]
    private string $eligibilityCriteria;

    // Nullable : de nombreux appels d'offres réels ne publient pas de
    // budget estimatif (mis sous pli/confidentiel jusqu'à l'ouverture des
    // offres), contrairement à Opportunity::envelopeAmount qui est
    // toujours connue (c'est le bailleur lui-même qui l'annonce).
    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 2, nullable: true)]
    private ?string $estimatedValue = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $publishedAt;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $submissionDeadline;

    #[ORM\Column(length: 2048, nullable: true)]
    private ?string $documentsUrl = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $contactEmail = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $title,
        string $issuingOrganization,
        TenderCategory $category,
        string $description,
        string $eligibilityCriteria,
        \DateTimeImmutable $publishedAt,
        \DateTimeImmutable $submissionDeadline,
    ) {
        $this->title = $title;
        $this->issuingOrganization = $issuingOrganization;
        $this->category = $category;
        $this->description = $description;
        $this->eligibilityCriteria = $eligibilityCriteria;
        $this->publishedAt = $publishedAt;
        $this->submissionDeadline = $submissionDeadline;
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

    public function getIssuingOrganization(): string
    {
        return $this->issuingOrganization;
    }

    public function setIssuingOrganization(string $issuingOrganization): static
    {
        $this->issuingOrganization = $issuingOrganization;

        return $this;
    }

    public function getReferenceNumber(): ?string
    {
        return $this->referenceNumber;
    }

    public function setReferenceNumber(?string $referenceNumber): static
    {
        $this->referenceNumber = $referenceNumber;

        return $this;
    }

    public function getCategory(): TenderCategory
    {
        return $this->category;
    }

    public function setCategory(TenderCategory $category): static
    {
        $this->category = $category;

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

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): static
    {
        $this->description = $description;

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

    public function getEstimatedValue(): ?string
    {
        return $this->estimatedValue;
    }

    public function setEstimatedValue(?string $estimatedValue): static
    {
        $this->estimatedValue = $estimatedValue;

        return $this;
    }

    public function getPublishedAt(): \DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function setPublishedAt(\DateTimeImmutable $publishedAt): static
    {
        $this->publishedAt = $publishedAt;

        return $this;
    }

    public function getSubmissionDeadline(): \DateTimeImmutable
    {
        return $this->submissionDeadline;
    }

    public function setSubmissionDeadline(\DateTimeImmutable $submissionDeadline): static
    {
        $this->submissionDeadline = $submissionDeadline;

        return $this;
    }

    public function getDocumentsUrl(): ?string
    {
        return $this->documentsUrl;
    }

    public function setDocumentsUrl(?string $documentsUrl): static
    {
        $this->documentsUrl = $documentsUrl;

        return $this;
    }

    public function getContactEmail(): ?string
    {
        return $this->contactEmail;
    }

    public function setContactEmail(?string $contactEmail): static
    {
        $this->contactEmail = $contactEmail;

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
