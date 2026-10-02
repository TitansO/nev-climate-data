<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Audit post-C3 2026-10-02 (P-01) : identite synthetique portee par
 * InternalServiceAuthenticator - jamais persistee, jamais liee a un compte
 * reel. Porte ROLE_USER uniquement : suffisant pour les endpoints
 * ^/api/reports et ^/api/analytics (ROLE_USER dans security.yaml) que
 * ai/ingest.py doit lire en service-a-service, jamais plus.
 */
final class InternalServiceUser implements UserInterface
{
    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function eraseCredentials(): void
    {
    }

    public function getUserIdentifier(): string
    {
        return 'internal-service';
    }
}
