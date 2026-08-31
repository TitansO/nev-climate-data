<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ProcessedDocumentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The B1.5 PDF-extraction cache: one row per document hash already
 * processed, so a re-run never re-extracts (and never re-publishes) the
 * same PDF twice - see the B1.5 spec's roadmap requirement ("cache (hash
 * du PDF déjà traité)"). No Symfony-side consumer reads this table today;
 * it exists purely so the pipeline's schema evolves through the same
 * Doctrine migration mechanism as every other table in this project.
 */
#[ORM\Table(name: 'processed_document')]
#[ORM\UniqueConstraint(name: 'uniq_processed_document_hash', columns: ['hash'])]
#[ORM\Entity(repositoryClass: ProcessedDocumentRepository::class)]
class ProcessedDocument
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    private string $hash;

    #[ORM\Column(length: 255)]
    private string $sourceName;

    #[ORM\Column(length: 500)]
    private string $sourceUrl;

    #[ORM\Column(length: 500)]
    private string $minioPath;

    #[ORM\Column]
    private int $rowsExtracted;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $processedAt;

    public function __construct(
        string $hash,
        string $sourceName,
        string $sourceUrl,
        string $minioPath,
        int $rowsExtracted,
    ) {
        $this->hash = $hash;
        $this->sourceName = $sourceName;
        $this->sourceUrl = $sourceUrl;
        $this->minioPath = $minioPath;
        $this->rowsExtracted = $rowsExtracted;
        $this->processedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getHash(): string
    {
        return $this->hash;
    }

    public function getSourceName(): string
    {
        return $this->sourceName;
    }

    public function getSourceUrl(): string
    {
        return $this->sourceUrl;
    }

    public function getMinioPath(): string
    {
        return $this->minioPath;
    }

    public function getRowsExtracted(): int
    {
        return $this->rowsExtracted;
    }

    public function getProcessedAt(): \DateTimeImmutable
    {
        return $this->processedAt;
    }
}
