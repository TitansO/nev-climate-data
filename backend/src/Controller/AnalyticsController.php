<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\CountryDistributionCriteria;
use App\Dto\FundingFlowCriteria;
use App\Entity\Enum\FinancingTrendsGroupBy;
use App\Service\AnalyticsService;
use App\Service\FundingFlowService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Server-side aggregates for the analytics charts on the frontend's
 * visualizations page (A2.5, extended with country-distribution for the
 * funding map) and the Hero stats strip on index.html (A2.7).
 * Deliberately PUBLIC_ACCESS, like GET /api/funding (see security.yaml):
 * neither page has a login requirement, so there is no reason for the data
 * feeding them to require one either. Every endpoint but
 * country-distribution takes no query parameters (see the A2.5/A2.6
 * implementation report) - country-distribution gained optional
 * sector/instrument/year/fundingType filters in C1.2, for the Dashboard's
 * (C1.1) and Visualisations' map layer selector. Every response is served
 * through a 15-minute Redis cache (App\Service\AnalyticsService).
 */
final class AnalyticsController extends AbstractController
{
    public function __construct(
        private readonly AnalyticsService $analyticsService,
        private readonly FundingFlowService $fundingFlowService,
    ) {
    }

    #[Route('/api/analytics/hero-stats', name: 'api_analytics_hero_stats', methods: ['GET'])]
    #[OA\Get(
        summary: 'Statistiques agrégées affichées dans le Hero de la page d\'accueil (mis en cache 15 min)',
        description: 'countriesCovered/activeSources comptent les pays/sources ayant au moins un enregistrement Funding (pas le total des tables de référence) ; sectorsTracked est le nombre total de secteurs suivis par la plateforme ; fundingRecords est le nombre total d\'enregistrements Funding. Réponse servie depuis un cache Redis dédié, TTL 900 secondes (même pool que les autres agrégats A2.5).',
        tags: ['Analytics'],
        security: [],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Statistiques du Hero',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'countriesCovered', type: 'integer', example: 54),
                        new OA\Property(property: 'sectorsTracked', type: 'integer', example: 5),
                        new OA\Property(property: 'fundingRecords', type: 'integer', example: 1080),
                        new OA\Property(property: 'activeSources', type: 'integer', example: 4),
                    ]
                )
            ),
        ]
    )]
    public function heroStats(): JsonResponse
    {
        return $this->json($this->analyticsService->getHeroStats());
    }

    #[Route('/api/analytics/financing-trends', name: 'api_analytics_financing_trends', methods: ['GET'])]
    #[OA\Get(
        summary: 'Tendances de financement par année, éventuellement ventilées par dimension (mis en cache 15 min)',
        description: 'Sans paramètre : montants agrégés (SUM) par année, ventilés par type de financement (public/private/multilateral) - comportement inchangé depuis A2.5. C1.6 : avec `groupBy`, `data` porte à la place un objet `groups` générique, ventilé par secteur/instrument/pays/type selon la valeur choisie. `movingAverageTotal` (nouveau, C1.6) est toujours présent : moyenne mobile du total sur 3 ans (les 1-2 premières années de la série moyennent sur moins de 3 points, jamais une valeur fabriquée). Ordre chronologique stable. Réponse servie depuis un cache Redis dédié par combinaison de paramètres, TTL 900 secondes.',
        tags: ['Analytics'],
        security: [],
        parameters: [
            new OA\Parameter(name: 'groupBy', in: 'query', required: false, description: 'Dimension de ventilation (par défaut : type de financement, forme historique)', schema: new OA\Schema(type: 'string', enum: ['sector', 'instrument', 'country', 'fundingType'])),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Série chronologique des montants agrégés',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(
                            properties: [
                                new OA\Property(property: 'period', type: 'integer', example: 2025),
                                new OA\Property(property: 'public', type: 'number', format: 'float', example: 1200000),
                                new OA\Property(property: 'private', type: 'number', format: 'float', example: 800000),
                                new OA\Property(property: 'multilateral', type: 'number', format: 'float', example: 950000),
                                new OA\Property(property: 'total', type: 'number', format: 'float', example: 2950000),
                            ]
                        )),
                        new OA\Property(property: 'movingAverageTotal', type: 'array', items: new OA\Items(
                            properties: [
                                new OA\Property(property: 'period', type: 'integer', example: 2025),
                                new OA\Property(property: 'value', type: 'number', format: 'float', example: 2450000),
                            ]
                        )),
                    ]
                )
            ),
            new OA\Response(response: 400, description: '"groupBy" a une valeur hors de l\'énumération connue'),
        ]
    )]
    public function financingTrends(Request $request): JsonResponse
    {
        $groupByRaw = $request->query->get('groupBy');
        $groupBy = null;
        if (null !== $groupByRaw && '' !== $groupByRaw) {
            $groupBy = FinancingTrendsGroupBy::tryFrom((string) $groupByRaw);
            if (null === $groupBy) {
                $allowed = implode(', ', array_map(static fn (FinancingTrendsGroupBy $case) => $case->value, FinancingTrendsGroupBy::cases()));
                throw new BadRequestHttpException(\sprintf('Invalid value for parameter "groupBy": must be one of %s.', $allowed));
            }
        }

        return $this->json($this->analyticsService->getFinancingTrends($groupBy));
    }

    #[Route('/api/analytics/sector-distribution', name: 'api_analytics_sector_distribution', methods: ['GET'])]
    #[OA\Get(
        summary: 'Répartition des financements par secteur (mis en cache 15 min)',
        description: 'Montants agrégés (SUM) par secteur réel, triés du plus grand au plus petit (ordre déterministe : égalité départagée par id de secteur). Le pourcentage est calculé côté serveur sur le total agrégé. Réponse servie depuis un cache Redis dédié, TTL 900 secondes.',
        tags: ['Analytics'],
        security: [],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Répartition sectorielle',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(
                            properties: [
                                new OA\Property(property: 'sector', type: 'string', example: 'Renewable Energy'),
                                new OA\Property(property: 'amount', type: 'number', format: 'float', example: 2500000),
                                new OA\Property(property: 'percentage', type: 'number', format: 'float', example: 32.5),
                            ]
                        )),
                    ]
                )
            ),
        ]
    )]
    public function sectorDistribution(): JsonResponse
    {
        return $this->json(['data' => $this->analyticsService->getSectorDistribution()]);
    }

    #[Route('/api/analytics/instrument-distribution', name: 'api_analytics_instrument_distribution', methods: ['GET'])]
    #[OA\Get(
        summary: 'Répartition des financements par instrument financier + croisements secteur/pays (mis en cache 15 min)',
        description: 'C1.7 : montants agrégés (SUM) par instrument financier (F1), pour explorer la part de concessionnalité des financements (don vs prêt vs garantie, etc). `bySector`/`byCountry` sont deux croisements instrument x secteur et instrument x pays (montants bruts, non pourcentés - une cellule de croisement n\'a pas de total naturel à rapporter à un pourcentage). Réponse servie depuis un cache Redis dédié, TTL 900 secondes.',
        tags: ['Analytics'],
        security: [],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Répartition par instrument + croisements',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(
                            properties: [
                                new OA\Property(property: 'instrument', type: 'string', example: 'grant'),
                                new OA\Property(property: 'amount', type: 'number', format: 'float', example: 1500000),
                                new OA\Property(property: 'percentage', type: 'number', format: 'float', example: 42.3),
                            ]
                        )),
                        new OA\Property(property: 'bySector', type: 'array', items: new OA\Items(
                            properties: [
                                new OA\Property(property: 'instrument', type: 'string', example: 'grant'),
                                new OA\Property(property: 'sector', type: 'string', example: 'Agriculture'),
                                new OA\Property(property: 'amount', type: 'number', format: 'float', example: 500000),
                            ]
                        )),
                        new OA\Property(property: 'byCountry', type: 'array', items: new OA\Items(
                            properties: [
                                new OA\Property(property: 'instrument', type: 'string', example: 'grant'),
                                new OA\Property(property: 'isoCode', type: 'string', example: 'SEN'),
                                new OA\Property(property: 'country', type: 'string', example: 'Senegal'),
                                new OA\Property(property: 'amount', type: 'number', format: 'float', example: 300000),
                            ]
                        )),
                    ]
                )
            ),
        ]
    )]
    public function instrumentDistribution(): JsonResponse
    {
        return $this->json($this->analyticsService->getInstrumentDistribution());
    }

    #[Route('/api/analytics/funding-flows', name: 'api_analytics_funding_flows', methods: ['GET'])]
    #[OA\Get(
        summary: 'Flux de financement bailleur -> pays -> secteur, pour un diagramme de Sankey (mis en cache 15 min)',
        description: 'C1.5 : nœuds + liens agrégés en SQL, filtrables par instrument et année. Chaque "couche" (bailleur, pays, secteur) est plafonnée aux `topN` premiers par montant total ; le reste est regroupé dans un unique nœud "Autres" par couche (jamais un nœud "Autres" vide fabriqué s\'il n\'y a rien à y mettre). Un pays garde la même identité (lui-même, ou "Autres pays") des deux côtés du diagramme, pour ne jamais scinder un même pays réel en deux nœuds déconnectés.',
        tags: ['Analytics'],
        security: [],
        parameters: [
            new OA\Parameter(name: 'instrument', in: 'query', required: false, description: 'Instrument financier (F1)', schema: new OA\Schema(type: 'string', enum: ['grant', 'loan_concessional', 'loan_non_concessional', 'guarantee', 'equity', 'results_based', 'unknown'])),
            new OA\Parameter(name: 'year', in: 'query', required: false, description: 'Année du financement', schema: new OA\Schema(type: 'integer', example: 2025)),
            new OA\Parameter(name: 'topN', in: 'query', required: false, description: 'Nombre de nœuds conservés par couche avant regroupement dans "Autres" (défaut 8, de 1 à 25)', schema: new OA\Schema(type: 'integer', example: 8)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Nœuds et liens du diagramme',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'nodes', type: 'array', items: new OA\Items(
                            properties: [
                                new OA\Property(property: 'id', type: 'string', example: 'source:12'),
                                new OA\Property(property: 'label', type: 'string', example: 'World Bank Data API'),
                                new OA\Property(property: 'layer', type: 'string', enum: ['source', 'country', 'sector'], example: 'source'),
                            ]
                        )),
                        new OA\Property(property: 'links', type: 'array', items: new OA\Items(
                            properties: [
                                new OA\Property(property: 'source', type: 'string', example: 'source:12'),
                                new OA\Property(property: 'target', type: 'string', example: 'country:SEN'),
                                new OA\Property(property: 'value', type: 'number', format: 'float', example: 4500000),
                            ]
                        )),
                    ]
                )
            ),
            new OA\Response(response: 400, description: '"instrument" hors énumération, "year" non entier, ou "topN" hors de [1, 25]'),
        ]
    )]
    public function fundingFlows(Request $request): JsonResponse
    {
        $criteria = FundingFlowCriteria::fromQuery($request->query);

        return $this->json($this->fundingFlowService->getFlows($criteria));
    }

    #[Route('/api/analytics/country-distribution', name: 'api_analytics_country_distribution', methods: ['GET'])]
    #[OA\Get(
        summary: 'Répartition des financements par pays (mis en cache 15 min)',
        description: 'Montants agrégés (SUM) par pays réel, triés du plus grand au plus petit (ordre déterministe : égalité départagée par id de pays). Le pourcentage est calculé côté serveur sur le total agrégé (déjà filtré par les paramètres ci-dessous, s\'il y en a). C1.2 : filtres optionnels et cumulables par secteur/instrument/année/type de financement - une requête sans aucun paramètre se comporte exactement comme avant C1.2 (même agrégat, même clé de cache). Chaque combinaison de filtres a sa propre entrée de cache Redis dédiée, TTL 900 secondes.',
        tags: ['Analytics'],
        security: [],
        parameters: [
            new OA\Parameter(name: 'sector', in: 'query', required: false, description: 'Identifiant du secteur (Sector.id - voir la répartition GET /api/analytics/sector-distribution pour les noms, ou GET /api/funding pour des lignes portant leur secteur)', schema: new OA\Schema(type: 'integer', example: 3)),
            new OA\Parameter(name: 'instrument', in: 'query', required: false, description: 'Instrument financier (F1)', schema: new OA\Schema(type: 'string', enum: ['grant', 'loan_concessional', 'loan_non_concessional', 'guarantee', 'equity', 'results_based', 'unknown'])),
            new OA\Parameter(name: 'year', in: 'query', required: false, description: 'Année du financement', schema: new OA\Schema(type: 'integer', example: 2025)),
            new OA\Parameter(name: 'fundingType', in: 'query', required: false, description: 'Nature du bailleur', schema: new OA\Schema(type: 'string', enum: ['public', 'private', 'multilateral'])),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Répartition par pays',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(
                            properties: [
                                new OA\Property(property: 'isoCode', type: 'string', example: 'SEN'),
                                new OA\Property(property: 'country', type: 'string', example: 'Senegal'),
                                new OA\Property(property: 'amount', type: 'number', format: 'float', example: 2500000),
                                new OA\Property(property: 'percentage', type: 'number', format: 'float', example: 8.2),
                            ]
                        )),
                    ]
                )
            ),
            new OA\Response(response: 400, description: 'Un paramètre a une valeur invalide (ex : "instrument" hors de l\'énumération connue)'),
        ]
    )]
    public function countryDistribution(Request $request): JsonResponse
    {
        $criteria = CountryDistributionCriteria::fromQuery($request->query);

        return $this->json(['data' => $this->analyticsService->getCountryDistribution($criteria)]);
    }

    #[Route('/api/analytics/co2-reduction', name: 'api_analytics_co2_reduction', methods: ['GET'])]
    #[OA\Get(
        summary: 'Variation CO2 d\'une année sur l\'autre (mis en cache 15 min)',
        description: 'Variation totale des émissions entre les deux années les plus récentes disposant de données (B1.4/B3.2), calculée uniquement sur les pays ayant une donnée les deux années (jamais un pays manquant traité comme zéro). `reductionMt` positif = réduction réelle, négatif = hausse réelle - jamais tronqué. Retourne `available: false` si moins de 2 années de données existent, ou aucun pays commun entre les deux années les plus récentes. Réponse servie depuis un cache Redis dédié, TTL 900 secondes.',
        tags: ['Analytics'],
        security: [],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Variation CO2 disponible ou indisponibilité explicite',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'available', type: 'boolean', example: true),
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            nullable: true,
                            properties: [
                                new OA\Property(property: 'reductionMt', type: 'number', format: 'float', example: 12.7),
                                new OA\Property(property: 'latestYear', type: 'integer', example: 2025),
                                new OA\Property(property: 'previousYear', type: 'integer', example: 2024),
                                new OA\Property(property: 'countriesCompared', type: 'integer', example: 28),
                            ]
                        ),
                        new OA\Property(property: 'reason', type: 'string', nullable: true, example: null),
                    ]
                )
            ),
        ]
    )]
    public function co2Reduction(): JsonResponse
    {
        return $this->json($this->analyticsService->getCo2Reduction());
    }

    #[Route('/api/analytics/data-freshness', name: 'api_analytics_data_freshness', methods: ['GET'])]
    #[OA\Get(
        summary: 'Fraîcheur des données par source (mis en cache 15 min)',
        description: 'Date de dernière collecte réelle connue pour chaque source (financements + émissions), triée de la plus en retard à la plus fraîche. `staleSourceCount` compte les sources sans nouvelle collecte depuis plus de 100 jours (un peu au-delà du cycle trimestriel de tous les connecteurs Volet B). `oldestCollectionAt` est `null` s\'il n\'existe aucune donnée réelle du tout. Réponse servie depuis un cache Redis dédié, TTL 900 secondes.',
        tags: ['Analytics'],
        security: [],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Fraîcheur des données par source',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'sources', type: 'array', items: new OA\Items(
                            properties: [
                                new OA\Property(property: 'name', type: 'string', example: 'World Bank Data API'),
                                new OA\Property(property: 'lastCollectedAt', type: 'string', format: 'date', example: '2026-07-01'),
                                new OA\Property(property: 'daysSinceCollection', type: 'integer', example: 42),
                            ]
                        )),
                        new OA\Property(property: 'oldestCollectionAt', type: 'string', format: 'date', nullable: true, example: '2026-04-01'),
                        new OA\Property(property: 'staleSourceCount', type: 'integer', example: 0),
                    ]
                )
            ),
        ]
    )]
    public function dataFreshness(): JsonResponse
    {
        return $this->json($this->analyticsService->getDataFreshness());
    }
}
