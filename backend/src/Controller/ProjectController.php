<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Enum\ProjectDecisionOutcome;
use App\Entity\Enum\ProjectExecutionStatus;
use App\Entity\Enum\ProjectPipelineStage;
use App\Entity\Project;
use App\Entity\User;
use App\Security\ClassifyQuotaPolicy;
use App\Service\BankabilityScoreService;
use App\Service\ClassifyService;
use App\Service\InvestmentRiskScoreService;
use App\Service\ProjectMatchingService;
use App\Service\ProjectService;
use App\Service\Scoring\ScoreService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * C2.2 (Matching Engine) + C2.3 (Project Pipeline / Kanban) - toutes deux
 * portent sur `Project` (F4), d'où un seul contrôleur. Jamais PUBLIC_ACCESS
 * (hérite du catch-all IS_AUTHENTICATED_FULLY de security.yaml, aucune
 * règle access_control dédiée) - contrairement à `Opportunity`, un
 * `Project` est une donnée de travail interne (porteur, montant recherché,
 * stade), pas une information publique comme un appel à projets ouvert.
 *
 * Décision produit (audit post-C3 2026-10-02, P-02) : PATCH /{id} et
 * PATCH /{id}/classify n'imposent aucun rôle au-delà de IS_AUTHENTICATED_FULLY
 * - n'importe quel compte authentifié, quel que soit son rôle, peut faire
 * avancer le pipeline ou reclasser n'importe quel projet. Confirmé
 * volontaire, pas un oubli : `Project` (voir son entité) ne porte aucune
 * notion de propriétaire (pas de champ user/createdBy/submittedBy), et
 * aucun endpoint POST /api/projects n'existe - seul le pipeline de collecte
 * (Volet B) crée des projets, jamais un utilisateur final. Le pipeline est
 * donc un espace de travail partagé entre toute l'équipe par conception,
 * pas une ressource personnelle comme AlertRule/ApiKey/Notification
 * (celles-ci sont bien scopées par propriétaire, voir findOneForUser()
 * dans leurs repositories respectifs) - une restriction par rôle serait un
 * changement de comportement produit, pas une correction de sécurité.
 */
final class ProjectController extends AbstractController
{
    public function __construct(
        private readonly ProjectService $projectService,
        private readonly ProjectMatchingService $matchingService,
        private readonly BankabilityScoreService $bankabilityScoreService,
        private readonly InvestmentRiskScoreService $investmentRiskScoreService,
        private readonly ClassifyService $classifyService,
        private readonly ClassifyQuotaPolicy $classifyQuotaPolicy,
    ) {
    }

    #[Route('/api/projects', name: 'api_projects_list', methods: ['GET'])]
    #[OA\Get(
        summary: 'Liste les projets à la recherche de financement, filtrables par stade',
        tags: ['Projects'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'stage', in: 'query', required: false, description: 'Stade du pipeline', schema: new OA\Schema(type: 'string', enum: ['idea', 'scoping', 'structuring', 'fundraising', 'decision', 'execution'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Liste des projets, plus récemment mis à jour en premier'),
            new OA\Response(response: 400, description: '"stage" a une valeur hors de l\'énumération connue'),
            new OA\Response(response: 401, description: 'Authentification JWT requise'),
        ]
    )]
    public function list(Request $request): JsonResponse
    {
        return $this->json(['data' => $this->projectService->list(self::parseStage($request->query->get('stage')))]);
    }

    #[Route('/api/projects/{id}', name: 'api_projects_update_stage', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    #[OA\Patch(
        summary: 'Déplace un projet vers un autre stade du pipeline (Kanban)',
        description: '"decisionOutcome" (financed/rejected) et "executionStatus" (in_progress/closed) sont des étiquettes affichées sur la carte, pas des colonnes séparées - voir App\\Entity\\Enum\\ProjectPipelineStage (fusion 2026-09-28 des anciens stades "financed"/"rejected" en un seul stade "decision"). Absents du corps, ils restent inchangés ; envoyés à `null`, ils sont explicitement effacés.',
        tags: ['Projects'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(required: ['stage'], properties: [
                new OA\Property(property: 'stage', type: 'string', enum: ['idea', 'scoping', 'structuring', 'fundraising', 'decision', 'execution']),
                new OA\Property(property: 'decisionOutcome', type: 'string', nullable: true, enum: ['financed', 'rejected']),
                new OA\Property(property: 'executionStatus', type: 'string', nullable: true, enum: ['in_progress', 'closed']),
            ])
        ),
        responses: [
            new OA\Response(response: 200, description: 'Projet mis à jour'),
            new OA\Response(response: 400, description: 'Corps invalide, ou une valeur hors énumération'),
            new OA\Response(response: 401, description: 'Authentification JWT requise'),
            new OA\Response(response: 404, description: 'Projet introuvable'),
        ]
    )]
    public function updateStage(int $id, Request $request): JsonResponse
    {
        $project = $this->requireProject($id);

        $decoded = json_decode($request->getContent(), true);
        if (!\is_array($decoded)) {
            throw new BadRequestHttpException('Corps de requête JSON invalide.');
        }

        $stage = self::parseStage($decoded['stage'] ?? null);
        if (null === $stage) {
            throw new BadRequestHttpException('Le champ "stage" est requis.');
        }

        $decisionOutcomeProvided = \array_key_exists('decisionOutcome', $decoded);
        $decisionOutcome = $decisionOutcomeProvided ? self::parseDecisionOutcome($decoded['decisionOutcome']) : null;

        $executionStatusProvided = \array_key_exists('executionStatus', $decoded);
        $executionStatus = $executionStatusProvided ? self::parseExecutionStatus($decoded['executionStatus']) : null;

        $this->projectService->updateStage($project, $stage, $decisionOutcomeProvided, $decisionOutcome, $executionStatusProvided, $executionStatus);

        return $this->json(ProjectService::toArray($project));
    }

    #[Route('/api/projects/{id}/matches', name: 'api_projects_matches', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[OA\Get(
        summary: 'Classe les opportunités de financement les plus pertinentes pour ce projet',
        description: 'Score pondéré transparent (secteur 0,35 ; pays 0,25 ; instrument 0,2 ; montant 0,1 ; fenêtre temporelle 0,1), jamais un modèle entraîné - voir App\\Service\\ProjectMatchingService. `breakdown` détaille chaque composante pour que le classement reste auditable.',
        tags: ['Projects'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'limit', in: 'query', required: false, description: 'Nombre maximum de résultats (défaut 10, plafond 25)', schema: new OA\Schema(type: 'integer', default: 10, maximum: 25)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Opportunités classées par score décroissant',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(
                        properties: [
                            new OA\Property(property: 'opportunityId', type: 'integer'),
                            new OA\Property(property: 'opportunityTitle', type: 'string'),
                            new OA\Property(property: 'sourceName', type: 'string'),
                            new OA\Property(property: 'opportunityStatus', type: 'string', enum: ['open', 'upcoming', 'closed']),
                            new OA\Property(property: 'score', type: 'number', format: 'float', example: 0.775),
                            new OA\Property(property: 'breakdown', properties: [
                                new OA\Property(property: 'sector', type: 'number'),
                                new OA\Property(property: 'country', type: 'number'),
                                new OA\Property(property: 'instrument', type: 'number'),
                                new OA\Property(property: 'amount', type: 'number'),
                                new OA\Property(property: 'window', type: 'number'),
                            ], type: 'object'),
                        ]
                    )),
                ])
            ),
            new OA\Response(response: 400, description: '"limit" invalide'),
            new OA\Response(response: 401, description: 'Authentification JWT requise'),
            new OA\Response(response: 404, description: 'Projet introuvable'),
        ]
    )]
    public function matches(int $id, Request $request): JsonResponse
    {
        $project = $this->requireProject($id);
        $limit = self::parseLimit($request->query->get('limit'));

        return $this->json(['data' => $this->matchingService->matchesForProject($project, $limit)]);
    }

    #[Route('/api/projects/{id}/scores/bankability', name: 'api_projects_bankability_score', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[OA\Get(
        summary: 'Score de bancabilité du projet (0-100, grade A-E)',
        description: '5 indicateurs pondérés transparents (complétude 0,25 ; porteur identifié 0,1 ; cohérence montant/secteur vs comparables réels 0,2 ; instrument réaliste pour le pays 0,15 ; risque pays inversé 0,3, réutilise C2.8) - jamais un modèle entraîné. `breakdown` détaille chaque composante. Recalculé à chaque appel (pas de mise en cache : App\\Repository\\ScoreRepository ne conserve qu\'une seule ligne courante par projet, toujours rafraîchie).',
        tags: ['Projects'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Score de bancabilité',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'subjectType', type: 'string', example: 'project'),
                    new OA\Property(property: 'subjectId', type: 'integer'),
                    new OA\Property(property: 'scoreType', type: 'string', example: 'bankability'),
                    new OA\Property(property: 'value', type: 'number', format: 'float', example: 72.5),
                    new OA\Property(property: 'grade', type: 'string', enum: ['A', 'B', 'C', 'D', 'E']),
                    new OA\Property(property: 'breakdown', type: 'array', items: new OA\Items(
                        properties: [
                            new OA\Property(property: 'code', type: 'string'),
                            new OA\Property(property: 'value', type: 'number'),
                            new OA\Property(property: 'weight', type: 'number'),
                            new OA\Property(property: 'explanation', type: 'string'),
                        ]
                    )),
                    new OA\Property(property: 'computedAt', type: 'string', format: 'date-time'),
                ])
            ),
            new OA\Response(response: 401, description: 'Authentification JWT requise'),
            new OA\Response(response: 404, description: 'Projet introuvable'),
        ]
    )]
    public function bankabilityScore(int $id): JsonResponse
    {
        $project = $this->requireProject($id);

        return $this->json(ScoreService::toArray($this->bankabilityScoreService->scoreFor($project)));
    }

    #[Route('/api/projects/{id}/scores/investment-risk', name: 'api_projects_investment_risk_score', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[OA\Get(
        summary: 'Score de risque d\'investissement du projet (0-100, grade A-E - A = le plus sûr)',
        description: 'Risque pays x risque secteur x risque projet (plan), poids égaux : risque pays (App\\Service\\Scoring\\CountryRiskCalculator, réutilisé depuis C2.4), risque secteur (App\\Service\\Scoring\\SectorRiskCalculator - maturité, dépendance à un instrument), risque projet (proxy par stade du pipeline). Grade orienté "plus haut = plus sûr".',
        tags: ['Projects'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Score de risque d\'investissement'),
            new OA\Response(response: 401, description: 'Authentification JWT requise'),
            new OA\Response(response: 404, description: 'Projet introuvable'),
        ]
    )]
    public function investmentRiskScore(int $id): JsonResponse
    {
        $project = $this->requireProject($id);

        return $this->json(ScoreService::toArray($this->investmentRiskScoreService->scoreForProject($project)));
    }

    #[Route('/api/projects/{id}/classify', name: 'api_projects_classify', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    #[OA\Patch(
        summary: 'Classe ce projet selon la taxonomie verte simplifiée et enregistre le résultat (C2.7)',
        description: 'Appelle POST /api/classify avec le titre/la description/le secteur du projet, puis enregistre la classe obtenue sur Project::$taxonomyClass. Répété sur un projet déjà classé, ce n\'appelle Gemini que si le titre/la description/le secteur ont changé depuis (cache par hash côté service `ai/`).',
        tags: ['Projects'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Projet mis à jour, avec sa nouvelle classe de taxonomie'),
            new OA\Response(response: 401, description: 'Authentification JWT requise'),
            new OA\Response(response: 404, description: 'Projet introuvable'),
            new OA\Response(response: 429, description: 'Quota quotidien de classification dépassé pour ce rôle'),
            new OA\Response(response: 503, description: 'Service IA temporairement injoignable'),
        ]
    )]
    public function classifyProject(int $id): JsonResponse
    {
        $project = $this->requireProject($id);

        /** @var User $user */
        $user = $this->getUser();
        $this->classifyQuotaPolicy->consume($user);

        $result = $this->classifyService->classify($project->getTitle(), $project->getDescription(), $project->getSector()->getName());
        $this->projectService->setTaxonomyClass($project, $result['taxonomyClass']);

        return $this->json(ProjectService::toArray($project));
    }

    private function requireProject(int $id): Project
    {
        $project = $this->projectService->find($id);
        if (null === $project) {
            throw $this->createNotFoundException('Project not found.');
        }

        return $project;
    }

    private static function parseStage(mixed $raw): ?ProjectPipelineStage
    {
        if (null === $raw || '' === $raw) {
            return null;
        }
        if (!\is_string($raw)) {
            throw new BadRequestHttpException('Invalid value for parameter "stage": must be a string.');
        }

        $stage = ProjectPipelineStage::tryFrom($raw);
        if (null === $stage) {
            $allowed = implode(', ', array_map(static fn (ProjectPipelineStage $case) => $case->value, ProjectPipelineStage::cases()));
            throw new BadRequestHttpException(\sprintf('Invalid value for parameter "stage": must be one of %s.', $allowed));
        }

        return $stage;
    }

    private static function parseDecisionOutcome(mixed $raw): ?ProjectDecisionOutcome
    {
        if (null === $raw) {
            return null;
        }
        if (!\is_string($raw)) {
            throw new BadRequestHttpException('Invalid value for parameter "decisionOutcome": must be a string.');
        }

        $outcome = ProjectDecisionOutcome::tryFrom($raw);
        if (null === $outcome) {
            $allowed = implode(', ', array_map(static fn (ProjectDecisionOutcome $case) => $case->value, ProjectDecisionOutcome::cases()));
            throw new BadRequestHttpException(\sprintf('Invalid value for parameter "decisionOutcome": must be one of %s.', $allowed));
        }

        return $outcome;
    }

    private static function parseExecutionStatus(mixed $raw): ?ProjectExecutionStatus
    {
        if (null === $raw) {
            return null;
        }
        if (!\is_string($raw)) {
            throw new BadRequestHttpException('Invalid value for parameter "executionStatus": must be a string.');
        }

        $status = ProjectExecutionStatus::tryFrom($raw);
        if (null === $status) {
            $allowed = implode(', ', array_map(static fn (ProjectExecutionStatus $case) => $case->value, ProjectExecutionStatus::cases()));
            throw new BadRequestHttpException(\sprintf('Invalid value for parameter "executionStatus": must be one of %s.', $allowed));
        }

        return $status;
    }

    private static function parseLimit(?string $raw): int
    {
        if (null === $raw || '' === $raw) {
            return ProjectMatchingService::DEFAULT_LIMIT;
        }

        if (!is_numeric($raw) || (string) (int) $raw !== (string) $raw || (int) $raw < 1) {
            throw new BadRequestHttpException('Invalid value for parameter "limit": must be a positive integer.');
        }

        return min((int) $raw, ProjectMatchingService::MAX_LIMIT);
    }
}
