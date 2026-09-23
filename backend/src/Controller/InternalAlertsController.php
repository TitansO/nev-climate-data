<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\AlertEvaluationService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * C3.8 : déclenchement HTTP de "app:alerts:evaluate" pour le DAG Airflow
 * quotidien (`pipeline/dags/evaluation_alertes_dag.py`). Existe uniquement
 * parce que le conteneur `airflow` n'a aucun moyen d'exécuter une commande
 * Symfony directement : pas de socket Docker monté (contrairement à un vrai
 * "docker-outside-of-docker"), donc pas de `docker exec` possible depuis ce
 * conteneur - seul un appel réseau (même `nev-network` que `backend`, voir
 * docker-compose.yml) est réalisable, à l'image du patron déjà établi par
 * `ai/ingest.py` (`BACKEND_BASE_URL`) pour la lecture, ici étendu à un
 * déclenchement en écriture.
 *
 * PUBLIC_ACCESS côté `security.yaml` (aucune notion d'utilisateur JWT n'a de
 * sens pour un appel machine-à-machine) - la vérification réelle se fait ici
 * même, par comparaison à temps constant (`hash_equals`) d'un jeton partagé
 * (`INTERNAL_TRIGGER_TOKEN`), jamais transmis au navigateur, connu
 * uniquement de `backend` et `airflow` (voir leurs blocs `environment` dans
 * docker-compose.yml).
 */
final class InternalAlertsController extends AbstractController
{
    public function __construct(
        private readonly AlertEvaluationService $alertEvaluationService,
        private readonly string $internalTriggerToken,
    ) {
    }

    #[Route('/api/internal/alerts/evaluate', name: 'api_internal_alerts_evaluate', methods: ['POST'])]
    #[OA\Post(
        summary: 'Déclenche AlertEvaluationService (usage machine-à-machine uniquement, DAG Airflow)',
        description: 'Authentification par jeton partagé (en-tête `X-Internal-Token`), jamais par JWT/clé API - endpoint interne, non destiné à un client humain.',
        tags: ['Internal'],
        security: [],
        parameters: [new OA\Parameter(name: 'X-Internal-Token', in: 'header', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Évaluation terminée',
                content: new OA\JsonContent(properties: [new OA\Property(property: 'created', type: 'integer', example: 3)])
            ),
            new OA\Response(response: 401, description: 'Jeton manquant ou invalide'),
        ]
    )]
    public function evaluate(Request $request): JsonResponse
    {
        $provided = (string) $request->headers->get('X-Internal-Token', '');
        if ('' === $this->internalTriggerToken || !hash_equals($this->internalTriggerToken, $provided)) {
            throw new AccessDeniedHttpException('Invalid or missing internal trigger token.');
        }

        return $this->json(['created' => $this->alertEvaluationService->evaluate()]);
    }
}
