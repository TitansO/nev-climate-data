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
 * Fiabilisation 2026-10-06 (demande explicite utilisateur) :
 * `vulnerabilityIndex` était auparavant une estimation illustrative, non
 * vérifiée - remplacé ici par le score de vulnérabilité ND-GAIN
 * (Notre Dame Global Adaptation Initiative) OFFICIEL, vintage 2024 (le
 * plus récent publié), lu directement sur la page pays officielle
 * (https://gain-new.crc.nd.edu/country/{pays}, miroir à jour de
 * https://gain.nd.edu/our-work/country-index/) le 2026-10-06. Échelle
 * native 0.0 (aucune vulnérabilité) à 1.0 (vulnérabilité maximale) -
 * déjà la même échelle qu'attend `Country::$vulnerabilityIndex`.
 *
 * Point de vigilance documenté par la recherche : le Rwanda a la
 * vulnérabilité la plus élevée du lot (0.610, 11e pays le plus
 * vulnérable au monde sur ~185) malgré sa réputation de bonne
 * gouvernance climatique - cohérent avec un score ND-GAIN global moyen
 * (40.6/100, 141e rang mondial) porté par une meilleure "readiness" qui
 * compense partiellement sa vulnérabilité brute élevée. Ne pas corriger
 * cette valeur par intuition si elle semble surprenante : c'est la
 * donnée officielle.
 *
 * `population` reste un ordre de grandeur récent (Banque Mondiale, non
 * vérifié ligne à ligne) - hors du périmètre de cette fiabilisation,
 * qui portait sur l'indice de vulnérabilité explicitement flagué comme
 * non fiable, pas sur la population.
 */
final class CountryEquityDataFixtures extends Fixture implements DependentFixtureInterface
{
    /**
     * [isoCode, population, vulnerabilityIndex].
     *
     * Vulnerability index : ND-GAIN Country Index, vintage 2024, lu sur
     * https://gain-new.crc.nd.edu/country/{pays} le 2026-10-06. Côte
     * d'Ivoire : l'identifiant d'URL officiel du site source contient un
     * artefact d'encodage ("c-te-d-ivoire" plutôt que "cote-d-ivoire") -
     * sans conséquence ici, seule la valeur lue compte.
     *
     * @var list<array{0: string, 1: int, 2: float}>
     */
    private const EQUITY_DATA = [
        ['NGA', 223_800_000, 0.490],
        ['KEN', 55_100_000, 0.482],
        ['MAR', 37_840_000, 0.450],
        ['GHA', 33_470_000, 0.455],
        ['ETH', 126_500_000, 0.508],
        ['SEN', 17_760_000, 0.529],
        ['RWA', 13_780_000, 0.610],
        ['TZA', 67_440_000, 0.505],
        ['CIV', 28_870_000, 0.488],
        ['ZMB', 20_570_000, 0.492],
        ['UGA', 48_580_000, 0.536],
        ['CMR', 28_650_000, 0.478],
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
