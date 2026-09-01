<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Enum\UserRole;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Central authority for account lifecycle: creation, role changes and
 * deletion, all reserved to ROLE_SUPER_ADMIN (see
 * App\Controller\UserController, which stays thin and only translates
 * between HTTP and this service - mirroring App\Service\ApiKeyService).
 */
final class UserManagementService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    public function createUser(string $name, string $email, string $plainPassword, UserRole $role): User
    {
        // The real hash is computed against $user itself right after
        // construction (hashPassword() only reads the user's identity/salt
        // material, not the placeholder), so no plaintext password is ever
        // persisted even transiently.
        $user = new User($name, $email, 'placeholder', $role);
        $user->setPasswordHash($this->passwordHasher->hashPassword($user, $plainPassword));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    public function updateRole(User $user, UserRole $role): void
    {
        $user->setRole($role);
        $this->entityManager->flush();
    }

    public function deleteUser(User $user): void
    {
        $this->entityManager->remove($user);
        $this->entityManager->flush();
    }
}
