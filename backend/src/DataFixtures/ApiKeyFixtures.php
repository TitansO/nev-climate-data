<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\ApiKey;
use App\Entity\Enum\UserRole;
use App\Entity\User;
use App\Security\ApiKeyQuotaPolicy;
use App\Service\ApiKeyService;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * Minimal API key fixtures — just enough to exercise the ApiKey -> User
 * relation and both ApiKeyStatus values, not a realistic key inventory
 * (cahier des charges A1.6: "données minimales uniquement pour tester les
 * relations" when no working credential is actually needed).
 *
 * Hashing reuses ApiKeyService::hashKey() (injected, not duplicated) so a
 * fixture-created row is hashed by the exact same algorithm as a
 * real POST /api/api-keys call. Quota reuses ApiKeyQuotaPolicy, the same
 * role -> quota mapping A1.5 applies at runtime.
 *
 * The plaintext values below are fixed, non-secret placeholders that exist
 * only to be hashed — never real credentials, and never printed in the
 * README (or anywhere else) as something a developer could actually use.
 */
final class ApiKeyFixtures extends Fixture implements DependentFixtureInterface
{
    public function __construct(
        private readonly ApiKeyService $apiKeyService,
        private readonly ApiKeyQuotaPolicy $quotaPolicy,
    ) {
    }

    public function getDependencies(): array
    {
        return [UserFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        /** @var User $admin */
        $admin = $this->getReference(UserFixtures::userReference(UserRole::Admin), User::class);
        /** @var User $partner */
        $partner = $this->getReference(UserFixtures::userReference(UserRole::ExternalPartner), User::class);

        $activeKey = new ApiKey(
            $admin,
            $this->apiKeyService->hashKey('nev_fixture_placeholder_admin_key_never_real'),
            $this->quotaPolicy->quotaForRole($admin->getRole()),
        );
        $manager->persist($activeKey);

        $revokedKey = new ApiKey(
            $partner,
            $this->apiKeyService->hashKey('nev_fixture_placeholder_partner_key_never_real'),
            $this->quotaPolicy->quotaForRole($partner->getRole()),
        );
        $revokedKey->revoke();
        $manager->persist($revokedKey);

        $manager->flush();
    }
}
