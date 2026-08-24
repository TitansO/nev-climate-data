<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Country;
use App\Entity\Enum\ReportStatus;
use App\Entity\Report;
use PHPUnit\Framework\TestCase;

final class ReportTest extends TestCase
{
    public function testConstructorSetsDefaults(): void
    {
        $report = new Report('2026 Climate Finance Overview', 'Annual Report', 'reports/2026-overview.pdf');

        self::assertNull($report->getId());
        self::assertSame('2026 Climate Finance Overview', $report->getTitle());
        self::assertNull($report->getCountry());
        self::assertNull($report->getRegion());
        self::assertSame('Annual Report', $report->getType());
        self::assertNull($report->getPublicationDate());
        self::assertSame(ReportStatus::Draft, $report->getStatus());
        self::assertSame('reports/2026-overview.pdf', $report->getPdfFile());
        self::assertSame(0, $report->getDownloadCount());
    }

    public function testPublishingSetsStatusAndPublicationDate(): void
    {
        $report = new Report('2026 Climate Finance Overview', 'Annual Report', 'reports/2026-overview.pdf');
        $country = new Country('Senegal', 'SEN', 'West Africa');
        $publicationDate = new \DateTimeImmutable('2026-08-22');

        $report->setCountry($country);
        $report->setRegion('West Africa');
        $report->setPublicationDate($publicationDate);
        $report->setStatus(ReportStatus::Published);

        self::assertSame($country, $report->getCountry());
        self::assertSame('West Africa', $report->getRegion());
        self::assertSame($publicationDate, $report->getPublicationDate());
        self::assertSame(ReportStatus::Published, $report->getStatus());
    }

    public function testDownloadCountCanBeIncremented(): void
    {
        $report = new Report('2026 Climate Finance Overview', 'Annual Report', 'reports/2026-overview.pdf');

        $report->incrementDownloadCount();
        $report->incrementDownloadCount();

        self::assertSame(2, $report->getDownloadCount());
    }
}
