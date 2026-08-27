<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * A2.3 (async export beyond a row-count threshold). Mirrors the
 * Pending -> Processing -> Ready|Failed shape the A2.3 spec describes
 * ("statut clair") - a client polling GET /api/funding/exports/{id} always
 * gets one of these four values, never an ambiguous in-between state.
 */
enum ExportStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Ready = 'ready';
    case Failed = 'failed';
}
