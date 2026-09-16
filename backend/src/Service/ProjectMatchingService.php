<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Enum\FundingInstrument;
use App\Entity\Enum\OpportunityStatus;
use App\Entity\Opportunity;
use App\Entity\Project;
use App\Repository\OpportunityRepository;

/**
 * C2.2 : "Matching Engine" - GET /api/projects/{id}/matches. Score de
 * correspondance projet<->opportunité par règles pondérées transparentes
 * (pas de ML, voir le plan §1 : "l'explicabilité d'un score prime sur sa
 * sophistication" pour C2/C3 en v1) : secteur (0,35), pays (0,25),
 * instrument (0,2), montant (0,1), fenêtre temporelle (0,1) - poids fixés
 * par le plan d'implémentation lui-même, pas une estimation de ce service.
 * `breakdown` renvoyé avec chaque résultat pour que le score reste
 * auditable, même raisonnement que App\Entity\Score (F2) même si ce
 * endpoint-ci ne persiste rien (candidat naturel pour F2/F3 dans une
 * itération future - pas fait ici pour rester dans le périmètre C2.2).
 */
final class ProjectMatchingService
{
    private const WEIGHT_SECTOR = 0.35;
    private const WEIGHT_COUNTRY = 0.25;
    private const WEIGHT_INSTRUMENT = 0.2;
    private const WEIGHT_AMOUNT = 0.1;
    private const WEIGHT_WINDOW = 0.1;

    public const DEFAULT_LIMIT = 10;
    public const MAX_LIMIT = 25;

    /**
     * Opportunity::eligibilityCriteria is free text describing eligibility
     * by region/income-group/alliance, not a structured country list (see
     * that entity's own docblock) - real opportunity text never names a
     * single country literally (confirmed against OpportunityFixtures).
     * These catch-all phrases are the one reliable, honest signal that an
     * opportunity is open to every NEV country without needing to parse
     * regional/alliance names this platform has no data model for.
     */
    private const CATCH_ALL_ELIGIBILITY_PHRASES = ['tous pays', 'tous les pays'];

    /**
     * French keyword(s) per FundingInstrument, matched against
     * Opportunity::eligibilityCriteria's free text (same "search the raw
     * text, never parse it" reasoning as country matching above) - the
     * exact wording OpportunityFixtures itself uses (e.g. "prêt
     * concessionnel", "equity" kept in English even in French text, a
     * genuine finance-jargon convention, not an inconsistency).
     *
     * @var array<string, list<string>>
     */
    private const INSTRUMENT_KEYWORDS = [
        'grant' => ['subvention'],
        'loan_concessional' => ['prêt concessionnel'],
        'loan_non_concessional' => ['prêt non concessionnel'],
        'guarantee' => ['garantie'],
        'equity' => ['equity', 'prise de participation'],
        'results_based' => ['résultats'],
    ];

    public function __construct(
        private readonly OpportunityRepository $opportunityRepository,
    ) {
    }

    /**
     * @return list<array{opportunityId: int, opportunityTitle: string, sourceName: string, opportunityStatus: string, score: float, breakdown: array{sector: float, country: float, instrument: float, amount: float, window: float}}>
     */
    public function matchesForProject(Project $project, int $limit): array
    {
        $now = new \DateTimeImmutable();

        $scored = array_map(
            fn (Opportunity $opportunity) => $this->score($project, $opportunity, $now),
            $this->opportunityRepository->findAll()
        );

        usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return \array_slice($scored, 0, $limit);
    }

    /**
     * @return array{opportunityId: int, opportunityTitle: string, sourceName: string, opportunityStatus: string, score: float, breakdown: array{sector: float, country: float, instrument: float, amount: float, window: float}}
     */
    private function score(Project $project, Opportunity $opportunity, \DateTimeImmutable $now): array
    {
        $breakdown = [
            'sector' => self::sectorScore($project, $opportunity),
            'country' => self::countryScore($project, $opportunity),
            'instrument' => self::instrumentScore($project, $opportunity),
            'amount' => self::amountScore($project, $opportunity),
            'window' => self::windowScore($opportunity, $now),
        ];

        $score = self::WEIGHT_SECTOR * $breakdown['sector']
            + self::WEIGHT_COUNTRY * $breakdown['country']
            + self::WEIGHT_INSTRUMENT * $breakdown['instrument']
            + self::WEIGHT_AMOUNT * $breakdown['amount']
            + self::WEIGHT_WINDOW * $breakdown['window'];

        return [
            'opportunityId' => $opportunity->getId(),
            'opportunityTitle' => $opportunity->getTitle(),
            'sourceName' => $opportunity->getSource()->getName(),
            'opportunityStatus' => OpportunityService::statusOf($opportunity, $now)->value,
            'score' => round($score, 4),
            'breakdown' => $breakdown,
        ];
    }

    /**
     * 1.0 : même secteur, ou l'opportunité est explicitement multi-
     * sectorielle (`sector` NULL - voir OpportunityFixtures : "Secteur
     * ouvert (multi-sectoriel)"). 0.0 sinon - aucune notion de secteurs
     * "proches" n'existe sur cette plateforme (pas de hiérarchie/similarité
     * entre secteurs), un score binaire est le seul choix honnête ici.
     */
    private static function sectorScore(Project $project, Opportunity $opportunity): float
    {
        if (null === $opportunity->getSector()) {
            return 1.0;
        }

        return $opportunity->getSector()->getId() === $project->getSector()->getId() ? 1.0 : 0.0;
    }

    /**
     * 1.0 si le nom ou la région du pays du projet, ou une formule
     * générale ("tous pays..."), apparaît dans le texte d'éligibilité.
     * Volontairement approximatif - voir le docblock de la classe.
     */
    private static function countryScore(Project $project, Opportunity $opportunity): float
    {
        $haystack = self::normalize($opportunity->getEligibilityCriteria());

        foreach (self::CATCH_ALL_ELIGIBILITY_PHRASES as $phrase) {
            if (str_contains($haystack, self::normalize($phrase))) {
                return 1.0;
            }
        }

        $country = $project->getCountry();
        if (str_contains($haystack, self::normalize($country->getName())) || str_contains($haystack, self::normalize($country->getRegion()))) {
            return 1.0;
        }

        return 0.0;
    }

    /**
     * 0.5 (neutre, ni pour ni contre) quand le projet n'a pas encore
     * arrêté d'instrument recherché (voir Project::$instrumentSought,
     * nullable par conception pour un projet en stade "idea") - jamais 0,
     * qui pénaliserait à tort un projet simplement pas assez avancé.
     */
    private static function instrumentScore(Project $project, Opportunity $opportunity): float
    {
        $instrument = $project->getInstrumentSought();
        if (null === $instrument || FundingInstrument::Unknown === $instrument) {
            return 0.5;
        }

        $keywords = self::INSTRUMENT_KEYWORDS[$instrument->value] ?? [];
        if ([] === $keywords) {
            return 0.5;
        }

        $haystack = self::normalize($opportunity->getEligibilityCriteria());
        foreach ($keywords as $keyword) {
            if (str_contains($haystack, self::normalize($keyword))) {
                return 1.0;
            }
        }

        return 0.0;
    }

    /**
     * "Ordre de grandeur" (formulation du plan), pas une inclusion stricte :
     * un projet demandant au plus l'enveloppe totale de l'opportunité
     * obtient 1.0 ; au-delà, le score décroît en fonction de l'ordre de
     * grandeur du dépassement (log10 du ratio) plutôt que de tomber à 0
     * d'un coup - `envelopeAmount` est le montant total du pool, jamais le
     * plafond par projet (souvent seulement mentionné en texte libre, non
     * structuré - voir OpportunityFixtures), donc ce n'est qu'un signal
     * d'ordre de grandeur, jamais une garantie d'éligibilité du montant.
     */
    private static function amountScore(Project $project, Opportunity $opportunity): float
    {
        $envelope = (float) $opportunity->getEnvelopeAmount();
        if ($envelope <= 0.0) {
            return 0.0;
        }

        $ratio = (float) $project->getAmountSought() / $envelope;
        if ($ratio <= 1.0) {
            return 1.0;
        }

        return max(0.0, 1.0 - log10($ratio));
    }

    /**
     * 1.0 si l'opportunité est ouverte ou pas encore ouverte (le projet
     * pourra candidater le moment venu), 0.0 si déjà fermée - même
     * calcul que C1.8 (OpportunityService::statusOf()), une seule source
     * de vérité pour ce que "ouvert" veut dire pour une Opportunity.
     */
    private static function windowScore(Opportunity $opportunity, \DateTimeImmutable $now): float
    {
        return OpportunityStatus::Closed === OpportunityService::statusOf($opportunity, $now) ? 0.0 : 1.0;
    }

    /**
     * Case- and accent-insensitive, same intent as the rest of this
     * codebase's UNACCENT(LOWER(...)) SQL pattern (App\Doctrine\DQL\
     * UnaccentFunction) - done in PHP here instead since this matches
     * already-loaded entities rather than running a fresh query, on a
     * table too small to justify a round-trip per candidate (same "too
     * small to bother" reasoning as OpportunityService's own docblock).
     * Real gap this closes: CountryFixtures mixes conventions ("Senegal"
     * plain vs "Côte d'Ivoire" accented) - a plain mb_strtolower() alone
     * would silently never match "Senegal" against eligibility text
     * spelled "Sénégal", the natural French spelling a real bailleur
     * would use.
     */
    private static function normalize(string $text): string
    {
        $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT', $text);

        return mb_strtolower(trim(false !== $transliterated ? $transliterated : $text));
    }
}
