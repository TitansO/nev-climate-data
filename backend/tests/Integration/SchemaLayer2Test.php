<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\ApiKey;
use App\Entity\Country;
use App\Entity\Enum\ApiKeyStatus;
use App\Entity\Enum\FundingType;
use App\Entity\Enum\NotificationType;
use App\Entity\Enum\ReportStatus;
use App\Entity\Enum\SourceReliability;
use App\Entity\Enum\SourceType;
use App\Entity\Enum\UserRole;
use App\Entity\Enum\ValidationStatus;
use App\Entity\Funding;
use App\Entity\Notification;
use App\Entity\Report;
use App\Entity\Sector;
use App\Entity\Source;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SchemaLayer2Test extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();
    }

    public function testFundingCanBePersistedWithRelationsAndFetched(): void
    {
        $country = new Country('Senegal', 'SEN', 'West Africa');
        $sector = new Sector('Renewable Energy');
        $source = new Source('Internal Demo', SourceType::InternalDemo, SourceReliability::Medium);
        $this->entityManager->persist($country);
        $this->entityManager->persist($sector);
        $this->entityManager->persist($source);

        $funding = new Funding(
            $country,
            $sector,
            2025,
            '1000000.00',
            FundingType::Public,
            $source,
            new \DateTimeImmutable('2026-08-20'),
            ValidationStatus::Demo,
        );
        $this->entityManager->persist($funding);
        $this->entityManager->flush();
        $id = $funding->getId();
        $this->entityManager->clear();

        $fetched = $this->entityManager->find(Funding::class, $id);

        self::assertNotNull($fetched);
        self::assertSame('Senegal', $fetched->getCountry()->getName());
        self::assertSame('Renewable Energy', $fetched->getSector()->getName());
        self::assertSame('1000000.00', $fetched->getAmount());
    }

    public function testReportCanBePersistedAndFetched(): void
    {
        $report = new Report('2026 Climate Finance Overview', 'Annual Report', 'reports/2026-overview.pdf');
        $report->setStatus(ReportStatus::Published);
        $this->entityManager->persist($report);
        $this->entityManager->flush();
        $id = $report->getId();
        $this->entityManager->clear();

        $fetched = $this->entityManager->find(Report::class, $id);

        self::assertNotNull($fetched);
        self::assertSame(ReportStatus::Published, $fetched->getStatus());
    }

    public function testApiKeyCanBePersistedWithUserAndFetched(): void
    {
        $user = new User('Amina Diallo', 'amina.apikey@example.com', 'hashed-password', UserRole::ExternalPartner);
        $this->entityManager->persist($user);

        $apiKey = new ApiKey($user, 'hashed-key-value', 1000);
        $this->entityManager->persist($apiKey);
        $this->entityManager->flush();
        $id = $apiKey->getId();
        $this->entityManager->clear();

        $fetched = $this->entityManager->find(ApiKey::class, $id);

        self::assertNotNull($fetched);
        self::assertSame(ApiKeyStatus::Active, $fetched->getStatus());
        self::assertSame('amina.apikey@example.com', $fetched->getUser()->getEmail());
    }

    public function testNotificationCanBePersistedWithUserAndFetched(): void
    {
        $user = new User('Amina Diallo', 'amina.notif@example.com', 'hashed-password', UserRole::InternalAnalyst);
        $this->entityManager->persist($user);

        $notification = new Notification($user, NotificationType::NewReport, 'A new report was published.');
        $this->entityManager->persist($notification);
        $this->entityManager->flush();
        $id = $notification->getId();
        $this->entityManager->clear();

        $fetched = $this->entityManager->find(Notification::class, $id);

        self::assertNotNull($fetched);
        self::assertFalse($fetched->isRead());
    }

    public function testFundingIndexesExist(): void
    {
        /** @var Connection $connection */
        $connection = $this->entityManager->getConnection();
        $indexNames = $connection->fetchFirstColumn(
            "SELECT indexname FROM pg_indexes WHERE tablename = 'funding'"
        );

        self::assertContains('idx_funding_country', $indexNames);
        self::assertContains('idx_funding_sector', $indexNames);
        self::assertContains('idx_funding_year', $indexNames);
        self::assertContains('idx_funding_collection_date', $indexNames);
    }

    protected function tearDown(): void
    {
        $connection = $this->entityManager->getConnection();
        if ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
        $this->entityManager->close();
        parent::tearDown();
    }
}
