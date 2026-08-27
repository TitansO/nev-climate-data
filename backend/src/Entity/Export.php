<?php

declare(strict_types=1);

namespace App\Entity;

use App\Dto\FundingExportFormat;
use App\Entity\Enum\ExportStatus;
use App\Repository\ExportRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A2.3 (async export beyond a row-count threshold). Tracks one export
 * request end to end: the filters snapshot it was generated from, its
 * format, and its lifecycle (Pending -> Processing -> Ready|Failed).
 * A synchronous export (below the threshold - see
 * App\Service\ExportService::ASYNC_THRESHOLD) never creates a row here at
 * all; it streams the file directly in the same request, exactly as
 * before this task. Only the async path persists anything.
 */
#[ORM\Entity(repositoryClass: ExportRepository::class)]
class Export
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $user;

    #[ORM\Column(type: Types::STRING, enumType: FundingExportFormat::class, length: 10)]
    private FundingExportFormat $format;

    #[ORM\Column(type: Types::STRING, enumType: ExportStatus::class, length: 20, options: ['default' => 'pending'])]
    private ExportStatus $status;

    /**
     * The exact query string the export was requested with (e.g.
     * "country=SEN&year=2025") - re-parsed into FundingSearchCriteria by
     * the async handler, the same class GET /api/funding/export itself
     * uses, so the async path can never apply a different filter than what
     * the user actually asked for.
     */
    #[ORM\Column(type: Types::TEXT)]
    private string $filterQueryString;

    #[ORM\Column(nullable: true)]
    private ?int $rowCount = null;

    /**
     * Path on disk, relative to the export storage directory - never
     * exposed directly to the client (see FundingController::downloadExport(),
     * which streams it after an ownership check, rather than returning a
     * raw path or URL the client could otherwise guess).
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $filePath = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    public function __construct(User $user, FundingExportFormat $format, string $filterQueryString)
    {
        $this->user = $user;
        $this->format = $format;
        $this->status = ExportStatus::Pending;
        $this->filterQueryString = $filterQueryString;
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

    public function getFormat(): FundingExportFormat
    {
        return $this->format;
    }

    public function getStatus(): ExportStatus
    {
        return $this->status;
    }

    public function getFilterQueryString(): string
    {
        return $this->filterQueryString;
    }

    public function getRowCount(): ?int
    {
        return $this->rowCount;
    }

    public function getFilePath(): ?string
    {
        return $this->filePath;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function markProcessing(): static
    {
        $this->status = ExportStatus::Processing;

        return $this;
    }

    public function markReady(string $filePath, int $rowCount): static
    {
        $this->status = ExportStatus::Ready;
        $this->filePath = $filePath;
        $this->rowCount = $rowCount;
        $this->completedAt = new \DateTimeImmutable();

        return $this;
    }

    public function markFailed(string $errorMessage): static
    {
        $this->status = ExportStatus::Failed;
        $this->errorMessage = $errorMessage;
        $this->completedAt = new \DateTimeImmutable();

        return $this;
    }
}
