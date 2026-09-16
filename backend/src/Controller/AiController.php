<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Security\AiAskQuotaPolicy;
use Lexik\Bundle\JWTAuthenticationBundle\Security\Authenticator\Token\JWTPostAuthenticationToken;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * C2.1 : proxy serveur vers le service `ai/` (F5, FastAPI + RAG pgvector) -
 * jamais d'appel direct navigateur -> Gemini, jamais de clé Gemini exposée
 * côté client (voir docker-compose.yml : `ai` n'a aucun port publié). JWT
 * obligatoire (hérite du catch-all `IS_AUTHENTICATED_FULLY` de
 * security.yaml, aucune règle `access_control` dédiée nécessaire) + quota
 * quotidien par rôle (AiAskQuotaPolicy) puisque chaque appel a un coût réel
 * (Gemini), contrairement au reste de `/api/*`.
 *
 * Toute panne d'obtenir une réponse exploitable du service `ai` (connexion
 * refusée, timeout, erreur 5xx interne, JSON invalide) devient un 503 côté
 * client - jamais un 200 `{available: false}` : cette forme-là reste
 * réservée au cas où le service `ai` a bien répondu mais n'a trouvé aucun
 * contexte pertinent (voir `ai/rag.py::answer()`), une absence de donnée
 * légitime, pas une panne.
 */
final class AiController extends AbstractController
{
    private const MIN_QUESTION_LENGTH = 3;
    private const MAX_QUESTION_LENGTH = 2000;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly AiAskQuotaPolicy $quotaPolicy,
        private readonly Security $security,
        private readonly string $aiServiceUrl,
    ) {
    }

    #[Route('/api/ai/ask', name: 'api_ai_ask', methods: ['POST'])]
    #[OA\Post(
        summary: 'Pose une question à l\'assistant IA (RAG sur le corpus interne)',
        description: 'Garde-fou : l\'assistant répond uniquement à partir du contexte documentaire/données récupéré (jamais de connaissance générale du modèle) et cite ses sources. `available=false` quand aucun contexte pertinent n\'a été trouvé - pas une erreur.',
        tags: ['AI Copilot'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['question'],
                properties: [new OA\Property(property: 'question', type: 'string', example: 'Quel est le montant total des financements climat pour le Sénégal ?')]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Réponse générée (ou absence de contexte pertinent)',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'available', type: 'boolean'),
                    new OA\Property(property: 'answer', type: 'string', nullable: true),
                    new OA\Property(property: 'sources', type: 'array', items: new OA\Items(type: 'string')),
                ])
            ),
            new OA\Response(response: 400, description: 'Question manquante ou invalide'),
            new OA\Response(response: 401, description: 'Authentification JWT requise'),
            new OA\Response(response: 429, description: 'Quota quotidien de l\'assistant IA dépassé pour ce rôle'),
            new OA\Response(response: 503, description: 'Service IA temporairement injoignable'),
        ]
    )]
    public function ask(Request $request): JsonResponse
    {
        $this->assertJwtAuthenticated();

        /** @var User $user */
        $user = $this->getUser();
        $this->quotaPolicy->consume($user);

        $question = self::parseQuestion($request);

        try {
            $response = $this->httpClient->request('POST', rtrim($this->aiServiceUrl, '/').'/ai/ask', [
                'json' => ['question' => $question],
                'timeout' => 30,
            ]);
            $body = $response->toArray();
        } catch (HttpClientExceptionInterface $exception) {
            throw new ServiceUnavailableHttpException(null, 'Service IA temporairement indisponible.', $exception);
        }

        return $this->json($body);
    }

    private static function parseQuestion(Request $request): string
    {
        $decoded = json_decode($request->getContent(), true);
        if (!\is_array($decoded)) {
            throw new BadRequestHttpException('Corps de requête JSON invalide.');
        }

        $question = trim((string) ($decoded['question'] ?? ''));
        $length = mb_strlen($question);
        if ($length < self::MIN_QUESTION_LENGTH) {
            throw new BadRequestHttpException(\sprintf('Le champ "question" doit contenir au moins %d caractères.', self::MIN_QUESTION_LENGTH));
        }
        if ($length > self::MAX_QUESTION_LENGTH) {
            throw new BadRequestHttpException(\sprintf('Le champ "question" doit contenir au plus %d caractères.', self::MAX_QUESTION_LENGTH));
        }

        return $question;
    }

    private function assertJwtAuthenticated(): void
    {
        if (!$this->security->getToken() instanceof JWTPostAuthenticationToken) {
            throw new AccessDeniedHttpException('AI Copilot requires JWT authentication.');
        }
    }
}
