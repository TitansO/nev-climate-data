<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * C3.8 : les 4 conditions détectables par App\Service\AlertEvaluationService,
 * une par ligne du plan ("alerte gap critique, opportunité qui ferme,
 * score dégradé, flux anormal"). Une AlertRule active de ce type déclenche
 * une Notification (NotificationType::AlertTriggered) pour son propriétaire
 * à chaque exécution de "app:alerts:evaluate" où la condition est vraie.
 */
enum AlertRuleType: string
{
    case GapCritical = 'gap_critical';
    case OpportunityClosing = 'opportunity_closing';
    case ScoreDegraded = 'score_degraded';
    case AbnormalFlow = 'abnormal_flow';
}
