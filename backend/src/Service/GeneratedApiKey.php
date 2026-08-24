<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ApiKey;

/**
 * Carries the one-time plaintext key alongside its persisted entity. Exists
 * only in memory for the duration of the creation request — the plaintext
 * is never persisted (see ApiKeyService::hashKey()) and this object is
 * discarded once the HTTP response has been built.
 */
final readonly class GeneratedApiKey
{
    public function __construct(
        public ApiKey $apiKey,
        public string $plainKey,
    ) {
    }
}
