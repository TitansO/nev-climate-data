<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\GenerateExportMessage;
use App\Service\ExportService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * A2.3 async export - the actual worker-side entry point. Symfony
 * discovers this via #[AsMessageHandler] and routes GenerateExportMessage
 * to it per config/packages/messenger.yaml's "async" transport, consumed
 * by the "backend-worker" service in docker-compose.yml
 * (messenger:consume async). All the real work lives in
 * App\Service\ExportService::processAsyncExport() - this class is
 * intentionally a one-line adapter, not a place for logic to accumulate.
 */
#[AsMessageHandler]
final readonly class GenerateExportMessageHandler
{
    public function __construct(
        private ExportService $exportService,
    ) {
    }

    public function __invoke(GenerateExportMessage $message): void
    {
        $this->exportService->processAsyncExport($message->exportId);
    }
}
