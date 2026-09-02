<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Forces every uncaught exception on `/api/*` to render as JSON
 * (`{"code":..., "message":...}`, the same shape already produced by the
 * JWT/API-key authentication failure handlers), instead of Symfony's
 * default HTML debug/error page.
 *
 * Added for A2.1 after confirming live (during A2 prep analysis) that a
 * request to an unknown `/api/*` route returns an HTML page even with
 * `Accept: application/json` — there was no JSON-forcing mechanism for
 * generic framework exceptions anywhere in the project, only the two
 * dedicated authentication failure handlers produced JSON, and only for
 * authentication failures specifically.
 *
 * Priority -64: below Symfony Security's own exception listener (so a real
 * AuthenticationException/AccessDeniedException — already turned into JSON
 * by Lexik's entry point or the custom failure handlers — is left alone via
 * the `hasResponse()` guard below), but above FrameworkBundle's default
 * ErrorListener (priority -128), so this claims the response before the
 * default HTML renderer runs.
 */
final class ApiExceptionListener implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => ['onKernelException', -64],
        ];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        if ($event->hasResponse()) {
            return;
        }

        if (!str_starts_with($event->getRequest()->getPathInfo(), '/api')) {
            return;
        }

        $throwable = $event->getThrowable();
        $status = $throwable instanceof HttpExceptionInterface
            ? $throwable->getStatusCode()
            : Response::HTTP_INTERNAL_SERVER_ERROR;

        // Never leak internal exception messages for unexpected (500) errors.
        $message = $throwable instanceof HttpExceptionInterface
            ? $throwable->getMessage()
            : 'Internal Server Error';

        // Preserve headers the exception itself set (A3.4: TooManyRequestsHttpException
        // carries a real "Retry-After", lost otherwise since this listener builds a brand
        // new Response rather than modifying Symfony's default one).
        $headers = $throwable instanceof HttpExceptionInterface ? $throwable->getHeaders() : [];

        $event->setResponse(new JsonResponse(['code' => $status, 'message' => $message], $status, $headers));
    }
}
