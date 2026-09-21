<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Enum\UserRole;
use App\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * C2.7 classification quotas by role (config/packages/rate_limiter.yaml,
 * "classify_*") - same shape as App\Security\AiAskQuotaPolicy, kept as a
 * separate budget on purpose (see that file's own rate_limiter.yaml
 * comment): classifying in bulk shouldn't exhaust a user's AI Copilot
 * chat quota, and vice versa.
 */
final class ClassifyQuotaPolicy
{
    public function __construct(
        #[Autowire(service: 'limiter.classify_admin')]
        private readonly RateLimiterFactory $adminLimiter,
        #[Autowire(service: 'limiter.classify_internal_analyst')]
        private readonly RateLimiterFactory $internalAnalystLimiter,
        #[Autowire(service: 'limiter.classify_external_partner')]
        private readonly RateLimiterFactory $externalPartnerLimiter,
    ) {
    }

    /**
     * @throws TooManyRequestsHttpException if the user's daily classification quota is exhausted
     */
    public function consume(User $user): void
    {
        $limiter = $this->limiterForRole($user->getRole())->create((string) $user->getId());
        $limit = $limiter->consume(1);

        if (!$limit->isAccepted()) {
            $retryAfter = max(1, $limit->getRetryAfter()->getTimestamp() - time());
            throw new TooManyRequestsHttpException($retryAfter, 'Classification quota exceeded for your role. Try again later.');
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
