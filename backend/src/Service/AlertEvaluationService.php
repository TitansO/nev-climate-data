<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Enum\AlertRuleType;
use App\Entity\Enum\NotificationType;
use App\Entity\Enum\OpportunityStatus;
use App\Entity\Enum\ScoreSubjectType;
use App\Entity\Score;
use App\Entity\User;
use App\Repository\AlertRuleRepository;
use App\Repository\CountryRepository;
use App\Repository\FundingRepository;
use App\Repository\NotificationRepository;
use App\Repository\OpportunityRepository;
use App\Repository\ProjectRepository;
use App\Repository\ScoreRepository;
use App\Repository\SourceRepository;

/**
 * C3.8 : moteur de règles - évalue les 4 conditions du plan pour chaque
 * AlertRule active, et notifie son propriétaire (App\Service\
 * NotificationService, NotificationType::AlertTriggered) quand la
 * condition est vraie. Appelé par la commande "app:alerts:evaluate",
 * destinée à tourner en DAG quotidien (Airflow) - pas de planification ici,
 * ce service ne fait qu'évaluer une fois quand on l'appelle.
 *
 * Chaque condition n'est évaluée que si au moins une AlertRule active de
 * ce type existe (évite un calcul global coûteux - ex. le scan des 54 pays
 * pour "flux anormal" - quand personne ne l'a demandé).
 */
final class AlertEvaluationService
{
    /**
     * "Score dégradé" : chute d'au moins 10 points (sur 100) depuis la
     * précédente valeur - choix éditorial documenté ici, comme les seuils
     * de sévérité de GapDetectionService (C3.1) : pas de standard externe
     * pour ce qui constitue une dégradation "notable" d'un score composite.
     */
    private const SCORE_DEGRADATION_THRESHOLD_POINTS = 10.0;

    /**
     * "Opportunité qui ferme" : fenêtre d'alerte de 14 jours avant la date
     * de clôture - même raisonnement, aucun standard externe ne fixe ce
     * délai.
     */
    private const OPPORTUNITY_CLOSING_WINDOW_DAYS = 14;

    public function __construct(
        private readonly AlertRuleRepository $alertRuleRepository,
        private readonly NotificationRepository $notificationRepository,
        private readonly NotificationService $notificationService,
        private readonly GapDetectionService $gapDetectionService,
        private readonly OpportunityRepository $opportunityRepository,
        private readonly ScoreRepository $scoreRepository,
        private readonly FundingRepository $fundingRepository,
        private readonly CountryRepository $countryRepository,
        private readonly SourceRepository $sourceRepository,
        private readonly ProjectRepository $projectRepository,
    ) {
    }

    /**
     * @return int le nombre de notifications effectivement créées (hors doublons déjà non lus)
     */
    public function evaluate(): int
    {
        $created = 0;
        $created += $this->evaluateGapCritical();
        $created += $this->evaluateOpportunityClosing();
        $created += $this->evaluateScoreDegraded();
        $created += $this->evaluateAbnormalFlow();

        return $created;
    }

    private function evaluateGapCritical(): int
    {
        $rules = $this->alertRuleRepository->findActiveByType(AlertRuleType::GapCritical);
        if ([] === $rules) {
            return 0;
        }

        $criticalGaps = array_filter(
            $this->gapDetectionService->getGaps('country'),
            static fn (array $row): bool => 'critical' === $row['severity']
        );
        if ([] === $criticalGaps) {
            return 0;
        }

        $created = 0;
        foreach ($rules as $rule) {
            foreach ($criticalGaps as $gap) {
                $content = \sprintf(
                    'Gap critique : %s ne couvre que %.0f %% de son besoin CDN déclaré (reçu %s USD sur %s USD).',
                    $gap['country'],
                    $gap['coverageRatio'] * 100,
                    number_format($gap['receivedUsd'], 0, ',', ' '),
                    number_format($gap['declaredNeedUsd'], 0, ',', ' ')
                );
                $created += $this->notifyOnce($rule->getUser(), $content);
            }
        }

        return $created;
    }

    private function evaluateOpportunityClosing(): int
    {
        $rules = $this->alertRuleRepository->findActiveByType(AlertRuleType::OpportunityClosing);
        if ([] === $rules) {
            return 0;
        }

        $now = new \DateTimeImmutable();
        $window = $now->modify(\sprintf('+%d days', self::OPPORTUNITY_CLOSING_WINDOW_DAYS));

        $closingSoon = [];
        foreach ($this->opportunityRepository->findAll() as $opportunity) {
            $closesAt = $opportunity->getApplicationWindowClosesAt();
            if (null === $closesAt || $closesAt < $now || $closesAt > $window) {
                continue;
            }
            if (OpportunityStatus::Open !== OpportunityService::statusOf($opportunity, $now)) {
                continue;
            }
            $closingSoon[] = $opportunity;
        }
        if ([] === $closingSoon) {
            return 0;
        }

        $created = 0;
        foreach ($rules as $rule) {
            foreach ($closingSoon as $opportunity) {
                $content = \sprintf(
                    'Opportunité qui ferme bientôt : "%s" (%s) - date limite le %s.',
                    $opportunity->getTitle(),
                    $opportunity->getSource()->getName(),
                    $opportunity->getApplicationWindowClosesAt()->format('Y-m-d')
                );
                $created += $this->notifyOnce($rule->getUser(), $content);
            }
        }

        return $created;
    }

    private function evaluateScoreDegraded(): int
    {
        $rules = $this->alertRuleRepository->findActiveByType(AlertRuleType::ScoreDegraded);
        if ([] === $rules) {
            return 0;
        }

        $degradedScores = $this->scoreRepository->findDegraded(self::SCORE_DEGRADATION_THRESHOLD_POINTS);
        if ([] === $degradedScores) {
            return 0;
        }

        $created = 0;
        foreach ($rules as $rule) {
            foreach ($degradedScores as $score) {
                $content = \sprintf(
                    'Score dégradé : %s "%s" est passé de %.1f à %.1f.',
                    $this->subjectLabelFor($score),
                    $score->getScoreType(),
                    (float) $score->getPreviousValue(),
                    $score->getValue()
                );
                $created += $this->notifyOnce($rule->getUser(), $content);
            }
        }

        return $created;
    }

    private function evaluateAbnormalFlow(): int
    {
        $rules = $this->alertRuleRepository->findActiveByType(AlertRuleType::AbnormalFlow);
        if ([] === $rules) {
            return 0;
        }

        // Audit post-C3 2026-10-01 : une seule requete groupee par pays
        // plutot qu'une requete findYearlyTotalsForCountry() par pays dans
        // la boucle ci-dessous (~54 requetes par execution quotidienne) -
        // voir le docblock de FundingRepository::findYearlyTotalsGroupedByCountry().
        $totalsByCountryId = [];
        foreach ($this->fundingRepository->findYearlyTotalsGroupedByCountry() as $row) {
            $totalsByCountryId[$row['countryId']][] = ['year' => $row['year'], 'total' => $row['total']];
        }

        $created = 0;
        foreach ($this->countryRepository->findAll() as $country) {
            $yearlyTotals = $totalsByCountryId[$country->getId()] ?? [];
            // Il faut au moins 2 années d'historique en plus de l'année
            // courante pour qu'une moyenne/écart-type ait un sens - sinon
            // rien à comparer, jamais un flux "anormal" déduit de 1-2 points.
            if (\count($yearlyTotals) < 3) {
                continue;
            }

            $latest = $yearlyTotals[array_key_last($yearlyTotals)];
            $history = \array_slice($yearlyTotals, 0, -1);
            $historyAmounts = array_map(static fn (array $row): float => (float) $row['total'], $history);
            $latestAmount = (float) $latest['total'];

            $mean = array_sum($historyAmounts) / \count($historyAmounts);
            $variance = array_sum(array_map(static fn (float $amount): float => ($amount - $mean) ** 2, $historyAmounts)) / \count($historyAmounts);
            $stdDev = sqrt($variance);

            // Écart-type nul (historique parfaitement plat, ex. tous les
            // montants identiques) : repli sur un seuil simple (3x la
            // moyenne) plutôt qu'une division par zéro dans le calcul
            // "moyenne + 2 écarts-types".
            $isOutlier = $stdDev > 0.0
                ? $latestAmount > $mean + 2 * $stdDev
                : ($mean > 0.0 && $latestAmount > 3 * $mean);

            if (!$isOutlier) {
                continue;
            }

            $content = \sprintf(
                'Flux anormal : %s a reçu %s USD en %d, très au-dessus de sa moyenne historique (%s USD sur %d ans).',
                $country->getName(),
                number_format($latestAmount, 0, ',', ' '),
                (int) $latest['year'],
                number_format($mean, 0, ',', ' '),
                \count($historyAmounts)
            );

            foreach ($rules as $rule) {
                $created += $this->notifyOnce($rule->getUser(), $content);
            }
        }

        return $created;
    }

    private function subjectLabelFor(Score $score): string
    {
        $name = match ($score->getSubjectType()) {
            ScoreSubjectType::Country => $this->countryRepository->find($score->getSubjectId())?->getName(),
            ScoreSubjectType::Source => $this->sourceRepository->find($score->getSubjectId())?->getName(),
            ScoreSubjectType::Project => $this->projectRepository->find($score->getSubjectId())?->getTitle(),
            ScoreSubjectType::Funding => null,
        };

        return $name ?? \sprintf('%s #%d', $score->getSubjectType()->value, $score->getSubjectId());
    }

    private function notifyOnce(User $user, string $content): int
    {
        if ($this->notificationRepository->existsUnreadForUser($user, NotificationType::AlertTriggered, $content)) {
            return 0;
        }

        $this->notificationService->notify($user, NotificationType::AlertTriggered, $content);

        return 1;
    }
}
