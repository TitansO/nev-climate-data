<?php

declare(strict_types=1);

namespace App\Entity\Enum;

enum NotificationType: string
{
    case NewReport = 'new_report';
    case NewData = 'new_data';

    /**
     * A2.3: raised by App\Service\ExportService once an async export
     * (beyond the row-count threshold) finishes - "notification pour les
     * gros volumes" per the plan. Reuses the notification system exactly
     * as it already stands (A2.4/A2.10); no new mechanism was built for
     * this.
     */
    case ExportReady = 'export_ready';

    /**
     * A2.10: where clicking a notification of this type should send the
     * user - the frontend page that actually shows the kind of thing the
     * notification is about. Static per-type mapping (not a stored field):
     * every case already maps onto an existing, real page, the same
     * reasoning App\Service\SearchService uses for its own `destination`
     * (A2.8) - no per-notification destination is needed since none of
     * these types reference a specific record (e.g. one Report) that has
     * its own route yet.
     */
    public function destination(): string
    {
        return match ($this) {
            self::NewReport => 'reports.html',
            self::NewData => 'data.html',
            // The only place with export UI - see the A2.9/A2.10 report's
            // reasoning for not building a dedicated "my exports" page.
            self::ExportReady => 'data.html',
        };
    }
}
