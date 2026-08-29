<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Country;
use App\Entity\Enum\ReportStatus;
use App\Entity\Report;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Dataset seeded by seedDataset() for every test:
 *   - "Annual Report" (Published, no country) - id kept in $this->annualReportId
 *   - "Regional Report" (Published, no country)
 *   - "Country Report" / Senegal (Published)
 *   - "Country Report" / Kenya (Draft, no publicationDate)
 * So type=Country+Report -> 1 published result (Senegal only, Kenya is a
 * Draft); country=SEN -> 1 result; the Draft never appears in any list
 * response and 404s if downloaded directly by id.
 */
final class ReportControllerTest extends WebTestCase
{
    private EntityManagerInterface $entityManager;
    private string $reportStorageDir;
    private int $publishedWithFileId;
    private int $publishedWithoutFileId;
    private int $draftId;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        if (isset($this->entityManager)) {
            $connection = $this->entityManager->getConnection();
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $this->entityManager->close();
        }
        if (isset($this->reportStorageDir)) {
            @unlink($this->reportStorageDir.'/test-reports/with-file.pdf');
        }
        parent::tearDown();
    }

    private function seedDataset(KernelBrowser $client): void
    {
        $client->disableReboot();

        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->reportStorageDir = $container->getParameter('app.report_storage_dir');
        $this->entityManager->getConnection()->beginTransaction();

        $senegal = new Country('Senegal', 'SEN', "Afrique de l'Ouest");
        $kenya = new Country('Kenya', 'KEN', "Afrique de l'Est");
        $this->entityManager->persist($senegal);
        $this->entityManager->persist($kenya);

        $annual = new Report('Annual Report Title', 'Annual Report', 'test-reports/annual.pdf');
        $annual->setStatus(ReportStatus::Published);
        $annual->setPublicationDate(new \DateTimeImmutable('2026-01-10'));
        $this->entityManager->persist($annual);

        $regional = new Report('Regional Report Title', 'Regional Report', 'test-reports/regional.pdf');
        $regional->setStatus(ReportStatus::Published);
        $regional->setPublicationDate(new \DateTimeImmutable('2026-02-05'));
        $this->entityManager->persist($regional);

        $senegalReport = new Report('Senegal Country Report', 'Country Report', 'test-reports/with-file.pdf');
        $senegalReport->setStatus(ReportStatus::Published);
        $senegalReport->setCountry($senegal);
        $senegalReport->setPublicationDate(new \DateTimeImmutable('2026-03-01'));
        $this->entityManager->persist($senegalReport);

        $kenyaDraft = new Report('Kenya Country Report', 'Country Report', 'test-reports/kenya-draft.pdf');
        $kenyaDraft->setCountry($kenya);
        // Left in Draft (constructor default) - must never appear in a list
        // response or be downloadable, even though it has a pdfFile value.
        $this->entityManager->persist($kenyaDraft);

        $this->entityManager->flush();

        $this->publishedWithFileId = $senegalReport->getId();
        $this->publishedWithoutFileId = $annual->getId();
        $this->draftId = $kenyaDraft->getId();

        $directory = $this->reportStorageDir.'/test-reports';
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
        file_put_contents($directory.'/with-file.pdf', '%PDF-1.4 test placeholder');
    }

    public function testReportsListReturns200AndIsPubliclyAccessible(): void
    {
        $client = static::createClient();
        $this->seedDataset($client);

        // No Authorization header, no X-API-Key - a visitor with no account.
        $client->request('GET', '/api/reports');

        self::assertResponseIsSuccessful();
    }

    public function testOnlyPublishedReportsAreListed(): void
    {
        $client = static::createClient();
        $this->seedDataset($client);

        $client->request('GET', '/api/reports?limit=100');

        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame(3, $data['meta']['total']);
        $ids = array_column($data['data'], 'id');
        self::assertNotContains($this->draftId, $ids);
    }

    public function testFilterByType(): void
    {
        $client = static::createClient();
        $this->seedDataset($client);

        $client->request('GET', '/api/reports?type=Country Report');

        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame(1, $data['meta']['total']);
        self::assertSame('Senegal Country Report', $data['data'][0]['title']);
    }

    public function testFilterByCountry(): void
    {
        $client = static::createClient();
        $this->seedDataset($client);

        $client->request('GET', '/api/reports?country=SEN');

        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame(1, $data['meta']['total']);
        self::assertSame('SEN', $data['data'][0]['country']['isoCode']);
    }

    public function testDefaultPaginationMeta(): void
    {
        $client = static::createClient();
        $this->seedDataset($client);

        $client->request('GET', '/api/reports');

        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame(1, $data['meta']['page']);
        self::assertSame(12, $data['meta']['limit']);
        self::assertSame(3, $data['meta']['total']);
        self::assertSame(1, $data['meta']['totalPages']);
    }

    public function testInvalidPageReturns400Json(): void
    {
        $client = static::createClient();
        $this->seedDataset($client);

        $client->request('GET', '/api/reports?page=0');

        self::assertResponseStatusCodeSame(400);
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame(400, $data['code']);
    }

    public function testResponseShapeMatchesContract(): void
    {
        $client = static::createClient();
        $this->seedDataset($client);

        $client->request('GET', '/api/reports?type=Country Report');

        $data = json_decode($client->getResponse()->getContent(), true);
        $item = $data['data'][0];
        self::assertSame($this->publishedWithFileId, $item['id']);
        self::assertSame('Senegal Country Report', $item['title']);
        self::assertSame('Country Report', $item['type']);
        self::assertSame('Senegal', $item['country']['name']);
        self::assertSame('SEN', $item['country']['isoCode']);
        self::assertSame('2026-03-01', $item['publicationDate']);
        self::assertSame(0, $item['downloadCount']);
        self::assertSame('/api/reports/'.$this->publishedWithFileId.'/download', $item['downloadUrl']);
    }

    public function testDownloadIncrementsCountAndStreamsFile(): void
    {
        $client = static::createClient();
        $this->seedDataset($client);

        $client->request('GET', '/api/reports/'.$this->publishedWithFileId.'/download');

        self::assertResponseIsSuccessful();
        self::assertSame('application/pdf', $client->getResponse()->headers->get('Content-Type'));

        $client->request('GET', '/api/reports?type=Country Report');
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame(1, $data['data'][0]['downloadCount']);
    }

    public function testDownloadOfDraftReportReturns404(): void
    {
        $client = static::createClient();
        $this->seedDataset($client);

        $client->request('GET', '/api/reports/'.$this->draftId.'/download');

        self::assertResponseStatusCodeSame(404);
    }

    public function testDownloadOfNonexistentReportReturns404(): void
    {
        $client = static::createClient();
        $this->seedDataset($client);

        $client->request('GET', '/api/reports/999999/download');

        self::assertResponseStatusCodeSame(404);
    }

    public function testDownloadWithMissingFileReturns404(): void
    {
        $client = static::createClient();
        $this->seedDataset($client);

        // "Annual Report Title" is published but its PDF was never written
        // to disk by seedDataset() (only with-file.pdf was) - the missing-
        // file branch in ReportController::download().
        $client->request('GET', '/api/reports/'.$this->publishedWithoutFileId.'/download');

        self::assertResponseStatusCodeSame(404);
    }
}
