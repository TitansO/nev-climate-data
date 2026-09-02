<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\EventListener\ApiExceptionListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * A3.5: unit-tested in isolation (same pattern as ApiRateLimitListenerTest)
 * rather than only indirectly through whichever HttpExceptionInterface
 * every controller test happens to throw. That indirect coverage already
 * exercises the HttpExceptionInterface branch of onKernelException()
 * thoroughly (every 4xx across the whole suite goes through it) but never
 * the *other* branch - a genuine, un-typed \Throwable (a real bug, not a
 * deliberate 4xx) - which is exactly the security-relevant one: that
 * branch exists specifically to never leak an internal exception message
 * on a 500. A regression there (e.g. someone "simplifying" the two
 * branches into one) would leak internals in production without any
 * existing test noticing.
 */
final class ApiExceptionListenerTest extends TestCase
{
    private function dispatch(string $path, \Throwable $throwable): ExceptionEvent
    {
        $kernel = $this->createStub(HttpKernelInterface::class);
        $event = new ExceptionEvent($kernel, Request::create($path), HttpKernelInterface::MAIN_REQUEST, $throwable);

        (new ApiExceptionListener())->onKernelException($event);

        return $event;
    }

    public function testGenericThrowableOnApiPathBecomes500WithoutLeakingItsMessage(): void
    {
        $event = $this->dispatch('/api/funding', new \RuntimeException('database password is Sup3rSecret!'));

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(500, $response->getStatusCode());

        $body = json_decode($response->getContent(), true);
        self::assertSame(500, $body['code']);
        self::assertSame('Internal Server Error', $body['message']);
        self::assertStringNotContainsString('Sup3rSecret', $response->getContent());
    }

    public function testHttpExceptionOnApiPathKeepsItsOwnStatusAndMessage(): void
    {
        $event = $this->dispatch('/api/funding/999', new NotFoundHttpException('Funding record not found.'));

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(404, $response->getStatusCode());

        $body = json_decode($response->getContent(), true);
        self::assertSame(404, $body['code']);
        self::assertSame('Funding record not found.', $body['message']);
    }

    /**
     * A3.4's Retry-After header (TooManyRequestsHttpException) must survive
     * this listener rebuilding the response from scratch - regression test
     * for the bug fixed alongside App\EventListener\ApiRateLimitListener.
     */
    public function testHttpExceptionHeadersArePreservedOnTheRebuiltResponse(): void
    {
        $exception = new \Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException(30, 'Too many requests.');
        $event = $this->dispatch('/api/funding', $exception);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame('30', $response->headers->get('Retry-After'));
    }

    public function testNonApiPathIsIgnored(): void
    {
        $event = $this->dispatch('/some-other-path', new \RuntimeException('irrelevant'));

        self::assertFalse($event->hasResponse());
    }

    public function testAlreadyHandledEventIsLeftAlone(): void
    {
        $kernel = $this->createStub(HttpKernelInterface::class);
        $event = new ExceptionEvent($kernel, Request::create('/api/funding'), HttpKernelInterface::MAIN_REQUEST, new \RuntimeException('x'));
        $existing = new Response('already set', 418);
        $event->setResponse($existing);

        (new ApiExceptionListener())->onKernelException($event);

        self::assertSame($existing, $event->getResponse());
    }
}
