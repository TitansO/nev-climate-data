<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\ValidationStatus;
use App\Repository\EmissionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Table(name: 'emission')]
#[ORM\Index(columns: ['country_id'], name: 'idx_emission_country')]
#[ORM\Index(columns: ['year'], name: 'idx_emission_year')]
#[ORM\Index(columns: ['collection_date'], name: 'idx_emission_collection_date')]
// Partial unique index, not a flat UniqueConstraint - same reasoning as Funding.php (see the
// comment there, B1.1): historization keeps multiple rows sharing this same (source_id,
// country_id, year) tuple over time, one per historical version, with is_current=true on
// exactly one of them. Unlike Funding, a second message for the same key REPLACES the current
// row rather than summing into it (B1.4 spec decision 6) - a national annual CO2 figure is a
// single authoritative statistic the IEA periodically revises, not an additive transaction
// stream - but the storage mechanism (partial unique index + SCD2 valid_from/valid_to/is_current)
// is identical.
#[ORM\UniqueConstraint(
    name: 'uniq_emission_dedup_key_current',
    columns: ['source_id', 'country_id', 'year'],
    options: ['where' => 'is_current = true'],
)]
#[ORM\Entity(repositoryClass: EmissionRepository::class)]
class Emission
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Country::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Country $country;

    #[ORM\Column]
    private int $year;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 3)]
    private string $valueMt;

    #[ORM\ManyToOne(targetEntity: Source::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Source $source;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $collectionDate;

    #[ORM\Column(type: Types::STRING, enumType: ValidationStatus::class, length: 20)]
    private ValidationStatus $validationStatus;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $validFrom = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $validTo = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $isCurrent = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        Country $country,
        int $year,
        string $valueMt,
        Source $source,
        \DateTimeImmutable $collectionDate,
        ValidationStatus $validationStatus,
    ) {
        $this->country = $country;
        $this->year = $year;
        $this->valueMt = $valueMt;
        $this->source = $source;
        $this->collectionDate = $collectionDate;
        $this->validationStatus = $validationStatus;
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

    public function getYear(): int
    {
        return $this->year;
    }

    public function setYear(int $year): static
    {
        $this->year = $year;

        return $this;
    }

    public function getValueMt(): string
    {
        return $this->valueMt;
    }

    public function setValueMt(string $valueMt): static
    {
        $this->valueMt = $valueMt;

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

    public function getCollectionDate(): \DateTimeImmutable
    {
        return $this->collectionDate;
    }

    public function setCollectionDate(\DateTimeImmutable $collectionDate): static
    {
        $this->collectionDate = $collectionDate;

        return $this;
    }

    public function getValidationStatus(): ValidationStatus
    {
        return $this->validationStatus;
    }

    public function setValidationStatus(ValidationStatus $validationStatus): static
    {
        $this->validationStatus = $validationStatus;

        return $this;
    }

    public function getValidFrom(): ?\DateTimeImmutable
    {
        return $this->validFrom;
    }

    public function setValidFrom(?\DateTimeImmutable $validFrom): static
    {
        $this->validFrom = $validFrom;

        return $this;
    }

    public function getValidTo(): ?\DateTimeImmutable
    {
        return $this->validTo;
    }

    public function setValidTo(?\DateTimeImmutable $validTo): static
    {
        $this->validTo = $validTo;

        return $this;
    }

    public function isCurrent(): bool
    {
        return $this->isCurrent;
    }

    public function setIsCurrent(bool $isCurrent): static
    {
        $this->isCurrent = $isCurrent;

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
