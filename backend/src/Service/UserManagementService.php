<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AlertRule;
use App\Entity\ApiKey;
use App\Entity\Enum\UserRole;
use App\Entity\Export;
use App\Entity\Notification;
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

    /**
     * Audit post-C3 2026-10-02 (P-07) : supprimait auparavant l'utilisateur
     * seul (remove()+flush()), sans toucher aux 4 entités qui le
     * référencent par une relation ManyToOne obligatoire et sans
     * cascade/onDelete (AlertRule, ApiKey, Export, Notification) -
     * confirmé en direct pendant l'audit : supprimer un compte ayant au
     * moins une de ces ressources, même une clé API déjà révoquée (qui
     * reste physiquement en base), plantait en 500 non intercepté
     * (Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException).
     * Suppression explicite plutôt qu'une cascade Doctrine déclarative :
     * ces 4 ressources n'ont de sens qu'attachées à leur propriétaire,
     * jamais consultées indépendamment de lui, donc les supprimer avec
     * son compte est le comportement attendu (pas une conservation
     * orpheline ni un rejet de la suppression).
     */
    public function deleteUser(User $user): void
    {
        foreach ([AlertRule::class, ApiKey::class, Export::class, Notification::class] as $dependentEntityClass) {
            foreach ($this->entityManager->getRepository($dependentEntityClass)->findBy(['user' => $user]) as $dependent) {
                $this->entityManager->remove($dependent);
            }
        }

        $this->entityManager->remove($user);
        $this->entityManager->flush();
    }
}
