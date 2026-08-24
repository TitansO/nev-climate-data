<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ApiKey;
use App\Entity\Enum\ApiKeyStatus;
use App\Entity\User;
use App\Repository\ApiKeyRepository;
use App\Security\ApiKeyQuotaPolicy;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Central authority for API key lifecycle: generation, hashing, validation
 * and revocation (cahier des charges 5.2.b). Controllers stay thin and only
 * translate between HTTP and this service.
 */
final class ApiKeyService
{
    /**
     * "nev_" makes a key visually identifiable (e.g. in logs, in a secret
     * scanner) as belonging to this project, a common convention for API
     * tokens (Stripe's "sk_", GitHub's "ghp_", ...).
     */
    private const KEY_PREFIX = 'nev_';

    /**
     * 32 bytes = 256 bits of entropy from a CSPRNG — far beyond what's
     * brute-forceable, and generous headroom versus the ~128 bits generally
     * considered sufficient for bearer tokens.
     */
    private const KEY_ENTROPY_BYTES = 32;

    public function __construct(
        private readonly ApiKeyRepository $apiKeyRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly ApiKeyQuotaPolicy $quotaPolicy,
    ) {
    }

    public function generateKey(User $user): GeneratedApiKey
    {
        $plainKey = self::KEY_PREFIX.bin2hex(random_bytes(self::KEY_ENTROPY_BYTES));
        $quota = $this->quotaPolicy->quotaForRole($user->getRole());

        $apiKey = new ApiKey($user, $this->hashKey($plainKey), $quota);

        $this->entityManager->persist($apiKey);
        $this->entityManager->flush();

        return new GeneratedApiKey($apiKey, $plainKey);
    }

    /**
     * SHA-256, not a slow password hash (bcrypt/argon2): the input here is
     * already a 256-bit CSPRNG-generated secret, not a low-entropy
     * human-chosen password, so there's nothing for a slow, salted hash to
     * protect against that a fast, deterministic digest doesn't already
     * cover — and a deterministic digest is what allows looking a key up by
     * an indexed equality match instead of hashing and comparing against
     * every stored key. This mirrors common practice for high-entropy API
     * tokens (e.g. GitHub, Stripe).
     */
    public function hashKey(string $plainKey): string
    {
        return hash('sha256', $plainKey);
    }

    /**
     * Looks up the key by its hash and returns it only if active. The
     * lookup itself is an indexed equality match in the database, so there
     * is no application-level string comparison of secrets to guard with
     * hash_equals() — the match either exists in the index or it doesn't,
     * which does not leak timing information about a candidate key.
     */
    public function validateKey(string $plainKey): ?ApiKey
    {
        $apiKey = $this->apiKeyRepository->findOneByHash($this->hashKey($plainKey));

        if (null === $apiKey || ApiKeyStatus::Active !== $apiKey->getStatus()) {
            return null;
        }

        return $apiKey;
    }

    public function revokeKey(ApiKey $apiKey): void
    {
        $apiKey->revoke();
        $this->entityManager->flush();
    }
}
