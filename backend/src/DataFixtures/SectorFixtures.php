<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Sector;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

/**
 * The 5 sectors named as examples in the A1.3 schema design doc
 * (docs/superpowers/specs/2026-08-22-a13-timescaledb-schema-design.md,
 * "Sector (layer 1)" table: "e.g. Renewable Energy, Sustainable Transport,
 * Agriculture, Forestry, Adaptation") — the only sector names that appear
 * anywhere in the project's own sources, and they already total exactly the
 * 5 sectors called for. Used verbatim rather than inventing alternatives.
 *
 * Reference name for other fixtures: self::sectorReference($slug),
 * e.g. self::sectorReference('renewable-energy').
 */
final class SectorFixtures extends Fixture
{
    /**
     * [name, slug] — slug only used to build a stable, readable reference
     * name; not a persisted field (Sector has no slug column).
     *
     * @var list<array{0: string, 1: string}>
     */
    private const SECTORS = [
        ['Renewable Energy', 'renewable-energy'],
        ['Sustainable Transport', 'sustainable-transport'],
        ['Agriculture', 'agriculture'],
        ['Forestry', 'forestry'],
        ['Adaptation', 'adaptation'],
    ];

    public static function sectorReference(string $slug): string
    {
        return 'sector_'.$slug;
    }

    /**
     * Slugs only, for fixtures that need to iterate every sector without
     * duplicating the full SECTORS list.
     *
     * @return list<string>
     */
    public static function slugs(): array
    {
        return array_column(self::SECTORS, 1);
    }

    public function load(ObjectManager $manager): void
    {
        foreach (self::SECTORS as [$name, $slug]) {
            $sector = new Sector($name);
            $manager->persist($sector);
            $this->addReference(self::sectorReference($slug), $sector);
        }

        $manager->flush();
    }
}
