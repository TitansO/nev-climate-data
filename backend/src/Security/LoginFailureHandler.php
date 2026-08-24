<?php

declare(strict_types=1);

namespace App\Security;

use Lexik\Bundle\JWTAuthenticationBundle\Security\Http\Authentication\AuthenticationFailureHandler as LexikAuthenticationFailureHandler;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;

/**
 * Wraps Lexik's JWT failure handler to return 429 for throttled login
 * attempts. Lexik maps a failure to a status code via the exception's
 * getCode(), but TooManyLoginAttemptsAuthenticationException never sets one
 * (defaults to 0), so without this it falls back to the generic 401 —
 * indistinguishable from a wrong password.
 *
 * Injects the concrete Lexik handler directly rather than decorating
 * "lexik_jwt_authentication.handler.authentication_failure": the json_login
 * factory clones that service definition inline into an anonymous service
 * when building CustomAuthenticationFailureHandler, so a decorator on the
 * original ID is never actually consulted at runtime.
 */
final class LoginFailureHandler implements AuthenticationFailureHandlerInterface
{
    public function __construct(
        private readonly LexikAuthenticationFailureHandler $decorated,
    ) {
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        if ($exception instanceof TooManyLoginAttemptsAuthenticationException) {
            return new JsonResponse(
                ['code' => Response::HTTP_TOO_MANY_REQUESTS, 'message' => strtr($exception->getMessageKey(), $exception->getMessageData())],
                Response::HTTP_TOO_MANY_REQUESTS,
            );
        }

        return $this->decorated->onAuthenticationFailure($request, $exception);
    }
}
