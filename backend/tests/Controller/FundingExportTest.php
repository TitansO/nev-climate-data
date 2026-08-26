<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Country;
use App\Entity\Enum\FundingType;
use App\Entity\Enum\SourceReliability;
use App\Entity\Enum\SourceType;
use App\Entity\Enum\UserRole;
use App\Entity\Enum\ValidationStatus;
use App\Entity\Funding;
use App\Entity\Sector;
use App\Entity\Source;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * GET /api/funding/export (A2.3). Same 15/10 Senegal-public+private /
 * Kenya-private dataset shape as FundingControllerTest, kept local to this
 * file (no shared base class exists in this test suite for that) plus a
 * User so a JWT can be minted.
 */
final class FundingExportTest extends WebTestCase
{
    private EntityManagerInterface $entityManager;

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
        parent::tearDown();
    }

    private function seedDataset(KernelBrowser $client): void
    {
        $client->disableReboot();

        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();

        $senegal = new Country('Senegal', 'SEN', "Afrique de l'Ouest");
        $kenya = new Country('Kenya', 'KEN', "Afrique de l'Est");
        $renewableEnergy = new Sector('Renewable Energy');
        $agriculture = new Sector('Agriculture');
        $source = new Source('Test Source', SourceType::InternalDemo, SourceReliability::Medium);
        $user = new User('Amina Diallo', 'export-user@example.com', password_hash('correct-horse-battery-staple', PASSWORD_DEFAULT), UserRole::ExternalPartner);

        foreach ([$senegal, $kenya, $renewableEnergy, $agriculture, $source, $user] as $entity) {
            $this->entityManager->persist($entity);
        }

        for ($i = 0; $i < 10; ++$i) {
            $this->entityManager->persist(new Funding($senegal, $renewableEnergy, 2025, '1000000.00', FundingType::Public, $source, new \DateTimeImmutable('2025-03-15'), ValidationStatus::Demo));
        }
        for ($i = 0; $i < 5; ++$i) {
            $this->entityManager->persist(new Funding($senegal, $renewableEnergy, 2025, '500000.00', FundingType::Private, $source, new \DateTimeImmutable('2025-03-15'), ValidationStatus::Demo));
        }
        for ($i = 0; $i < 10; ++$i) {
            $this->entityManager->persist(new Funding($kenya, $agriculture, 2024, '250000.00', FundingType::Private, $source, new \DateTimeImmutable('2024-06-01'), ValidationStatus::Demo));
        }

        $this->entityManager->flush();
    }

    /**
     * @return array{token: string, refresh_token: string}
     */
    private function loginAndGetTokens(KernelBrowser $client, string $email = 'export-user@example.com', string $plainPassword = 'correct-horse-battery-staple'): array
    {
        $client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['email' => $email, 'password' => $plainPassword]));

        return json_decode($client->getResponse()->getContent(), true);
    }

    public function testExportWithoutAuthenticationFails(): void
    {
        $client = static::createClient();
        $this->seedDataset($client);

        $client->request('GET', '/api/funding/export');

        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testAuthenticatedExportSucceedsWithCsvContentType(): void
    {
        $client = static::createClient();
        $this->seedDataset($client);
        $tokens = $this->loginAndGetTokens($client);

        $client->request('GET', '/api/funding/export', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$tokens['token']]);

        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('text/csv', $client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('attachment', $client->getResponse()->headers->get('Content-Disposition'));
    }

    public function testExportWithNoFilterReturnsEveryRow(): void
    {
        $client = static::createClient();
        $this->seedDataset($client);
        $tokens = $this->loginAndGetTokens($client);

        $client->request('GET', '/api/funding/export', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$tokens['token']]);

        $rows = self::parseCsv($client->getResponse()->getContent());
        self::assertCount(25, $rows); // header excluded by parseCsv()
    }

    public function testExportReusesTheSameFiltersAsTheListEndpoint(): void
    {
        $client = static::createClient();
        $this->seedDataset($client);
        $tokens = $this->loginAndGetTokens($client);

        $client->request('GET', '/api/funding/export?country=SEN&fundingType=private', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$tokens['token']]);

        $rows = self::parseCsv($client->getResponse()->getContent());
        self::assertCount(5, $rows);
        foreach ($rows as $row) {
            self::assertSame('SEN', $row['country_iso_code']);
            self::assertSame('private', $row['funding_type']);
        }
    }

    public function testExportContentMatchesFundingData(): void
    {
        $client = static::createClient();
        $this->seedDataset($client);
        $tokens = $this->loginAndGetTokens($client);

        $client->request('GET', '/api/funding/export?country=SEN&fundingType=public', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$tokens['token']]);

        $rows = self::parseCsv($client->getResponse()->getContent());
        self::assertCount(10, $rows);
        $row = $rows[0];
        self::assertSame(['id', 'country_name', 'country_iso_code', 'sector_id', 'sector_name', 'year', 'amount', 'funding_type', 'source_id', 'source_name', 'collection_date', 'validation_status'], array_keys($row));
        self::assertSame('Senegal', $row['country_name']);
        self::assertSame('Renewable Energy', $row['sector_name']);
        self::assertSame('2025', $row['year']);
        self::assertSame('1000000.00', $row['amount']);
        self::assertSame('2025-03-15', $row['collection_date']);
        self::assertSame('demo', $row['validation_status']);
    }

    public function testInvalidFormatReturns400Json(): void
    {
        $client = static::createClient();
        $this->seedDataset($client);
        $tokens = $this->loginAndGetTokens($client);

        $client->request('GET', '/api/funding/export?format=pdf', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$tokens['token']]);

        self::assertSame(400, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame(400, $data['code']);
    }

    public function testInvalidFilterReturns400JsonJustLikeTheListEndpoint(): void
    {
        $client = static::createClient();
        $this->seedDataset($client);
        $tokens = $this->loginAndGetTokens($client);

        $client->request('GET', '/api/funding/export?fundingType=not-a-type', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$tokens['token']]);

        self::assertSame(400, $client->getResponse()->getStatusCode());
    }

    public function testApiKeyAuthenticationIsAlsoAccepted(): void
    {
        $client = static::createClient();
        $this->seedDataset($client);
        $tokens = $this->loginAndGetTokens($client);

        $client->request('POST', '/api/api-keys', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$tokens['token']]);
        $created = json_decode($client->getResponse()->getContent(), true);

        $client->request('GET', '/api/funding/export', server: ['HTTP_X_API_KEY' => $created['key']]);

        self::assertResponseIsSuccessful();
    }

    public function testExportIsDocumentedInSwagger(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/doc.json');

        self::assertResponseIsSuccessful();
        $spec = json_decode($client->getResponse()->getContent(), true);
        self::assertArrayHasKey('/api/funding/export', $spec['paths']);
        self::assertArrayHasKey('get', $spec['paths']['/api/funding/export']);
    }

    /**
     * @return list<array<string, string>> one assoc row per CSV data line (header consumed as keys)
     */
    private static function parseCsv(string $content): array
    {
        // Strip the UTF-8 BOM written ahead of the header row.
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        $lines = array_values(array_filter(explode("\n", $content), static fn ($line) => '' !== trim($line)));

        $header = str_getcsv(array_shift($lines), escape: '');

        return array_map(static fn (string $line) => array_combine($header, str_getcsv($line, escape: '')), $lines);
    }
}
