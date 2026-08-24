<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\ApiKey;
use App\Entity\Enum\ApiKeyStatus;
use App\Entity\Enum\UserRole;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class ApiKeyTest extends TestCase
{
    public function testConstructorSetsDefaults(): void
    {
        $user = new User('Amina Diallo', 'amina@example.com', 'hashed-password', UserRole::ExternalPartner);
        $apiKey = new ApiKey($user, 'hashed-key-value', 1000);

        self::assertNull($apiKey->getId());
        self::assertSame($user, $apiKey->getUser());
        self::assertSame('hashed-key-value', $apiKey->getKeyHash());
        self::assertSame(1000, $apiKey->getQuota());
        self::assertSame(ApiKeyStatus::Active, $apiKey->getStatus());
        self::assertNull($apiKey->getRevokedAt());
    }

    public function testRevokeSetsStatusAndTimestamp(): void
    {
        $user = new User('Amina Diallo', 'amina@example.com', 'hashed-password', UserRole::ExternalPartner);
        $apiKey = new ApiKey($user, 'hashed-key-value', 1000);

        $apiKey->revoke();

        self::assertSame(ApiKeyStatus::Revoked, $apiKey->getStatus());
        self::assertInstanceOf(\DateTimeImmutable::class, $apiKey->getRevokedAt());
    }
}
