<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\ApiKeyStatus;
use App\Repository\ApiKeyRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ApiKeyRepository::class)]
class ApiKey
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $user;

    #[ORM\Column(length: 255)]
    private string $keyHash;

    #[ORM\Column(type: Types::STRING, enumType: ApiKeyStatus::class, length: 20, options: ['default' => 'active'])]
    private ApiKeyStatus $status;

    #[ORM\Column]
    private int $quota;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    public function __construct(User $user, string $keyHash, int $quota)
    {
        $this->user = $user;
        $this->keyHash = $keyHash;
        $this->quota = $quota;
        $this->status = ApiKeyStatus::Active;
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

    public function getKeyHash(): string
    {
        return $this->keyHash;
    }

    public function getStatus(): ApiKeyStatus
    {
        return $this->status;
    }

    public function getQuota(): int
    {
        return $this->quota;
    }

    public function setQuota(int $quota): static
    {
        $this->quota = $quota;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function revoke(): static
    {
        $this->status = ApiKeyStatus::Revoked;
        $this->revokedAt = new \DateTimeImmutable();

        return $this;
    }
}
