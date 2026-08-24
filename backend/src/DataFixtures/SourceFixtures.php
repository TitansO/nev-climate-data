<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Enum\SourceReliability;
use App\Entity\Enum\SourceType;
use App\Entity\Source;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

/**
 * One source per SourceType case (cahier des charges 6.2 connectors named
 * in the implementation plan: World Bank, GCF, PDF extractor), plus the
 * internal-demo source used by every Funding fixture record — never an
 * invented enum value.
 *
 * Reference name for other fixtures: self::sourceReference($slug).
 */
final class SourceFixtures extends Fixture
{
    public const REF_INTERNAL_DEMO = 'source_internal-demo';

    /**
     * [name, slug, SourceType, SourceReliability].
     *
     * @var list<array{0: string, 1: string, 2: SourceType, 3: SourceReliability}>
     */
    private const SOURCES = [
        ['World Bank Data API', 'world-bank-api', SourceType::OfficialApi, SourceReliability::High],
        ['Green Climate Fund — Annual Report (PDF)', 'gcf-pdf-report', SourceType::PdfReport, SourceReliability::Medium],
        ['GreenAccess Platform Events', 'greenaccess-events', SourceType::GreenAccessEvent, SourceReliability::Medium],
        ['NEV Climate Data — Internal Demonstration', 'internal-demo', SourceType::InternalDemo, SourceReliability::Low],
    ];

    public static function sourceReference(string $slug): string
    {
        return 'source_'.$slug;
    }

    public function load(ObjectManager $manager): void
    {
        foreach (self::SOURCES as [$name, $slug, $type, $reliability]) {
            $source = new Source($name, $type, $reliability);
            $manager->persist($source);
            $this->addReference(self::sourceReference($slug), $source);
        }

        $manager->flush();
    }
}
