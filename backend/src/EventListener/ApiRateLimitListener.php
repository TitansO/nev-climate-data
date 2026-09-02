<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Enum\UserRole;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * A3.4: general request-rate ceiling on every `/api/*` call, "configurable
 * par rôle" (règle 5.4) - protects the platform as a whole against abuse/
 * scraping, distinct from App\Security\ExportQuotaPolicy (a single business
 * operation's daily volume) and from `login_throttling` (security.yaml,
 * protects authentication itself, not general usage).
 *
 * One sliding-window limiter per role tier (config/packages/
 * rate_limiter.yaml, "api_*"), keyed by user id for authenticated traffic
 * (JWT or API key - both resolve to a real App\Entity\User via Security)
 * and by client IP for anonymous traffic, i.e. every PUBLIC_ACCESS
 * endpoint (GET /api/funding, /api/analytics/*, /api/search, /api/reports).
 *
 * Excluded on purpose:
 * - `/api/auth/login` - already has its own dedicated, much stricter
 *   mechanism (login_throttling, A1.4) built for authentication abuse
 *   specifically; double-throttling it here would only add complexity, the
 *   looser general ceiling below would essentially never bind first.
 * - `/api/doc` - Swagger/OpenAPI UI, not a realistic abuse target, and
 *   rate-limiting it would only hurt legitimate developer usage.
 * - `/api/health` - must never be throttled: Docker healthchecks and any
 *   uptime monitor poll it on their own schedule, unrelated to real API
 *   traffic volume.
 *
 * Priority 0 on kernel.request: below Security's own firewall listener
 * (priority 8), so by the time this runs, Security::getUser() already
 * reflects the authenticated user (JWT or API key) if any - running before
 * it would see every request as anonymous.
 *
 * Disabled in the `test` environment (see onKernelRequest()'s early
 * return). Unlike login_throttling (A1.4, exercised by exactly one test
 * designed around it) this listener runs on *every* `/api/*` request
 * across the whole functional-test suite - real pre-existing tests
 * legitimately fire more requests than a practical per-minute abuse
 * ceiling in far less than a minute of wall-clock test time, confirmed
 * live: enabling it in `test` made unrelated, previously-passing
 * controller tests fail with 429 depending only on execution order, no
 * storage backend choice (filesystem: leaks across tests sharing one
 * client IP/user id; in-memory array: breaks WebTestCase's own
 * per-request kernel reboot) avoids that. The mechanism itself is
 * unit-tested directly against real, private, in-memory limiters instead
 * - see tests/EventListener/ApiRateLimitListenerTest.php.
 */
final class ApiRateLimitListener implements EventSubscriberInterface
{
    private const EXCLUDED_PREFIXES = ['/api/auth/login', '/api/doc', '/api/health'];

    public function __construct(
        private readonly Security $security,
        #[Autowire(service: 'limiter.api_anonymous')]
        private readonly RateLimiterFactory $anonymousLimiter,
        #[Autowire(service: 'limiter.api_admin')]
        private readonly RateLimiterFactory $adminLimiter,
        #[Autowire(service: 'limiter.api_internal_analyst')]
        private readonly RateLimiterFactory $internalAnalystLimiter,
        #[Autowire(service: 'limiter.api_external_partner')]
        private readonly RateLimiterFactory $externalPartnerLimiter,
        #[Autowire(param: 'kernel.environment')]
        private readonly string $environment,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 0],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if ('test' === $this->environment) {
            return;
        }

        if (!$event->isMainRequest()) {
            return;
        }

        $path = $event->getRequest()->getPathInfo();
        if (!str_starts_with($path, '/api')) {
            return;
        }

        foreach (self::EXCLUDED_PREFIXES as $excluded) {
            if (str_starts_with($path, $excluded)) {
                return;
            }
        }

        $user = $this->security->getUser();
        $limiter = $user instanceof User
            ? $this->limiterForRole($user->getRole())->create((string) $user->getId())
            : $this->anonymousLimiter->create($event->getRequest()->getClientIp() ?? 'unknown');

        $limit = $limiter->consume(1);
        if (!$limit->isAccepted()) {
            $retryAfter = max(1, $limit->getRetryAfter()->getTimestamp() - time());
            throw new TooManyRequestsHttpException($retryAfter, 'Too many requests. Please slow down and try again later.');
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
