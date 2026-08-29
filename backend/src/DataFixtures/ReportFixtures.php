<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Country;
use App\Entity\Enum\ReportStatus;
use App\Entity\Report;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * A small, deliberately limited set of demonstration reports - enough to
 * exercise the Report entity's nullable country/region/publicationDate and
 * both ReportStatus values, not a realistic publication history.
 *
 * pdfFile values are relative paths under $reportStorageDir (see
 * App\Controller\ReportController, A2.13) - each one is backed by a real,
 * minimal-but-valid one-page PDF written to disk by this fixture (see
 * self::PLACEHOLDER_PDF_BYTES), so GET /api/reports/{id}/download actually
 * streams a file rather than 404ing on missing content.
 */
final class ReportFixtures extends Fixture implements DependentFixtureInterface
{
    /**
     * The smallest PDF that every major reader (including browsers) will
     * still open without complaint: one empty A4-ish page, no fonts, no
     * content stream worth mentioning. Good enough to prove the tracked
     * download path works end-to-end - not a real report.
     */
    private const PLACEHOLDER_PDF_BYTES = <<<'PDF'
        %PDF-1.4
        1 0 obj
        << /Type /Catalog /Pages 2 0 R >>
        endobj
        2 0 obj
        << /Type /Pages /Kids [3 0 R] /Count 1 >>
        endobj
        3 0 obj
        << /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] >>
        endobj
        xref
        0 4
        0000000000 65535 f
        0000000009 00000 n
        0000000058 00000 n
        0000000115 00000 n
        trailer
        << /Size 4 /Root 1 0 R >>
        startxref
        190
        %%EOF
        PDF;

    public function __construct(private readonly string $reportStorageDir)
    {
    }

    public function getDependencies(): array
    {
        return [CountryFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        $pdfFiles = [];

        $pdfFiles[] = $pdfFile = 'reports/2025-global-climate-finance-overview.pdf';
        $report = new Report('2025 Global Climate Finance Overview', 'Annual Report', $pdfFile);
        $report->setStatus(ReportStatus::Published);
        $report->setPublicationDate(new \DateTimeImmutable('2026-02-15'));
        $manager->persist($report);

        $pdfFiles[] = $pdfFile = 'reports/2025-west-africa-regional-report.pdf';
        $report = new Report('West Africa Regional Climate Finance Report', 'Regional Report', $pdfFile);
        $report->setStatus(ReportStatus::Published);
        $report->setRegion("Afrique de l'Ouest");
        $report->setPublicationDate(new \DateTimeImmutable('2026-03-01'));
        $manager->persist($report);

        /** @var Country $senegal */
        $senegal = $this->getReference(CountryFixtures::countryReference('SEN'), Country::class);
        $pdfFiles[] = $pdfFile = 'reports/2025-senegal-country-profile.pdf';
        $report = new Report('Senegal — Climate Finance Country Profile', 'Country Report', $pdfFile);
        $report->setStatus(ReportStatus::Published);
        $report->setCountry($senegal);
        $report->setPublicationDate(new \DateTimeImmutable('2026-04-10'));
        $manager->persist($report);

        /** @var Country $kenya */
        $kenya = $this->getReference(CountryFixtures::countryReference('KEN'), Country::class);
        $report = new Report('Kenya — Climate Finance Country Profile', 'Country Report', 'reports/2025-kenya-country-profile.pdf');
        $report->setCountry($kenya);
        // Left in Draft (the constructor's default): no publicationDate yet,
        // and no PDF written to disk either - a draft has nothing to
        // download (App\Repository\ReportRepository::findOnePublished()
        // 404s on it regardless).
        $manager->persist($report);

        /** @var Country $nigeria */
        $nigeria = $this->getReference(CountryFixtures::countryReference('NGA'), Country::class);
        $pdfFiles[] = $pdfFile = 'reports/2025-nigeria-country-profile.pdf';
        $report = new Report('Nigeria — Climate Finance Country Profile', 'Country Report', $pdfFile);
        $report->setStatus(ReportStatus::Published);
        $report->setCountry($nigeria);
        $report->setPublicationDate(new \DateTimeImmutable('2026-05-20'));
        $manager->persist($report);

        $report = new Report('2026 Sector Deep Dive — Renewable Energy', 'Sector Report', 'reports/2026-renewable-energy-deep-dive.pdf');
        // Left in Draft: still being written, no publicationDate, no PDF on
        // disk (see the Kenya report above for the same reasoning).
        $manager->persist($report);

        $manager->flush();

        foreach ($pdfFiles as $pdfFile) {
            $this->writePlaceholderPdf($pdfFile);
        }
    }

    private function writePlaceholderPdf(string $relativePath): void
    {
        $absolutePath = $this->reportStorageDir.'/'.$relativePath;
        $directory = \dirname($absolutePath);
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        file_put_contents($absolutePath, self::PLACEHOLDER_PDF_BYTES);
    }
}
