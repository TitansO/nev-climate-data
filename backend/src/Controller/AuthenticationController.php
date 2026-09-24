<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Enum\UserRole;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\UserManagementService;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenManagerInterface;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

final class AuthenticationController extends AbstractController
{
    public function __construct(
        private readonly RefreshTokenManagerInterface $refreshTokenManager,
        private readonly UserRepository $userRepository,
        private readonly UserManagementService $userManagementService,
        #[Autowire(service: 'limiter.register_anonymous')]
        private readonly RateLimiterFactory $registerLimiter,
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

    /**
     * Auto-inscription publique (structuration des accès, visiteur ->
     * ROLE_USER). Toujours ROLE_EXTERNAL_PARTNER - le palier le moins
     * privilégié parmi les rôles authentifiés - jamais un rôle choisi par
     * l'appelant, contrairement à POST /api/users (réservé au SuperAdmin,
     * qui accepte un champ "role" arbitraire pour provisionner n'importe
     * quel compte). Ne connecte pas automatiquement l'appelant : le
     * frontend enchaîne un appel à POST /api/auth/login juste après,
     * réutilisant tel quel le flux d'authentification existant plutôt que
     * de dupliquer la génération de JWT ici.
     */
    #[Route('/api/auth/register', name: 'api_auth_register', methods: ['POST'])]
    #[OA\Post(
        summary: 'Crée un compte visiteur (auto-inscription publique)',
        description: 'Ouvert à tous (PUBLIC_ACCESS), limité par IP (config/packages/rate_limiter.yaml, "register_anonymous") pour éviter la création massive de comptes. Le compte créé a toujours le rôle "external_partner", le palier le moins privilégié - pour créer un compte avec un autre rôle, voir POST /api/users (réservé au SuperAdmin).',
        tags: ['Authentication'],
        security: [],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'email', 'password'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'Amina Diallo'),
                    new OA\Property(property: 'email', type: 'string', format: 'email', example: 'amina.diallo@example.com'),
                    new OA\Property(property: 'password', type: 'string', format: 'password', example: 'UnMotDePasseSolide2026!'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Compte créé'),
            new OA\Response(response: 400, description: 'Corps de requête invalide (champ manquant, email mal formé, mot de passe trop faible)'),
            new OA\Response(response: 409, description: 'Cet email est déjà utilisé'),
            new OA\Response(response: 429, description: 'Trop de comptes créés depuis cette adresse IP - réessayer plus tard'),
        ]
    )]
    public function register(Request $request): JsonResponse
    {
        $limit = $this->registerLimiter->create($request->getClientIp() ?? 'unknown')->consume(1);
        if (!$limit->isAccepted()) {
            $retryAfter = max(1, $limit->getRetryAfter()->getTimestamp() - time());
            throw new TooManyRequestsHttpException($retryAfter, 'Trop de comptes créés depuis cette adresse. Réessayez plus tard.');
        }

        $body = json_decode($request->getContent(), true);
        if (!\is_array($body)) {
            throw new BadRequestHttpException('Corps de requête JSON invalide.');
        }

        $name = trim((string) ($body['name'] ?? ''));
        if ('' === $name) {
            throw new BadRequestHttpException('Le champ "name" est requis.');
        }

        $email = trim((string) ($body['email'] ?? ''));
        if (false === filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            throw new BadRequestHttpException('Le champ "email" doit être une adresse email valide.');
        }

        $password = (string) ($body['password'] ?? '');
        if (\strlen($password) < 8) {
            throw new BadRequestHttpException('Le mot de passe doit contenir au moins 8 caractères.');
        }
        // Même règle minimale que POST /api/users (App\Controller\UserController)
        // - voir sa docblock pour le contexte (audit pré-production 2026-09-23).
        if (1 !== preg_match('/[a-zA-Z]/', $password) || 1 !== preg_match('/[0-9]/', $password)) {
            throw new BadRequestHttpException('Le mot de passe doit contenir au moins une lettre et un chiffre.');
        }

        if (null !== $this->userRepository->findOneBy(['email' => $email])) {
            throw new ConflictHttpException('Cet email est déjà utilisé.');
        }

        $user = $this->userManagementService->createUser($name, $email, $password, UserRole::ExternalPartner);

        return $this->json(['email' => $user->getEmail(), 'role' => $user->getRole()->value], Response::HTTP_CREATED);
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
