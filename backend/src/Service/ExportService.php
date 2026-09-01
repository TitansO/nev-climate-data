<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\FundingExportFormat;
use App\Dto\FundingSearchCriteria;
use App\Entity\Enum\NotificationType;
use App\Entity\Export;
use App\Entity\Funding;
use App\Entity\User;
use App\Message\GenerateExportMessage;
use App\Repository\ExportRepository;
use App\Repository\FundingRepository;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * A2.3: export generation (CSV/XLSX), synchronous below
 * self::ASYNC_THRESHOLD rows and asynchronous above it - "génération
 * asynchrone au-delà du seuil" per the plan. Controllers stay thin
 * (App\Controller\FundingController only decides sync vs async and
 * handles the HTTP response shape); this service and
 * App\MessageHandler\GenerateExportMessageHandler share the actual file-
 * building logic so the sync and async paths can never drift apart on
 * what a CSV/XLSX export actually contains.
 */
final class ExportService
{
    /**
     * Rows above this go async. PROVISIONAL (same reasoning as
     * App\Security\ApiKeyQuotaPolicy): the plan says "au-delà du seuil"
     * without a number.
     *
     * Raised from 500 to 10 000 after a real production bug: the async
     * path requires a separate worker process consuming the `async`
     * Messenger transport (docker-compose.yml's `backend-worker` service,
     * `messenger:consume async`) - present locally/Codespace, but the
     * current Render deployment only runs the web service, no worker. An
     * export above the old threshold (every unfiltered "export everything"
     * - 1080 rows in the current fixtures - immediately qualified) was
     * accepted (202) and left stuck at "pending" forever, since nothing
     * was ever going to consume that message. 10 000 keeps every export of
     * the current dataset synchronous - same one-request-one-download
     * behavior as the common filtered case - while still async above that.
     * The async path itself is untouched (still real, still tested, still
     * exercised locally) - if a Render worker is added later (see the
     * alternative considered in this bug's discussion) or the dataset
     * grows well past this, revisit the number rather than the design.
     */
    public const ASYNC_THRESHOLD = 10_000;

    private const CSV_HEADER = ['id', 'country_name', 'country_iso_code', 'sector_id', 'sector_name', 'year', 'amount', 'funding_type', 'source_id', 'source_name', 'collection_date', 'validation_status'];

    public function __construct(
        private readonly FundingRepository $fundingRepository,
        private readonly ExportRepository $exportRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly NotificationService $notificationService,
        private readonly MessageBusInterface $messageBus,
        private readonly string $exportStorageDir,
    ) {
    }

    public function countMatching(FundingSearchCriteria $criteria): int
    {
        return $this->fundingRepository->countByCriteria($criteria);
    }

    /**
     * Synchronous path (below the threshold): builds the file entirely in
     * memory and hands the raw bytes back to the controller to stream
     * immediately - unchanged in spirit from the pre-A2.3-completion
     * behavior, now shared with the XLSX writer too.
     */
    public function generateContent(FundingSearchCriteria $criteria, FundingExportFormat $format): string
    {
        $items = $this->fundingRepository->streamByCriteria($criteria);

        return match ($format) {
            FundingExportFormat::Csv => self::buildCsv($items),
            FundingExportFormat::Xlsx => self::buildXlsx($items),
        };
    }

    /**
     * Async path (above the threshold): persists an Export job (Pending)
     * and dispatches a message for App\MessageHandler\GenerateExportMessageHandler
     * to pick up - see config/packages/messenger.yaml (doctrine transport)
     * and the "backend-worker" service in docker-compose.yml that actually
     * consumes it.
     */
    public function requestAsyncExport(User $user, FundingExportFormat $format, string $queryString): Export
    {
        $export = new Export($user, $format, $queryString);
        $this->entityManager->persist($export);
        $this->entityManager->flush();

        $this->messageBus->dispatch(new GenerateExportMessage($export->getId()));

        return $export;
    }

    /**
     * Runs in the worker process, never in an HTTP request. Re-parses the
     * stored query string through the exact same FundingSearchCriteria
     * used by GET /api/funding and the synchronous export path, so the
     * async result can never apply a different filter than what the user
     * actually asked for.
     */
    public function processAsyncExport(int $exportId): void
    {
        $export = $this->exportRepository->find($exportId);
        if (null === $export) {
            return; // deleted/invalid id - nothing to do, not an error worth retrying
        }

        $export->markProcessing();
        $this->entityManager->flush();

        try {
            parse_str($export->getFilterQueryString(), $params);
            $criteria = FundingSearchCriteria::fromQuery(new InputBag($params));

            $items = $this->fundingRepository->streamByCriteria($criteria);
            $rowCount = 0;
            $content = match ($export->getFormat()) {
                FundingExportFormat::Csv => self::buildCsv(self::countingIterable($items, $rowCount)),
                FundingExportFormat::Xlsx => self::buildXlsx(self::countingIterable($items, $rowCount)),
            };

            $filename = $export->getId().'.'.$export->getFormat()->value;
            $this->ensureStorageDirExists();
            file_put_contents($this->exportStorageDir.'/'.$filename, $content);

            $export->markReady($filename, $rowCount);
            $this->entityManager->flush();

            $this->notificationService->notify(
                $export->getUser(),
                NotificationType::ExportReady,
                \sprintf('Votre export %s est prêt (%d ligne%s).', strtoupper($export->getFormat()->value), $rowCount, $rowCount > 1 ? 's' : ''),
            );
        } catch (\Throwable $exception) {
            $export->markFailed($exception->getMessage());
            $this->entityManager->flush();
        }
    }

    public function absolutePathFor(Export $export): ?string
    {
        if (null === $export->getFilePath()) {
            return null;
        }

        return $this->exportStorageDir.'/'.$export->getFilePath();
    }

    private function ensureStorageDirExists(): void
    {
        if (!is_dir($this->exportStorageDir)) {
            mkdir($this->exportStorageDir, 0775, true);
        }
    }

    /**
     * Wraps an iterable to count the rows actually yielded, without
     * buffering them - the row count is only known once iteration
     * finishes, and by then buildCsv()/buildXlsx() have already consumed
     * the generator once (Funding rows aren't re-iterable from Doctrine's
     * side), so this counts as a side effect of the same single pass
     * rather than iterating twice.
     *
     * @param iterable<Funding> $items
     *
     * @return iterable<Funding>
     */
    private static function countingIterable(iterable $items, int &$count): iterable
    {
        foreach ($items as $item) {
            ++$count;
            yield $item;
        }
    }

    /**
     * @param iterable<Funding> $items
     */
    private static function buildCsv(iterable $items): string
    {
        $handle = fopen('php://memory', 'r+');
        // UTF-8 BOM: without it, Excel (the realistic consumer of a CSV
        // export of French-language content) misreads accented characters
        // as a different encoding on open. escape: '' (RFC 4180 - quotes
        // doubled, no backslash-escaping) rather than PHP's legacy default.
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, self::CSV_HEADER, escape: '');
        foreach ($items as $funding) {
            fputcsv($handle, self::toRow($funding), escape: '');
        }
        rewind($handle);
        $content = stream_get_contents($handle);
        fclose($handle);

        return $content;
    }

    /**
     * @param iterable<Funding> $items
     */
    private static function buildXlsx(iterable $items): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Funding export');
        $sheet->fromArray(self::CSV_HEADER, null, 'A1');

        $rowIndex = 2;
        foreach ($items as $funding) {
            $sheet->fromArray(self::toRow($funding), null, 'A'.$rowIndex);
            ++$rowIndex;
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'nev_export_');
        (new XlsxWriter($spreadsheet))->save($tmpPath);
        $content = file_get_contents($tmpPath);
        unlink($tmpPath);
        $spreadsheet->disconnectWorksheets();

        return $content;
    }

    /**
     * @return list<string>
     */
    private static function toRow(Funding $funding): array
    {
        return [
            (string) $funding->getId(),
            $funding->getCountry()->getName(),
            $funding->getCountry()->getIsoCode(),
            (string) $funding->getSector()->getId(),
            $funding->getSector()->getName(),
            (string) $funding->getYear(),
            $funding->getAmount(),
            $funding->getFundingType()->value,
            (string) $funding->getSource()->getId(),
            $funding->getSource()->getName(),
            $funding->getCollectionDate()->format('Y-m-d'),
            $funding->getValidationStatus()->value,
        ];
    }
}
