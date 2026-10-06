<?php

declare(strict_types=1);

namespace App\Service\Scoring;

use App\Entity\Enum\ScoreSubjectType;
use App\Entity\Score;
use App\Repository\ScoreRepository;

/**
 * F3 (fondation du sprint C1-C3) : moteur de scoring générique - combine
 * une liste d'indicateurs (App\Service\Scoring\ScoreIndicatorInterface) en
 * un score 0-100 + grade A-E, persisté via App\Repository\ScoreRepository
 * (F2). Jamais de modèle entraîné : "l'explicabilité d'un score prime sur
 * sa sophistication" (plan §1) - une simple somme pondérée d'indicateurs
 * normalisés, chacun expliqué dans `breakdown`.
 *
 * Un score consommateur (BankabilityScoreService, NdcAlignmentScoreService,
 * ...) construit sa propre liste d'indicateurs pour son `$scoreType` et
 * appelle compute() - ScoreService lui-même ne connaît aucun indicateur
 * concret, seulement l'interface.
 */
final class ScoreService
{
    public function __construct(
        private readonly ScoreRepository $scoreRepository,
    ) {
    }

    /**
     * @param list<ScoreIndicatorInterface> $indicators
     */
    public function compute(ScoreSubjectType $subjectType, int $subjectId, string $scoreType, mixed $subject, array $indicators): Score
    {
        $breakdown = [];
        $weightedSum = 0.0;
        $totalWeight = 0.0;

        foreach ($indicators as $indicator) {
            $value = max(0.0, min(1.0, $indicator->compute($subject)));
            $weight = $indicator->weight();

            $breakdown[] = [
                'code' => $indicator->code(),
                'value' => round($value, 4),
                'weight' => $weight,
                'explanation' => $indicator->explain($subject),
            ];

            $weightedSum += $value * $weight;
            $totalWeight += $weight;
        }

        // totalWeight ne devrait jamais être 0 (chaque score consommateur
        // fournit une liste d'indicateurs à poids fixes) - gardé pour ne
        // jamais diviser par zéro si un score futur en décidait autrement.
        $normalized = $totalWeight > 0.0 ? $weightedSum / $totalWeight : 0.0;
        $value = round($normalized * 100, 2);

        return $this->scoreRepository->upsert($subjectType, $subjectId, $scoreType, $value, self::gradeFor($value), $breakdown);
    }

    /**
     * A : ≥80, B : ≥60, C : ≥40, D : ≥20, E : <20 - bornes rondes,
     * documentées ici comme la seule source de vérité (jamais
     * recalculées ailleurs).
     */
    public static function gradeFor(float $value): string
    {
        return match (true) {
            $value >= 80.0 => 'A',
            $value >= 60.0 => 'B',
            $value >= 40.0 => 'C',
            $value >= 20.0 => 'D',
            default => 'E',
        };
    }

    /**
     * Fiabilisation 2026-10-06 (demande explicite utilisateur, mapping
     * validé avant implémentation) : "l'explicabilité d'un score prime sur
     * sa sophistication" (plan §1, cf. docblock de classe) ne suffisait
     * plus une fois la vente de rapports envisagée - connaître la formule
     * ne dit pas sur quelle norme/autorité de régulation climatique
     * reconnue elle s'appuie. Mapping par scoreType (pas par indicateur) :
     * c'est la méthode dans son ensemble qui se rattache à un référentiel,
     * pas chaque composante isolément. URLs vérifiées en direct le
     * 06/10/2026, pas de mémoire.
     *
     * @return list<array{framework: string, url: string, note: string}>
     */
    public static function methodologyReferencesFor(string $scoreType): array
    {
        return match ($scoreType) {
            'bankability' => [
                [
                    'framework' => "Fonds Vert pour le Climat (GCF) - Cadre d'investissement, 6 critères",
                    'url' => 'https://www.greenclimate.fund/about/policies/investment',
                    'note' => "Les 5 indicateurs de ce score recoupent directement les dimensions \"Needs of the recipient\", \"Country Ownership\" et \"Efficiency and Effectiveness\" du cadre d'investissement du GCF (complétude, porteur identifié, cohérence montant/secteur, instrument réaliste, risque pays).",
                ],
            ],
            'investment_risk' => [
                [
                    'framework' => 'Equator Principles (EP4)',
                    'url' => 'https://equator-principles.com/',
                    'note' => "Cadre de référence pour l'évaluation du risque environnemental/social/financier d'un projet en finance de projet - ce score en reprend la logique (risque pays × secteur × stade projet) avec des indicateurs ouverts, pas les critères EP4 eux-mêmes.",
                ],
                [
                    'framework' => 'IFC Performance Standards on Environmental and Social Sustainability',
                    'url' => 'https://www.ifc.org/PerformanceStandards',
                    'note' => "Référentiel complémentaire pour le risque projet en finance climatique dans les pays en développement.",
                ],
                [
                    'framework' => 'Choix méthodologique assumé',
                    'url' => '',
                    'note' => "Aucun indice souverain tiers payant (Moody's/S&P/Coface...) n'est utilisé, par décision explicite : uniquement des indicateurs ouverts calculés sur données réelles (volatilité des flux, concentration des bailleurs via l'indice de Herfindahl-Hirschman, écart CDN). Un choix de transparence méthodologique, pas une lacune.",
                ],
            ],
            'equity' => [
                [
                    'framework' => 'ND-GAIN Country Index (Notre Dame Global Adaptation Initiative)',
                    'url' => 'https://gain.nd.edu/',
                    'note' => "Source de l'indice de vulnérabilité climatique par pays utilisé par ce score.",
                ],
                [
                    'framework' => "Accord de Paris - principe de responsabilité commune mais différenciée",
                    'url' => 'https://unfccc.int/process-and-meetings/the-paris-agreement',
                    'note' => "Le ratio financement/habitant ÷ vulnérabilité opérationnalise directement ce principe : à financement égal, un pays plus vulnérable doit être considéré mieux servi.",
                ],
            ],
            'transparency' => [
                [
                    'framework' => 'Norme IATI (International Aid Transparency Initiative)',
                    'url' => 'https://iatistandard.org/',
                    'note' => "Un des 5 indicateurs de ce score vérifie directement la publication IATI du bailleur.",
                ],
                [
                    'framework' => 'IFRS S2 - Climate-related Disclosures (ISSB, successeur de la TCFD)',
                    'url' => 'https://www.ifrs.org/issued-standards/ifrs-sustainability-standards-navigator/ifrs-s2-climate-related-disclosures/',
                    'note' => "Les piliers Gouvernance/Stratégie/Gestion des risques/Métriques d'IFRS S2 correspondent aux dimensions fraîcheur/granularité/complétude évaluées par ce score.",
                ],
            ],
            'ndc_alignment' => [
                [
                    'framework' => 'Registre officiel des CDN de la CCNUCC',
                    'url' => 'https://unfccc.int/NDCREG',
                    'note' => "Source de référence pour les cibles climatiques nationales (CDN) comparées au financement reçu.",
                ],
                [
                    'framework' => 'Accord de Paris, Article 9 (finance climatique)',
                    'url' => 'https://unfccc.int/process-and-meetings/the-paris-agreement',
                    'note' => "Cadre juridique international définissant l'obligation de financement climatique que ce score mesure l'exécution.",
                ],
            ],
            default => [],
        };
    }

    /**
     * Forme JSON partagée par tous les endpoints de score (C2.4, C2.6, et
     * les futurs C2.7/C2.8/C3.3/C3.6) - une seule fois ici plutôt que
     * dupliquée par contrôleur.
     *
     * @return array{subjectType: string, subjectId: int, scoreType: string, value: float, grade: string, breakdown: array<int, array{code: string, value: float, weight: float, explanation: string}>, methodologyReferences: list<array{framework: string, url: string, note: string}>, computedAt: string}
     */
    public static function toArray(Score $score): array
    {
        return [
            'subjectType' => $score->getSubjectType()->value,
            'subjectId' => $score->getSubjectId(),
            'scoreType' => $score->getScoreType(),
            'value' => $score->getValue(),
            'grade' => $score->getGrade(),
            'breakdown' => $score->getBreakdown(),
            'methodologyReferences' => self::methodologyReferencesFor($score->getScoreType()),
            'computedAt' => $score->getComputedAt()->format(\DATE_ATOM),
        ];
    }
}
