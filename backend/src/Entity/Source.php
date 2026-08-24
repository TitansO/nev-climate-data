<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\SourceReliability;
use App\Entity\Enum\SourceType;
use App\Repository\SourceRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SourceRepository::class)]
class Source
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(type: Types::STRING, enumType: SourceType::class, length: 30)]
    private SourceType $type;

    #[ORM\Column(type: Types::STRING, enumType: SourceReliability::class, length: 10)]
    private SourceReliability $reliability;

    public function __construct(string $name, SourceType $type, SourceReliability $reliability)
    {
        $this->name = $name;
        $this->type = $type;
        $this->reliability = $reliability;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getType(): SourceType
    {
        return $this->type;
    }

    public function setType(SourceType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getReliability(): SourceReliability
    {
        return $this->reliability;
    }

    public function setReliability(SourceReliability $reliability): static
    {
        $this->reliability = $reliability;

        return $this;
    }
}
