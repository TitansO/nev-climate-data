<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Notification;
use App\Entity\User;
use App\Repository\NotificationRepository;
use App\Service\NotificationService;
use Lexik\Bundle\JWTAuthenticationBundle\Security\Authenticator\Token\JWTPostAuthenticationToken;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Per-user notification feed (A2.4), reusing the Notification entity/
 * NotificationType enum already in the schema (cahier des charges,
 * A1.3 design) - nothing new is added to the data model.
 *
 * Deliberately JWT-only, mirroring App\Controller\ApiKeyController's
 * assertJwtAuthenticated() (duplicated here rather than extracted into a
 * shared trait/service, to avoid touching ApiKeyController - already
 * shipped and tested - for this task): notifications are personal account
 * data in the same sense API keys are, and the task's own requirements
 * state JWT explicitly, unlike A2.1/A2.3 which accept either JWT or an API
 * key.
 */
final class NotificationController extends AbstractController
{
    private const DEFAULT_PAGE = 1;
    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT = 100;

    public function __construct(
        private readonly NotificationRepository $notificationRepository,
        private readonly NotificationService $notificationService,
        private readonly Security $security,
    ) {
    }

    #[Route('/api/notifications', name: 'api_notifications_list', methods: ['GET'])]
    #[OA\Get(
        summary: 'Liste les notifications de l\'utilisateur connecté, les plus récentes en premier',
        tags: ['Notifications'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', required: false, description: 'Numéro de page (défaut : 1)', schema: new OA\Schema(type: 'integer', default: 1), example: 1),
            new OA\Parameter(name: 'limit', in: 'query', required: false, description: 'Taille de page (défaut : 20, plafond : 100)', schema: new OA\Schema(type: 'integer', default: 20, maximum: 100), example: 20),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Liste paginée des notifications',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(
                            properties: [
                                new OA\Property(property: 'id', type: 'integer', example: 1),
                                new OA\Property(property: 'eventType', type: 'string', example: 'new_data'),
                                new OA\Property(property: 'content', type: 'string', example: 'De nouvelles données de financement sont disponibles.'),
                                new OA\Property(property: 'isRead', type: 'boolean', example: false),
                                new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
                            ]
                        )),
                        new OA\Property(property: 'meta', properties: [
                            new OA\Property(property: 'page', type: 'integer', example: 1),
                            new OA\Property(property: 'limit', type: 'integer', example: 20),
                            new OA\Property(property: 'total', type: 'integer', example: 3),
                            new OA\Property(property: 'totalPages', type: 'integer', example: 1),
                        ], type: 'object'),
                    ]
                )
            ),
            new OA\Response(response: 400, description: 'Paramètre de pagination invalide'),
            new OA\Response(response: 401, description: 'Authentification JWT requise'),
        ]
    )]
    public function list(Request $request): JsonResponse
    {
        $this->assertJwtAuthenticated();

        /** @var User $user */
        $user = $this->getUser();

        [$page, $limit] = self::parsePagination($request);

        $items = $this->notificationRepository->findByUser($user, $page, $limit);
        $total = $this->notificationRepository->countByUser($user);

        return $this->json([
            'data' => array_map(self::toListItem(...), $items),
            'meta' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'totalPages' => $total > 0 ? (int) ceil($total / $limit) : 0,
            ],
        ]);
    }

    #[Route('/api/notifications/unread-count', name: 'api_notifications_unread_count', methods: ['GET'])]
    #[OA\Get(
        summary: 'Nombre de notifications non lues de l\'utilisateur connecté',
        description: 'Pensé pour un badge de navbar interrogé fréquemment : une seule valeur, pas la liste complète.',
        tags: ['Notifications'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Compte des notifications non lues',
                content: new OA\JsonContent(properties: [new OA\Property(property: 'count', type: 'integer', example: 2)])
            ),
            new OA\Response(response: 401, description: 'Authentification JWT requise'),
        ]
    )]
    public function unreadCount(): JsonResponse
    {
        $this->assertJwtAuthenticated();

        /** @var User $user */
        $user = $this->getUser();

        return $this->json(['count' => $this->notificationRepository->countUnreadByUser($user)]);
    }

    #[Route('/api/notifications/{id}/read', name: 'api_notifications_mark_read', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    #[OA\Patch(
        summary: 'Marque une notification de l\'utilisateur connecté comme lue',
        description: 'Idempotent : marquer une notification déjà lue renvoie simplement 204 à nouveau.',
        tags: ['Notifications'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 204, description: 'Notification marquée comme lue'),
            new OA\Response(response: 401, description: 'Authentification JWT requise'),
            new OA\Response(response: 404, description: 'Notification introuvable (inexistante ou appartenant à un autre utilisateur)'),
        ]
    )]
    public function markRead(int $id): Response
    {
        $this->assertJwtAuthenticated();

        /** @var User $user */
        $user = $this->getUser();

        $notification = $this->notificationRepository->findOneForUser($id, $user);
        if (null === $notification) {
            throw $this->createNotFoundException('Notification not found.');
        }

        $this->notificationService->markAsRead($notification);

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    #[Route('/api/notifications/read-all', name: 'api_notifications_mark_all_read', methods: ['POST'])]
    #[OA\Post(
        summary: 'Marque toutes les notifications non lues de l\'utilisateur connecté comme lues',
        tags: ['Notifications'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Nombre de notifications marquées comme lues par cet appel',
                content: new OA\JsonContent(properties: [new OA\Property(property: 'updated', type: 'integer', example: 2)])
            ),
            new OA\Response(response: 401, description: 'Authentification JWT requise'),
        ]
    )]
    public function markAllRead(): JsonResponse
    {
        $this->assertJwtAuthenticated();

        /** @var User $user */
        $user = $this->getUser();

        return $this->json(['updated' => $this->notificationService->markAllAsRead($user)]);
    }

    /**
     * @return array{page: int, limit: int}
     */
    private static function parsePagination(Request $request): array
    {
        $rawPage = $request->query->get('page');
        $page = self::DEFAULT_PAGE;
        if (null !== $rawPage && '' !== $rawPage) {
            if (!is_numeric($rawPage) || (string) (int) $rawPage !== (string) $rawPage || (int) $rawPage < 1) {
                throw new BadRequestHttpException('Invalid value for parameter "page": must be a positive integer.');
            }
            $page = (int) $rawPage;
        }

        $rawLimit = $request->query->get('limit');
        $limit = self::DEFAULT_LIMIT;
        if (null !== $rawLimit && '' !== $rawLimit) {
            if (!is_numeric($rawLimit) || (string) (int) $rawLimit !== (string) $rawLimit || (int) $rawLimit < 1) {
                throw new BadRequestHttpException('Invalid value for parameter "limit": must be a positive integer.');
            }
            $limit = min((int) $rawLimit, self::MAX_LIMIT);
        }

        return [$page, $limit];
    }

    /**
     * @return array{id: int|null, eventType: string, content: string, isRead: bool, createdAt: string}
     */
    private static function toListItem(Notification $notification): array
    {
        return [
            'id' => $notification->getId(),
            'eventType' => $notification->getEventType()->value,
            'content' => $notification->getContent(),
            'isRead' => $notification->isRead(),
            'createdAt' => $notification->getCreatedAt()->format(\DATE_ATOM),
        ];
    }

    private function assertJwtAuthenticated(): void
    {
        if (!$this->security->getToken() instanceof JWTPostAuthenticationToken) {
            throw new AccessDeniedHttpException('Notifications require JWT authentication.');
        }
    }
}
