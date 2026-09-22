<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AlertRule;
use App\Entity\Enum\AlertRuleType;
use App\Entity\User;
use App\Repository\AlertRuleRepository;
use Doctrine\ORM\EntityManagerInterface;
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
 * C3.8 : gestion des AlertRule de l'utilisateur connecté - lues et
 * évaluées par App\Service\AlertEvaluationService ("app:alerts:evaluate").
 * Deliberately JWT-only, même raisonnement qu'App\Controller\
 * NotificationController : donnée de compte personnelle.
 */
final class AlertRuleController extends AbstractController
{
    public function __construct(
        private readonly AlertRuleRepository $alertRuleRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly Security $security,
    ) {
    }

    #[Route('/api/alert-rules', name: 'api_alert_rules_list', methods: ['GET'])]
    #[OA\Get(
        summary: 'Liste les règles d\'alerte de l\'utilisateur connecté',
        tags: ['Alert Rules'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Liste des règles',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(
                    properties: [
                        new OA\Property(property: 'id', type: 'integer', example: 1),
                        new OA\Property(property: 'ruleType', type: 'string', example: 'gap_critical'),
                        new OA\Property(property: 'active', type: 'boolean', example: true),
                        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
                    ]
                ))
            ),
            new OA\Response(response: 401, description: 'Authentification JWT requise'),
        ]
    )]
    public function list(): JsonResponse
    {
        $this->assertJwtAuthenticated();

        /** @var User $user */
        $user = $this->getUser();

        return $this->json(array_map(self::toListItem(...), $this->alertRuleRepository->findByUser($user)));
    }

    #[Route('/api/alert-rules', name: 'api_alert_rules_create', methods: ['POST'])]
    #[OA\Post(
        summary: 'Active une règle d\'alerte pour l\'utilisateur connecté',
        description: 'Idempotent par type : si une règle active de ce type existe déjà pour l\'utilisateur, elle est retournée telle quelle (200) plutôt que dupliquée.',
        tags: ['Alert Rules'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['ruleType'],
                properties: [
                    new OA\Property(property: 'ruleType', type: 'string', enum: ['gap_critical', 'opportunity_closing', 'score_degraded', 'abnormal_flow'], example: 'gap_critical'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Règle déjà active de ce type, retournée sans changement'),
            new OA\Response(response: 201, description: 'Règle créée'),
            new OA\Response(response: 400, description: '"ruleType" manquant ou invalide'),
            new OA\Response(response: 401, description: 'Authentification JWT requise'),
        ]
    )]
    public function create(Request $request): JsonResponse
    {
        $this->assertJwtAuthenticated();

        /** @var User $user */
        $user = $this->getUser();

        $decoded = json_decode($request->getContent(), true);
        $ruleType = self::parseRuleType(\is_array($decoded) ? ($decoded['ruleType'] ?? null) : null);

        foreach ($this->alertRuleRepository->findByUser($user) as $existing) {
            if ($existing->getRuleType() === $ruleType && $existing->isActive()) {
                return $this->json(self::toListItem($existing));
            }
        }

        $alertRule = new AlertRule($user, $ruleType);
        $this->entityManager->persist($alertRule);
        $this->entityManager->flush();

        return $this->json(self::toListItem($alertRule), Response::HTTP_CREATED);
    }

    private static function parseRuleType(mixed $raw): AlertRuleType
    {
        if (!\is_string($raw)) {
            throw new BadRequestHttpException('Le champ "ruleType" est requis.');
        }

        $ruleType = AlertRuleType::tryFrom($raw);
        if (null === $ruleType) {
            throw new BadRequestHttpException(\sprintf('Le champ "ruleType" doit être l\'un de : %s.', implode(', ', array_map(static fn (AlertRuleType $case) => $case->value, AlertRuleType::cases()))));
        }

        return $ruleType;
    }

    /**
     * @return array{id: int|null, ruleType: string, active: bool, createdAt: string}
     */
    private static function toListItem(AlertRule $alertRule): array
    {
        return [
            'id' => $alertRule->getId(),
            'ruleType' => $alertRule->getRuleType()->value,
            'active' => $alertRule->isActive(),
            'createdAt' => $alertRule->getCreatedAt()->format(\DATE_ATOM),
        ];
    }

    private function assertJwtAuthenticated(): void
    {
        if (!$this->security->getToken() instanceof JWTPostAuthenticationToken) {
            throw new AccessDeniedHttpException('Alert rule management requires JWT authentication.');
        }
    }
}
