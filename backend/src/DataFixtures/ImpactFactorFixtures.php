<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\ImpactFactor;
use App\Entity\Sector;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * C2.5 : facteurs d'impact par secteur, un ordre de grandeur inspiré de
 * publications publiques (IRENA "Renewable Power Generation Costs",
 * GCF "Results Handbook") au moment de la rédaction de cette fixture -
 * PAS une extraction vérifiée ligne par ligne de ces rapports. Même
 * réserve que NdcTargetFixtures : à vérifier/corriger contre les
 * publications officielles avant tout usage réel hors de la
 * démonstration de C2.5. `null` pour une métrique qui ne s'applique pas
 * au secteur (l'adaptation n'évite pas d'émissions par définition, par
 * exemple), jamais un chiffre inventé pour combler.
 */
final class ImpactFactorFixtures extends Fixture implements DependentFixtureInterface
{
    /**
     * [sectorSlug, usdPerTco2Avoided|null, usdPerMwInstalled|null, usdPerBeneficiary|null, uncertaintyPercent, methodologySource].
     *
     * @var list<array{0: string, 1: ?string, 2: ?string, 3: ?string, 4: float, 5: string}>
     */
    private const FACTORS = [
        ['renewable-energy', '50.00', '1200000.00', null, 30.0, 'IRENA - Renewable Power Generation Costs (ordre de grandeur, non vérifié ligne à ligne)'],
        ['sustainable-transport', '80.00', null, '500.00', 35.0, 'GCF Results Handbook - secteur transport (ordre de grandeur, non vérifié ligne à ligne)'],
        ['agriculture', '40.00', null, '300.00', 40.0, 'GCF Results Handbook - agriculture climato-intelligente (ordre de grandeur, non vérifié ligne à ligne)'],
        ['forestry', '15.00', null, '200.00', 35.0, 'GCF Results Handbook - REDD+/reboisement (ordre de grandeur, non vérifié ligne à ligne)'],
        ['adaptation', null, null, '150.00', 45.0, 'GCF Results Handbook - programmes de résilience (ordre de grandeur, non vérifié ligne à ligne)'],
    ];

    public function getDependencies(): array
    {
        return [SectorFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        foreach (self::FACTORS as [$sectorSlug, $usdPerTco2Avoided, $usdPerMwInstalled, $usdPerBeneficiary, $uncertaintyPercent, $methodologySource]) {
            /** @var Sector $sector */
            $sector = $this->getReference(SectorFixtures::sectorReference($sectorSlug), Sector::class);

            $impactFactor = new ImpactFactor($sector, $uncertaintyPercent, $methodologySource);
            $impactFactor->setUsdPerTco2Avoided($usdPerTco2Avoided);
            $impactFactor->setUsdPerMwInstalled($usdPerMwInstalled);
            $impactFactor->setUsdPerBeneficiary($usdPerBeneficiary);

            $manager->persist($impactFactor);
        }

        $manager->flush();
    }
}
