<?php

declare(strict_types=1);

namespace App\Message;

/**
 * A2.3 async export. Carries only the Export id - the handler re-fetches
 * the entity fresh (and re-parses its stored filter query string) rather
 * than the message carrying any of that itself, so the message payload
 * stays tiny and never goes stale relative to the database row.
 */
final readonly class GenerateExportMessage
{
    public function __construct(
        public int $exportId,
    ) {
    }
}
