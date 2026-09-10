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
 * App\Controller\ReportController, A2.13). Point d'attention #55 (B3.3):
 * these used to be written to var/reports/ at fixture-load time - broke in
 * production because var/ is gitignored/dockerignored (never ships in the
 * image) and Render's disk is ephemeral anyway, so GET /api/reports/{id}/
 * download 404'd on every environment that hadn't just run this fixture on
 * its own local disk. The 4 real (minimal-but-valid, one-page) placeholder
 * PDFs now live as committed, git-tracked static files under
 * backend/resources/reports/ - this fixture only ever references them, it
 * never writes file bytes at runtime.
 */
final class ReportFixtures extends Fixture implements DependentFixtureInterface
{
    public function getDependencies(): array
    {
        return [CountryFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        $report = new Report('2025 Global Climate Finance Overview', 'Annual Report', 'reports/2025-global-climate-finance-overview.pdf');
        $report->setStatus(ReportStatus::Published);
        $report->setPublicationDate(new \DateTimeImmutable('2026-02-15'));
        $manager->persist($report);

        $report = new Report('West Africa Regional Climate Finance Report', 'Regional Report', 'reports/2025-west-africa-regional-report.pdf');
        $report->setStatus(ReportStatus::Published);
        $report->setRegion("Afrique de l'Ouest");
        $report->setPublicationDate(new \DateTimeImmutable('2026-03-01'));
        $manager->persist($report);

        /** @var Country $senegal */
        $senegal = $this->getReference(CountryFixtures::countryReference('SEN'), Country::class);
        $report = new Report('Senegal - Climate Finance Country Profile', 'Country Report', 'reports/2025-senegal-country-profile.pdf');
        $report->setStatus(ReportStatus::Published);
        $report->setCountry($senegal);
        $report->setPublicationDate(new \DateTimeImmutable('2026-04-10'));
        $manager->persist($report);

        /** @var Country $kenya */
        $kenya = $this->getReference(CountryFixtures::countryReference('KEN'), Country::class);
        $report = new Report('Kenya - Climate Finance Country Profile', 'Country Report', 'reports/2025-kenya-country-profile.pdf');
        $report->setCountry($kenya);
        // Left in Draft (the constructor's default): no publicationDate yet,
        // and no PDF committed either - a draft has nothing to download
        // (App\Repository\ReportRepository::findOnePublished() 404s on it
        // regardless).
        $manager->persist($report);

        /** @var Country $nigeria */
        $nigeria = $this->getReference(CountryFixtures::countryReference('NGA'), Country::class);
        $report = new Report('Nigeria - Climate Finance Country Profile', 'Country Report', 'reports/2025-nigeria-country-profile.pdf');
        $report->setStatus(ReportStatus::Published);
        $report->setCountry($nigeria);
        $report->setPublicationDate(new \DateTimeImmutable('2026-05-20'));
        $manager->persist($report);

        $report = new Report('2026 Sector Deep Dive - Renewable Energy', 'Sector Report', 'reports/2026-renewable-energy-deep-dive.pdf');
        // Left in Draft: still being written, no publicationDate, no PDF
        // committed (see the Kenya report above for the same reasoning).
        $manager->persist($report);

        $manager->flush();
    }
}
