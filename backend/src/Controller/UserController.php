<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Enum\UserRole;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\UserManagementService;
use Lexik\Bundle\JWTAuthenticationBundle\Security\Authenticator\Token\JWTPostAuthenticationToken;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Account management, reserved to ROLE_SUPER_ADMIN (security.yaml's
 * access_control already rejects anyone else with a 403 before a request
 * ever reaches this controller - the assertJwtAuthenticated() calls below
 * only narrow that further to JWT sessions, mirroring
 * App\Controller\ApiKeyController and App\Controller\NotificationController:
 * an API key is scoped to its own quota-limited read/write surface, never
 * to standing up or tearing down other accounts.
 *
 * Every mutation below refuses to target the caller's own account
 * (self-role-change, self-delete). That single rule is also what keeps the
 * system from ever being left with zero SuperAdmins: whoever performs the
 * action is necessarily a SuperAdmin who remains untouched by it, so at
 * least one SuperAdmin always survives any single request - no separate
 * "last SuperAdmin" count is needed.
 */
final class UserController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly UserManagementService $userManagementService,
        private readonly Security $security,
    ) {
    }

    #[Route('/api/users', name: 'api_users_list', methods: ['GET'])]
    #[OA\Get(
        summary: 'Liste tous les comptes utilisateurs',
        description: 'Réservé aux SuperAdmin.',
        tags: ['Users'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Liste des utilisateurs',
                content: new OA\JsonContent(
                    type: 'array',
                    items: new OA\Items(
                        properties: [
                            new OA\Property(property: 'id', type: 'integer', example: 1),
                            new OA\Property(property: 'name', type: 'string', example: 'Amina Diallo'),
                            new OA\Property(property: 'email', type: 'string', example: 'admin@nev-climate-data.demo'),
                            new OA\Property(property: 'role', type: 'string', example: 'admin'),
                            new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
                        ]
                    )
                )
            ),
            new OA\Response(response: 401, description: 'Authentification JWT requise'),
            new OA\Response(response: 403, description: 'Réservé aux SuperAdmin'),
        ]
    )]
    public function list(): JsonResponse
    {
        $this->assertJwtAuthenticated();

        $users = $this->userRepository->findBy([], ['createdAt' => 'ASC']);

        return $this->json(array_map(self::toListItem(...), $users));
    }

    #[Route('/api/users', name: 'api_users_create', methods: ['POST'])]
    #[OA\Post(
        summary: 'Crée un compte utilisateur',
        description: 'Réservé aux SuperAdmin.',
        tags: ['Users'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'email', 'password', 'role'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'Nouveau Membre'),
                    new OA\Property(property: 'email', type: 'string', example: 'membre@nev-climate-data.demo'),
                    new OA\Property(property: 'password', type: 'string', example: 'UnMotDePasseSolide2026!'),
                    new OA\Property(property: 'role', type: 'string', enum: ['super_admin', 'admin', 'internal_analyst', 'external_partner'], example: 'internal_analyst'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Utilisateur créé'),
            new OA\Response(response: 400, description: 'Corps de requête invalide (champ manquant, email mal formé, mot de passe trop court, rôle inconnu)'),
            new OA\Response(response: 401, description: 'Authentification JWT requise'),
            new OA\Response(response: 403, description: 'Réservé aux SuperAdmin'),
            new OA\Response(response: 409, description: 'Cet email est déjà utilisé'),
        ]
    )]
    public function create(Request $request): JsonResponse
    {
        $this->assertJwtAuthenticated();

        [$name, $email, $password, $role] = self::parseCreatePayload($request);

        if (null !== $this->userRepository->findOneBy(['email' => $email])) {
            throw new ConflictHttpException('Cet email est déjà utilisé.');
        }

        $user = $this->userManagementService->createUser($name, $email, $password, $role);

        return $this->json(self::toListItem($user), Response::HTTP_CREATED);
    }

    #[Route('/api/users/{id}/role', name: 'api_users_update_role', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    #[OA\Patch(
        summary: 'Change le rôle d\'un utilisateur',
        description: 'Réservé aux SuperAdmin. Un SuperAdmin ne peut pas modifier son propre rôle (voir la docblock de la classe).',
        tags: ['Users'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['role'],
                properties: [new OA\Property(property: 'role', type: 'string', enum: ['super_admin', 'admin', 'internal_analyst', 'external_partner'], example: 'admin')]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Rôle mis à jour'),
            new OA\Response(response: 400, description: 'Rôle inconnu'),
            new OA\Response(response: 401, description: 'Authentification JWT requise'),
            new OA\Response(response: 403, description: 'Réservé aux SuperAdmin, ou tentative de modifier son propre rôle'),
            new OA\Response(response: 404, description: 'Utilisateur introuvable'),
        ]
    )]
    public function updateRole(int $id, Request $request): JsonResponse
    {
        $this->assertJwtAuthenticated();

        /** @var User $caller */
        $caller = $this->getUser();
        if ($caller->getId() === $id) {
            throw new AccessDeniedHttpException('Vous ne pouvez pas modifier votre propre rôle.');
        }

        $user = $this->userRepository->find($id);
        if (null === $user) {
            throw $this->createNotFoundException('Utilisateur introuvable.');
        }

        $this->userManagementService->updateRole($user, self::parseRole(self::decodeJsonBody($request)['role'] ?? null));

        return $this->json(self::toListItem($user));
    }

    #[Route('/api/users/{id}', name: 'api_users_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    #[OA\Delete(
        summary: 'Supprime un compte utilisateur',
        description: 'Réservé aux SuperAdmin. Un SuperAdmin ne peut pas supprimer son propre compte (voir la docblock de la classe).',
        tags: ['Users'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 204, description: 'Utilisateur supprimé'),
            new OA\Response(response: 401, description: 'Authentification JWT requise'),
            new OA\Response(response: 403, description: 'Réservé aux SuperAdmin, ou tentative de supprimer son propre compte'),
            new OA\Response(response: 404, description: 'Utilisateur introuvable'),
        ]
    )]
    public function delete(int $id): Response
    {
        $this->assertJwtAuthenticated();

        /** @var User $caller */
        $caller = $this->getUser();
        if ($caller->getId() === $id) {
            throw new AccessDeniedHttpException('Vous ne pouvez pas supprimer votre propre compte.');
        }

        $user = $this->userRepository->find($id);
        if (null === $user) {
            throw $this->createNotFoundException('Utilisateur introuvable.');
        }

        $this->userManagementService->deleteUser($user);

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    /**
     * @return array{id: int|null, name: string, email: string, role: string, createdAt: string}
     */
    private static function toListItem(User $user): array
    {
        return [
            'id' => $user->getId(),
            'name' => $user->getName(),
            'email' => $user->getEmail(),
            'role' => $user->getRole()->value,
            'createdAt' => $user->getCreatedAt()->format(\DATE_ATOM),
        ];
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: UserRole}
     */
    private static function parseCreatePayload(Request $request): array
    {
        $body = self::decodeJsonBody($request);

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

        $role = self::parseRole($body['role'] ?? null);

        return [$name, $email, $password, $role];
    }

    private static function parseRole(mixed $rawRole): UserRole
    {
        if (!\is_string($rawRole)) {
            throw new BadRequestHttpException('Le champ "role" est requis.');
        }

        $role = UserRole::tryFrom($rawRole);
        if (null === $role) {
            $accepted = implode(', ', array_map(static fn (UserRole $case) => $case->value, UserRole::cases()));
            throw new BadRequestHttpException(\sprintf('Rôle "%s" inconnu. Valeurs acceptées : %s.', $rawRole, $accepted));
        }

        return $role;
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeJsonBody(Request $request): array
    {
        $decoded = json_decode($request->getContent(), true);
        if (!\is_array($decoded)) {
            throw new BadRequestHttpException('Corps de requête JSON invalide.');
        }

        return $decoded;
    }

    private function assertJwtAuthenticated(): void
    {
        if (!$this->security->getToken() instanceof JWTPostAuthenticationToken) {
            throw new AccessDeniedHttpException('User management requires JWT authentication.');
        }
    }
}
