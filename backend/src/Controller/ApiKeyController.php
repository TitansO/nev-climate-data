<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ApiKey;
use App\Entity\User;
use App\Repository\ApiKeyRepository;
use App\Service\ApiKeyService;
use Lexik\Bundle\JWTAuthenticationBundle\Security\Authenticator\Token\JWTPostAuthenticationToken;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Self-service API key management for the authenticated user (cahier des
 * charges 5.2.b). Deliberately JWT-only (see assertJwtAuthenticated()): a
 * key that manages keys would let a single leaked API key mint further
 * keys, which is a privilege-escalation path an interactive JWT session
 * doesn't have (it always starts from a real login).
 *
 * The `bearerAuth` / `apiKeyAuth` security schemes referenced below are
 * declared in App\OpenApi\SecuritySchemes, not here: swagger-php rejects a
 * class that mixes a root SecurityScheme attribute with per-method root
 * Operation attributes (Get/Post/Delete) in the same class.
 */
final class ApiKeyController extends AbstractController
{
    public function __construct(
        private readonly ApiKeyService $apiKeyService,
        private readonly ApiKeyRepository $apiKeyRepository,
        private readonly Security $security,
    ) {
    }

    #[Route('/api/api-keys', name: 'api_keys_create', methods: ['POST'])]
    #[OA\Post(
        summary: 'Génère une nouvelle clé API pour l\'utilisateur connecté',
        description: 'La clé brute retournée n\'est jamais accessible à nouveau ensuite — seul son hash est conservé en base. Elle doit être sauvegardée immédiatement par le client.',
        tags: ['API Keys'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 201,
                description: 'Clé créée — la propriété "key" ne sera plus jamais retournée',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'id', type: 'integer', example: 1),
                        new OA\Property(property: 'key', type: 'string', example: 'nev_1f2a3b4c...'),
                        new OA\Property(property: 'status', type: 'string', example: 'active'),
                        new OA\Property(property: 'quota', type: 'integer', example: 5000),
                        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Authentification JWT requise'),
            new OA\Response(response: 403, description: 'Authentification par clé API refusée pour cet endpoint'),
        ]
    )]
    public function create(): JsonResponse
    {
        $this->assertJwtAuthenticated();

        /** @var User $user */
        $user = $this->getUser();

        $generated = $this->apiKeyService->generateKey($user);

        return $this->json([
            'id' => $generated->apiKey->getId(),
            'key' => $generated->plainKey,
            'status' => $generated->apiKey->getStatus()->value,
            'quota' => $generated->apiKey->getQuota(),
            'created_at' => $generated->apiKey->getCreatedAt()->format(\DATE_ATOM),
        ], Response::HTTP_CREATED);
    }

    #[Route('/api/api-keys', name: 'api_keys_list', methods: ['GET'])]
    #[OA\Get(
        summary: 'Liste les clés API de l\'utilisateur connecté',
        description: 'Ne retourne jamais le hash ni la clé brute — uniquement les métadonnées de chaque clé.',
        tags: ['API Keys'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Liste des clés',
                content: new OA\JsonContent(
                    type: 'array',
                    items: new OA\Items(
                        properties: [
                            new OA\Property(property: 'id', type: 'integer', example: 1),
                            new OA\Property(property: 'status', type: 'string', example: 'active'),
                            new OA\Property(property: 'quota', type: 'integer', example: 5000),
                            new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
                            new OA\Property(property: 'revoked_at', type: 'string', format: 'date-time', nullable: true),
                        ]
                    )
                )
            ),
            new OA\Response(response: 401, description: 'Authentification JWT requise'),
            new OA\Response(response: 403, description: 'Authentification par clé API refusée pour cet endpoint'),
        ]
    )]
    public function list(): JsonResponse
    {
        $this->assertJwtAuthenticated();

        /** @var User $user */
        $user = $this->getUser();

        $apiKeys = $this->apiKeyRepository->findByUser($user);

        return $this->json(array_map(self::toListItem(...), $apiKeys));
    }

    #[Route('/api/api-keys/{id}', name: 'api_keys_revoke', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    #[OA\Delete(
        summary: 'Révoque une clé API appartenant à l\'utilisateur connecté',
        tags: ['API Keys'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Clé révoquée'),
            new OA\Response(response: 401, description: 'Authentification JWT requise'),
            new OA\Response(response: 403, description: 'Authentification par clé API refusée pour cet endpoint'),
            new OA\Response(response: 404, description: 'Clé introuvable (inexistante ou appartenant à un autre utilisateur)'),
        ]
    )]
    public function revoke(int $id): Response
    {
        $this->assertJwtAuthenticated();

        /** @var User $user */
        $user = $this->getUser();

        $apiKey = $this->apiKeyRepository->findOneForUser($id, $user);
        if (null === $apiKey) {
            throw $this->createNotFoundException('API key not found.');
        }

        $this->apiKeyService->revokeKey($apiKey);

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    /**
     * @return array{id: int|null, status: string, quota: int, created_at: string, revoked_at: string|null}
     */
    private static function toListItem(ApiKey $apiKey): array
    {
        return [
            'id' => $apiKey->getId(),
            'status' => $apiKey->getStatus()->value,
            'quota' => $apiKey->getQuota(),
            'created_at' => $apiKey->getCreatedAt()->format(\DATE_ATOM),
            'revoked_at' => $apiKey->getRevokedAt()?->format(\DATE_ATOM),
        ];
    }

    private function assertJwtAuthenticated(): void
    {
        if (!$this->security->getToken() instanceof JWTPostAuthenticationToken) {
            throw new AccessDeniedHttpException('API key management requires JWT authentication.');
        }
    }
}
