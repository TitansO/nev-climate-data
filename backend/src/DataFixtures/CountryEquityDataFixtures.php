<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Country;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * C3.3 (Equity Score) : population + indice de vulnérabilité climatique
 * pour le même sous-ensemble de 12 pays que NdcTargetFixtures (F7) -
 * aucune autre donnée de population/vulnérabilité n'existe dans ce
 * schéma, et EquityScoreService ne peut calculer un score complet que
 * pour un pays disposant des deux (sinon `available: false`, jamais un
 * score partiel présenté comme complet).
 *
 * IMPORTANT - fiabilité des chiffres, même avertissement que
 * NdcTargetFixtures : `population` est un ordre de grandeur récent
 * (Banque Mondiale, non vérifié ligne à ligne) ; `vulnerabilityIndex`
 * est une ESTIMATION ILLUSTRATIVE 0.0-1.0 inspirée de l'ordonnancement
 * relatif généralement observé dans l'indice ND-GAIN (Notre Dame Global
 * Adaptation Initiative) pour cette région - PAS une extraction vérifiée
 * du jeu de données ND-GAIN officiel (https://gain.nd.edu/our-work/country-index/).
 * À vérifier/corriger avant tout usage réel en dehors de la démonstration
 * de C3.3.
 */
final class CountryEquityDataFixtures extends Fixture implements DependentFixtureInterface
{
    /**
     * [isoCode, population, vulnerabilityIndex].
     *
     * @var list<array{0: string, 1: int, 2: float}>
     */
    private const EQUITY_DATA = [
        ['NGA', 223_800_000, 0.47],
        ['KEN', 55_100_000, 0.46],
        ['MAR', 37_840_000, 0.36],
        ['GHA', 33_470_000, 0.41],
        ['ETH', 126_500_000, 0.53],
        ['SEN', 17_760_000, 0.44],
        ['RWA', 13_780_000, 0.45],
        ['TZA', 67_440_000, 0.44],
        ['CIV', 28_870_000, 0.42],
        ['ZMB', 20_570_000, 0.47],
        ['UGA', 48_580_000, 0.44],
        ['CMR', 28_650_000, 0.45],
    ];

    public function getDependencies(): array
    {
        return [CountryFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        foreach (self::EQUITY_DATA as [$isoCode, $population, $vulnerabilityIndex]) {
            /** @var Country $country */
            $country = $this->getReference(CountryFixtures::countryReference($isoCode), Country::class);
            $country->setPopulation($population);
            $country->setVulnerabilityIndex($vulnerabilityIndex);
        }

        $manager->flush();
    }
}
