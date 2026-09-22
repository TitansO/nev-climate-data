<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Enum\UserRole;
use App\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * C3.5 greenwashing check quotas by role (config/packages/rate_limiter.yaml,
 * "greenwashing_*") - même forme que App\Security\ClassifyQuotaPolicy, un
 * budget séparé pour la même raison qu'elle : vérifier en masse ne doit
 * épuiser ni le quota du Copilot IA, ni celui de la classification.
 */
final class GreenwashingQuotaPolicy
{
    public function __construct(
        #[Autowire(service: 'limiter.greenwashing_admin')]
        private readonly RateLimiterFactory $adminLimiter,
        #[Autowire(service: 'limiter.greenwashing_internal_analyst')]
        private readonly RateLimiterFactory $internalAnalystLimiter,
        #[Autowire(service: 'limiter.greenwashing_external_partner')]
        private readonly RateLimiterFactory $externalPartnerLimiter,
    ) {
    }

    /**
     * @throws TooManyRequestsHttpException if the user's daily greenwashing-check quota is exhausted
     */
    public function consume(User $user): void
    {
        $limiter = $this->limiterForRole($user->getRole())->create((string) $user->getId());
        $limit = $limiter->consume(1);

        if (!$limit->isAccepted()) {
            $retryAfter = max(1, $limit->getRetryAfter()->getTimestamp() - time());
            throw new TooManyRequestsHttpException($retryAfter, 'Greenwashing check quota exceeded for your role. Try again later.');
        }
    }

    private function limiterForRole(UserRole $role): RateLimiterFactory
    {
        return match ($role) {
            UserRole::SuperAdmin, UserRole::Admin => $this->adminLimiter,
            UserRole::InternalAnalyst => $this->internalAnalystLimiter,
            UserRole::ExternalPartner => $this->externalPartnerLimiter,
        };
    }
}
