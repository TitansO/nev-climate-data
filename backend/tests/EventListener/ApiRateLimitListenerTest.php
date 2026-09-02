<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\Entity\Enum\UserRole;
use App\Entity\User;
use App\EventListener\ApiRateLimitListener;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * A3.4: App\EventListener\ApiRateLimitListener, unit-tested in isolation
 * rather than through the real HTTP kernel/`config/packages/
 * rate_limiter.yaml` limiters like FundingExportTest does for the
 * pre-existing export quotas. Deliberate difference: those limiters only
 * ever get exercised by one narrowly-scoped test each, but this listener
 * runs on *every* `/api/*` request across the whole functional-test suite
 * (~180 tests) - a fixed-window ceiling low enough to be practical to test
 * (and to actually protect against abuse) will always be smaller than the
 * suite's own real anonymous/per-role traffic volume, so exercising it
 * through the shared client/production limiters would make unrelated
 * tests intermittently fail with 429 depending only on what ran before
 * them (confirmed live: an earlier version of this test did exactly that
 * to 8+ unrelated tests in the same run). Constructing the listener
 * directly with its own private, in-memory limiters keeps this test fast,
 * deterministic, and fully decoupled from every other test's traffic.
 */
final class ApiRateLimitListenerTest extends TestCase
{
    private function makeListener(?User $user, int $limit = 3): ApiRateLimitListener
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        // One shared InMemoryStorage instance per listener, but a distinct
        // limiter "id" per role tier (exactly like config/packages/
        // rate_limiter.yaml's real api_* limiters) so consuming from one
        // tier never affects another - mirrors limiterForRole()'s real
        // role->limiter mapping being one-to-one.
        $storage = new InMemoryStorage();
        $factory = static fn (string $id) => new RateLimiterFactory(['id' => $id, 'policy' => 'fixed_window', 'limit' => $limit, 'interval' => '1 minute'], $storage);

        // "dev", never "test": the listener no-ops entirely in the test
        // environment (see its class docblock) - this unit test exercises
        // the real enforcement logic directly, bypassing that guard, since
        // it never goes through a real kernel boot/%kernel.environment%.
        return new ApiRateLimitListener($security, $factory('anon'), $factory('admin'), $factory('analyst'), $factory('partner'), 'dev');
    }

    private function makeEvent(string $path): RequestEvent
    {
        return new RequestEvent($this->createStub(HttpKernelInterface::class), Request::create($path), HttpKernelInterface::MAIN_REQUEST);
    }

    public function testAnonymousRequestsAreRateLimitedByIpAfterTheConfiguredCeiling(): void
    {
        $listener = $this->makeListener(null, limit: 3);

        for ($i = 0; $i < 3; ++$i) {
            $listener->onKernelRequest($this->makeEvent('/api/funding'));
        }

        $this->expectException(TooManyRequestsHttpException::class);
        $listener->onKernelRequest($this->makeEvent('/api/funding'));
    }

    public function testAuthenticatedRequestsAreRateLimitedByRoleAfterTheConfiguredCeiling(): void
    {
        $user = new User('Fatou Ndiaye', 'ratelimit-user@example.com', 'irrelevant-hash', UserRole::ExternalPartner);
        $listener = $this->makeListener($user, limit: 3);

        for ($i = 0; $i < 3; ++$i) {
            $listener->onKernelRequest($this->makeEvent('/api/funding'));
        }

        $this->expectException(TooManyRequestsHttpException::class);
        $listener->onKernelRequest($this->makeEvent('/api/funding'));
    }

    /**
     * limiterForRole() must route Admin to its own "admin" limiter, not
     * accidentally reuse "anon"/"partner"/"analyst" - proven here by an
     * Admin user hitting a ceiling of 1 on the very first extra request,
     * exactly as strictly as the anonymous/ExternalPartner tests above.
     */
    public function testAdminRoleIsRoutedToItsOwnLimiter(): void
    {
        $admin = new User('Admin', 'admin@example.com', 'irrelevant-hash', UserRole::Admin);
        $listener = $this->makeListener($admin, limit: 1);

        $listener->onKernelRequest($this->makeEvent('/api/funding'));

        $this->expectException(TooManyRequestsHttpException::class);
        $listener->onKernelRequest($this->makeEvent('/api/funding'));
    }

    /**
     * The three excluded prefixes must never 429 regardless of volume:
     * `/api/auth/login` has its own dedicated, much stricter mechanism
     * (login_throttling, A1.4), `/api/health` is polled by Docker/uptime
     * monitors on their own schedule, and `/api/doc` is not a realistic
     * abuse target.
     */
    public function testExcludedEndpointsAreNeverRateLimitedEvenPastTheCeiling(): void
    {
        $listener = $this->makeListener(null, limit: 1);

        foreach (['/api/health', '/api/auth/login', '/api/doc'] as $path) {
            for ($i = 0; $i < 5; ++$i) {
                $listener->onKernelRequest($this->makeEvent($path));
            }
        }

        $this->addToAssertionCount(1);
    }

    public function testNonApiRequestsAreIgnoredEvenPastTheCeiling(): void
    {
        $listener = $this->makeListener(null, limit: 1);

        for ($i = 0; $i < 5; ++$i) {
            $listener->onKernelRequest($this->makeEvent('/index.html'));
        }

        $this->addToAssertionCount(1);
    }
}
