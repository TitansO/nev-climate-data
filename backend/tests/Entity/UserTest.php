<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Enum\UserRole;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class UserTest extends TestCase
{
    public function testConstructorSetsFields(): void
    {
        $user = new User('Amina Diallo', 'amina@example.com', 'hashed-password', UserRole::InternalAnalyst);

        self::assertNull($user->getId());
        self::assertSame('Amina Diallo', $user->getName());
        self::assertSame('amina@example.com', $user->getEmail());
        self::assertSame('hashed-password', $user->getPasswordHash());
        self::assertSame(UserRole::InternalAnalyst, $user->getRole());
        self::assertInstanceOf(\DateTimeImmutable::class, $user->getCreatedAt());
    }

    public function testSettersUpdateFields(): void
    {
        $user = new User('Amina Diallo', 'amina@example.com', 'hashed-password', UserRole::InternalAnalyst);

        $user->setName('Amina D.');
        $user->setEmail('amina.diallo@example.com');
        $user->setPasswordHash('new-hash');
        $user->setRole(UserRole::Admin);

        self::assertSame('Amina D.', $user->getName());
        self::assertSame('amina.diallo@example.com', $user->getEmail());
        self::assertSame('new-hash', $user->getPasswordHash());
        self::assertSame(UserRole::Admin, $user->getRole());
    }

    public function testGetRolesMapsSuperAdminRole(): void
    {
        $user = new User('Amina Diallo', 'amina@example.com', 'hashed-password', UserRole::SuperAdmin);

        self::assertSame(['ROLE_SUPER_ADMIN', 'ROLE_USER'], $user->getRoles());
    }

    public function testGetRolesMapsAdminRole(): void
    {
        $user = new User('Amina Diallo', 'amina@example.com', 'hashed-password', UserRole::Admin);

        self::assertSame(['ROLE_ADMIN', 'ROLE_USER'], $user->getRoles());
    }

    public function testGetRolesMapsInternalAnalystRole(): void
    {
        $user = new User('Amina Diallo', 'amina@example.com', 'hashed-password', UserRole::InternalAnalyst);

        self::assertSame(['ROLE_INTERNAL_ANALYST', 'ROLE_USER'], $user->getRoles());
    }

    public function testGetRolesMapsExternalPartnerRole(): void
    {
        $user = new User('Amina Diallo', 'amina@example.com', 'hashed-password', UserRole::ExternalPartner);

        self::assertSame(['ROLE_EXTERNAL_PARTNER', 'ROLE_USER'], $user->getRoles());
    }

    public function testGetPasswordReturnsPasswordHash(): void
    {
        $user = new User('Amina Diallo', 'amina@example.com', 'hashed-password', UserRole::Admin);

        self::assertSame('hashed-password', $user->getPassword());
    }

    public function testGetUserIdentifierReturnsEmail(): void
    {
        $user = new User('Amina Diallo', 'amina@example.com', 'hashed-password', UserRole::Admin);

        self::assertSame('amina@example.com', $user->getUserIdentifier());
    }

    public function testEraseCredentialsIsANoOp(): void
    {
        $user = new User('Amina Diallo', 'amina@example.com', 'hashed-password', UserRole::Admin);

        $user->eraseCredentials();

        self::assertSame('hashed-password', $user->getPassword());
    }
}
