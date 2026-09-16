<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Enum\UserRole;
use App\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * C2.1 AI Copilot quotas by role (config/packages/rate_limiter.yaml,
 * "ai_ask_*") - same shape as App\Security\ExportQuotaPolicy (one
 * sliding-window limiter per role, keyed by user id), a day-based quota
 * rather than api_*'s per-minute ceiling because each call has a real
 * Gemini cost, same reasoning as export_* above it in that file.
 */
final class AiAskQuotaPolicy
{
    public function __construct(
        #[Autowire(service: 'limiter.ai_ask_admin')]
        private readonly RateLimiterFactory $adminLimiter,
        #[Autowire(service: 'limiter.ai_ask_internal_analyst')]
        private readonly RateLimiterFactory $internalAnalystLimiter,
        #[Autowire(service: 'limiter.ai_ask_external_partner')]
        private readonly RateLimiterFactory $externalPartnerLimiter,
    ) {
    }

    /**
     * @throws TooManyRequestsHttpException if the user's daily AI Copilot quota is exhausted
     */
    public function consume(User $user): void
    {
        $limiter = $this->limiterForRole($user->getRole())->create((string) $user->getId());
        $limit = $limiter->consume(1);

        if (!$limit->isAccepted()) {
            $retryAfter = max(1, $limit->getRetryAfter()->getTimestamp() - time());
            throw new TooManyRequestsHttpException($retryAfter, 'AI Copilot quota exceeded for your role. Try again later.');
        }
    }

    private function limiterForRole(UserRole $role): RateLimiterFactory
    {
        return match ($role) {
            // Shares Admin's limiter, same reasoning as ExportQuotaPolicy:
            // SuperAdmin is Admin plus account management, not a distinct
            // usage tier.
            UserRole::SuperAdmin, UserRole::Admin => $this->adminLimiter,
            UserRole::InternalAnalyst => $this->internalAnalystLimiter,
            UserRole::ExternalPartner => $this->externalPartnerLimiter,
        };
    }
}
