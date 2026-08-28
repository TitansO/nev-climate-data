<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\FundingType;
use App\Entity\Enum\ValidationStatus;
use App\Repository\FundingRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Table(name: 'funding')]
#[ORM\Index(columns: ['country_id'], name: 'idx_funding_country')]
#[ORM\Index(columns: ['sector_id'], name: 'idx_funding_sector')]
#[ORM\Index(columns: ['year'], name: 'idx_funding_year')]
#[ORM\Index(columns: ['collection_date'], name: 'idx_funding_collection_date')]
// Partial unique index, not a flat UniqueConstraint: historization (already on this entity
// since A1.3 - see isCurrent/validFrom/validTo below) deliberately keeps multiple rows sharing
// this same 5-column tuple over time, one per historical version, with is_current=true on
// exactly one of them. A flat constraint across every row - current and historized alike -
// would reject the second version the moment it's inserted. Scoping the constraint to
// `WHERE is_current = true` is what actually enforces "at most one *current* row per dedup
// key" while still letting historized rows share the same business key indefinitely. Also
// what Task 7's `INSERT ... ON CONFLICT (...) WHERE is_current = true` targets (Postgres
// supports inferring a partial unique index this way).
// Known Doctrine/DBAL limitation, confirmed while building this (`doctrine:schema:update
// --dump-sql` on an already-correct DB): the Postgres schema comparator does not read back a
// partial index's WHERE clause, so it always proposes DROP + CREATE of this exact index and
// `doctrine:schema:validate` always reports it "not in sync" - even immediately after
// applying the migration that creates it correctly. Verified via `\d funding` that the actual
// index (`... WHERE is_current = true`) matches this mapping exactly. This is a permanent,
// expected false positive for this one index - do not "fix" it by re-running
// migrations:diff, it will only generate a no-op drop/recreate migration.
#[ORM\UniqueConstraint(
    name: 'uniq_funding_dedup_key_current',
    columns: ['source_id', 'country_id', 'sector_id', 'year', 'funding_type'],
    options: ['where' => 'is_current = true'],
)]
#[ORM\Entity(repositoryClass: FundingRepository::class)]
class Funding
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Country::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Country $country;

    #[ORM\ManyToOne(targetEntity: Sector::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Sector $sector;

    #[ORM\Column]
    private int $year;

    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 2)]
    private string $amount;

    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 2, nullable: true)]
    private ?string $originalAmount = null;

    #[ORM\Column(length: 3, nullable: true)]
    private ?string $originalCurrency = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 6, nullable: true)]
    private ?string $exchangeRate = null;

    #[ORM\Column(type: Types::STRING, enumType: FundingType::class, length: 20)]
    private FundingType $fundingType;

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
        Sector $sector,
        int $year,
        string $amount,
        FundingType $fundingType,
        Source $source,
        \DateTimeImmutable $collectionDate,
        ValidationStatus $validationStatus,
    ) {
        $this->country = $country;
        $this->sector = $sector;
        $this->year = $year;
        $this->amount = $amount;
        $this->fundingType = $fundingType;
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

    public function getSector(): Sector
    {
        return $this->sector;
    }

    public function setSector(Sector $sector): static
    {
        $this->sector = $sector;

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

    public function getAmount(): string
    {
        return $this->amount;
    }

    public function setAmount(string $amount): static
    {
        $this->amount = $amount;

        return $this;
    }

    public function getOriginalAmount(): ?string
    {
        return $this->originalAmount;
    }

    public function setOriginalAmount(?string $originalAmount): static
    {
        $this->originalAmount = $originalAmount;

        return $this;
    }

    public function getOriginalCurrency(): ?string
    {
        return $this->originalCurrency;
    }

    public function setOriginalCurrency(?string $originalCurrency): static
    {
        $this->originalCurrency = $originalCurrency;

        return $this;
    }

    public function getExchangeRate(): ?string
    {
        return $this->exchangeRate;
    }

    public function setExchangeRate(?string $exchangeRate): static
    {
        $this->exchangeRate = $exchangeRate;

        return $this;
    }

    public function getFundingType(): FundingType
    {
        return $this->fundingType;
    }

    public function setFundingType(FundingType $fundingType): static
    {
        $this->fundingType = $fundingType;

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
