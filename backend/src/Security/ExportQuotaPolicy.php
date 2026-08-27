<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Enum\UserRole;
use App\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * A2.3 export quotas by role ("règle 5.2.d") - one sliding-window limiter
 * per role (config/packages/rate_limiter.yaml), keyed by user id so quotas
 * are per-person, not global. Counts export *requests*, not rows exported:
 * simpler, and consistent with how ApiKeyQuotaPolicy (A1.5) counts API
 * calls rather than data volume.
 */
final class ExportQuotaPolicy
{
    public function __construct(
        #[Autowire(service: 'limiter.export_admin')]
        private readonly RateLimiterFactory $adminLimiter,
        #[Autowire(service: 'limiter.export_internal_analyst')]
        private readonly RateLimiterFactory $internalAnalystLimiter,
        #[Autowire(service: 'limiter.export_external_partner')]
        private readonly RateLimiterFactory $externalPartnerLimiter,
    ) {
    }

    /**
     * @throws TooManyRequestsHttpException if the user's daily export quota is exhausted
     */
    public function consume(User $user): void
    {
        $limiter = $this->limiterForRole($user->getRole())->create((string) $user->getId());
        $limit = $limiter->consume(1);

        if (!$limit->isAccepted()) {
            $retryAfter = max(1, $limit->getRetryAfter()->getTimestamp() - time());
            throw new TooManyRequestsHttpException($retryAfter, 'Export quota exceeded for your role. Try again later.');
        }
    }

    private function limiterForRole(UserRole $role): RateLimiterFactory
    {
        return match ($role) {
            UserRole::Admin => $this->adminLimiter,
            UserRole::InternalAnalyst => $this->internalAnalystLimiter,
            UserRole::ExternalPartner => $this->externalPartnerLimiter,
        };
    }
}
