<?php

declare(strict_types=1);

namespace App\Security;

use App\Service\ApiKeyService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * Authenticates requests carrying an `X-API-Key` header (cahier des charges
 * 5.2.b). Registered on the `api` firewall alongside the existing `jwt` and
 * `refresh-jwt` authenticators (see security.yaml): supports() only claims
 * requests that actually carry the header, so JWT bearer-token requests are
 * untouched and continue to be handled by Lexik's authenticator.
 */
final class ApiKeyAuthenticator extends AbstractAuthenticator
{
    private const HEADER_NAME = 'X-API-Key';

    public function __construct(
        private readonly ApiKeyService $apiKeyService,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        return $request->headers->has(self::HEADER_NAME);
    }

    public function authenticate(Request $request): Passport
    {
        $plainKey = (string) $request->headers->get(self::HEADER_NAME);

        $apiKey = $this->apiKeyService->validateKey($plainKey);
        if (null === $apiKey) {
            throw new CustomUserMessageAuthenticationException('Invalid or revoked API key.');
        }

        $user = $apiKey->getUser();

        // The badge's user loader closure avoids a redundant lookup: the
        // user is already loaded via the ApiKey -> User relation above.
        return new SelfValidatingPassport(new UserBadge($user->getUserIdentifier(), static fn () => $user));
    }

    public function onAuthenticationSuccess(Request $request, \Symfony\Component\Security\Core\Authentication\Token\TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return new JsonResponse(
            ['code' => Response::HTTP_UNAUTHORIZED, 'message' => $exception->getMessage()],
            Response::HTTP_UNAUTHORIZED,
        );
    }
}
