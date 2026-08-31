<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\FundingProjectContributionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Tracks the last-known contribution of a single source project to the
 * `funding` table's aggregated totals - one row per (source, project,
 * country). Exists to fix a real production bug: every collection DAG
 * (World Bank, GCF, AfDB) re-publishes its entire current portfolio on
 * every run, not just new/changed projects, and funding_validator.py used
 * to blindly sum every message's amount into the current total - so a
 * project already counted in a previous run got counted again on every
 * subsequent run (verified live: Senegal/Agriculture/1989 summed 8 times
 * in a row with the exact same increment). See
 * docs/superpowers/specs/2026-08-31-funding-project-idempotency-design.md.
 * No Symfony-side consumer reads this table - same rationale as
 * ProcessedDocument (B1.5): it exists so the pipeline's schema evolves
 * through the same Doctrine migration mechanism as every other table.
 */
#[ORM\Table(name: 'funding_project_contribution')]
#[ORM\UniqueConstraint(
    name: 'uniq_funding_project_contribution_key',
    columns: ['source_id', 'project_id', 'country_id'],
)]
#[ORM\Entity(repositoryClass: FundingProjectContributionRepository::class)]
class FundingProjectContribution
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Source::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Source $source;

    #[ORM\Column(length: 255)]
    private string $projectId;

    #[ORM\ManyToOne(targetEntity: Country::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Country $country;

    #[ORM\ManyToOne(targetEntity: Sector::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Sector $sector;

    #[ORM\Column]
    private int $year;

    #[ORM\Column(length: 20)]
    private string $fundingType;

    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 2)]
    private string $amount;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        Source $source,
        string $projectId,
        Country $country,
        Sector $sector,
        int $year,
        string $fundingType,
        string $amount,
    ) {
        $this->source = $source;
        $this->projectId = $projectId;
        $this->country = $country;
        $this->sector = $sector;
        $this->year = $year;
        $this->fundingType = $fundingType;
        $this->amount = $amount;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSource(): Source
    {
        return $this->source;
    }

    public function getProjectId(): string
    {
        return $this->projectId;
    }

    public function getCountry(): Country
    {
        return $this->country;
    }

    public function getSector(): Sector
    {
        return $this->sector;
    }

    public function getYear(): int
    {
        return $this->year;
    }

    public function getFundingType(): string
    {
        return $this->fundingType;
    }

    public function getAmount(): string
    {
        return $this->amount;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
