<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\UserRole;
use App\Repository\UserRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Table(name: 'users')]
#[ORM\Entity(repositoryClass: UserRepository::class)]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 255, unique: true)]
    private string $email;

    #[ORM\Column(length: 255)]
    private string $passwordHash;

    #[ORM\Column(type: Types::STRING, enumType: UserRole::class, length: 20)]
    private UserRole $role;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $name, string $email, string $passwordHash, UserRole $role)
    {
        $this->name = $name;
        $this->email = $email;
        $this->passwordHash = $passwordHash;
        $this->role = $role;
        $this->createdAt = new \DateTimeImmutable();
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

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getPasswordHash(): string
    {
        return $this->passwordHash;
    }

    public function setPasswordHash(string $passwordHash): static
    {
        $this->passwordHash = $passwordHash;

        return $this;
    }

    public function getRole(): UserRole
    {
        return $this->role;
    }

    public function setRole(UserRole $role): static
    {
        $this->role = $role;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        $roleName = match ($this->role) {
            UserRole::SuperAdmin => 'ROLE_SUPER_ADMIN',
            UserRole::Admin => 'ROLE_ADMIN',
            UserRole::InternalAnalyst => 'ROLE_INTERNAL_ANALYST',
            UserRole::ExternalPartner => 'ROLE_EXTERNAL_PARTNER',
        };

        return [$roleName, 'ROLE_USER'];
    }

    public function getPassword(): string
    {
        return $this->passwordHash;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    public function eraseCredentials(): void
    {
        // No plaintext credential is ever held on this entity — nothing to erase.
    }
}
