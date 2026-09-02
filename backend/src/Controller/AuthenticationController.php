<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenManagerInterface;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AuthenticationController extends AbstractController
{
    public function __construct(
        private readonly RefreshTokenManagerInterface $refreshTokenManager,
    ) {
    }

    /**
     * A3.9: never actually invoked - `config/routes.yaml`'s own comment
     * explains why (`json_login`'s authenticator intercepts and responds
     * at `kernel.request`, before controller resolution runs). This
     * method exists solely so NelmioApiDocBundle has a real controller to
     * reflect `#[OA\Post]` off of for `POST /api/auth/login` - see
     * routes.yaml's `api_auth_login` entry. The exception is defensive:
     * if this were ever actually reached (e.g. the firewall config
     * changes and stops intercepting first), fail loudly rather than
     * silently returning something wrong.
     */
    #[OA\Post(
        path: '/api/auth/login',
        summary: 'Authentification par email/mot de passe',
        description: 'Protégé par un throttling anti-brute-force (A1.4, 5 tentatives / 15 min par combinaison IP+email) - une 6e tentative renvoie 429, pas 401, même avec un mot de passe correct.',
        tags: ['Authentication'],
        security: [],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['email', 'password'],
                properties: [
                    new OA\Property(property: 'email', type: 'string', format: 'email', example: 'admin@nev-climate-data.demo'),
                    new OA\Property(property: 'password', type: 'string', format: 'password', example: 'ClimateDemo2026!'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Authentification réussie',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'token', type: 'string', description: 'JWT à courte durée de vie, à envoyer en `Authorization: Bearer <token>`'),
                        new OA\Property(property: 'refresh_token', type: 'string', description: 'À usage unique - voir POST /api/auth/refresh'),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Email ou mot de passe incorrect'),
            new OA\Response(response: 429, description: 'Trop de tentatives échouées - réessayer plus tard'),
        ]
    )]
    public function login(): never
    {
        throw new \LogicException('Unreachable: the "login" firewall\'s json_login authenticator always intercepts this request first.');
    }

    /**
     * A3.9: same reasoning as login() above - never actually invoked, the
     * "api" firewall's refresh-jwt authenticator intercepts first. Exists
     * solely for NelmioApiDocBundle to document POST /api/auth/refresh
     * (routes.yaml's `api_auth_refresh` entry).
     */
    #[OA\Post(
        path: '/api/auth/refresh',
        summary: 'Échange un refresh token contre une nouvelle paire de jetons',
        description: 'Rotation à usage unique (`single_use: true`, `config/packages/gesdinet_jwt_refresh_token.yaml`) : chaque refresh token n\'est valable qu\'une fois - la réponse en fournit toujours un nouveau à utiliser pour le prochain rafraîchissement. TTL 30 jours.',
        tags: ['Authentication'],
        security: [],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['refresh_token'],
                properties: [
                    new OA\Property(property: 'refresh_token', type: 'string'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Nouvelle paire de jetons',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'token', type: 'string'),
                        new OA\Property(property: 'refresh_token', type: 'string', description: 'Remplace l\'ancien - l\'ancien devient invalide après cet appel'),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Refresh token invalide, expiré, ou déjà utilisé'),
        ]
    )]
    public function refreshToken(): never
    {
        throw new \LogicException('Unreachable: the "api" firewall\'s refresh-jwt authenticator always intercepts this request first.');
    }

    #[Route('/api/auth/me', name: 'auth_me', methods: ['GET'])]
    public function me(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->json([
            'email' => $user->getUserIdentifier(),
            'role' => strtolower(str_replace('ROLE_', '', $user->getRoles()[0])),
        ]);
    }

    #[Route('/api/auth/logout', name: 'auth_logout', methods: ['POST'])]
    public function logout(): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $refreshToken = $this->refreshTokenManager->getLastFromUsername($user->getUserIdentifier());
        if (null !== $refreshToken) {
            $this->refreshTokenManager->delete($refreshToken);
        }

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
