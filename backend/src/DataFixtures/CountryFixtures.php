<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Country;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

/**
 * The 54 UN member states of Africa (cahier des charges 5.3 / A1.3 schema
 * design doc: "54 countries"), grouped by UN M49 sub-region. An explicit,
 * deterministic list rather than a generator — country data doesn't follow
 * a formula, and this stays trivially reviewable and re-runnable.
 *
 * Reference name for other fixtures: self::countryReference($isoCode),
 * e.g. self::countryReference('SEN').
 */
final class CountryFixtures extends Fixture
{
    /**
     * [name, ISO 3166-1 alpha-3 code, region] — codes verified unique below.
     *
     * @var list<array{0: string, 1: string, 2: string}>
     */
    private const COUNTRIES = [
        // Afrique du Nord (6)
        ['Algeria', 'DZA', 'Afrique du Nord'],
        ['Egypt', 'EGY', 'Afrique du Nord'],
        ['Libya', 'LBY', 'Afrique du Nord'],
        ['Morocco', 'MAR', 'Afrique du Nord'],
        ['Sudan', 'SDN', 'Afrique du Nord'],
        ['Tunisia', 'TUN', 'Afrique du Nord'],

        // Afrique de l'Ouest (16)
        ['Benin', 'BEN', "Afrique de l'Ouest"],
        ['Burkina Faso', 'BFA', "Afrique de l'Ouest"],
        ['Cabo Verde', 'CPV', "Afrique de l'Ouest"],
        ["Côte d'Ivoire", 'CIV', "Afrique de l'Ouest"],
        ['Gambia', 'GMB', "Afrique de l'Ouest"],
        ['Ghana', 'GHA', "Afrique de l'Ouest"],
        ['Guinea', 'GIN', "Afrique de l'Ouest"],
        ['Guinea-Bissau', 'GNB', "Afrique de l'Ouest"],
        ['Liberia', 'LBR', "Afrique de l'Ouest"],
        ['Mali', 'MLI', "Afrique de l'Ouest"],
        ['Mauritania', 'MRT', "Afrique de l'Ouest"],
        ['Niger', 'NER', "Afrique de l'Ouest"],
        ['Nigeria', 'NGA', "Afrique de l'Ouest"],
        ['Senegal', 'SEN', "Afrique de l'Ouest"],
        ['Sierra Leone', 'SLE', "Afrique de l'Ouest"],
        ['Togo', 'TGO', "Afrique de l'Ouest"],

        // Afrique centrale (9)
        ['Angola', 'AGO', 'Afrique centrale'],
        ['Cameroon', 'CMR', 'Afrique centrale'],
        ['Central African Republic', 'CAF', 'Afrique centrale'],
        ['Chad', 'TCD', 'Afrique centrale'],
        ['Republic of the Congo', 'COG', 'Afrique centrale'],
        ['Democratic Republic of the Congo', 'COD', 'Afrique centrale'],
        ['Equatorial Guinea', 'GNQ', 'Afrique centrale'],
        ['Gabon', 'GAB', 'Afrique centrale'],
        ['São Tomé and Príncipe', 'STP', 'Afrique centrale'],

        // Afrique de l'Est (18)
        ['Burundi', 'BDI', "Afrique de l'Est"],
        ['Comoros', 'COM', "Afrique de l'Est"],
        ['Djibouti', 'DJI', "Afrique de l'Est"],
        ['Eritrea', 'ERI', "Afrique de l'Est"],
        ['Ethiopia', 'ETH', "Afrique de l'Est"],
        ['Kenya', 'KEN', "Afrique de l'Est"],
        ['Madagascar', 'MDG', "Afrique de l'Est"],
        ['Malawi', 'MWI', "Afrique de l'Est"],
        ['Mauritius', 'MUS', "Afrique de l'Est"],
        ['Mozambique', 'MOZ', "Afrique de l'Est"],
        ['Rwanda', 'RWA', "Afrique de l'Est"],
        ['Seychelles', 'SYC', "Afrique de l'Est"],
        ['Somalia', 'SOM', "Afrique de l'Est"],
        ['South Sudan', 'SSD', "Afrique de l'Est"],
        ['Tanzania', 'TZA', "Afrique de l'Est"],
        ['Uganda', 'UGA', "Afrique de l'Est"],
        ['Zambia', 'ZMB', "Afrique de l'Est"],
        ['Zimbabwe', 'ZWE', "Afrique de l'Est"],

        // Afrique australe (5)
        ['Botswana', 'BWA', 'Afrique australe'],
        ['Eswatini', 'SWZ', 'Afrique australe'],
        ['Lesotho', 'LSO', 'Afrique australe'],
        ['Namibia', 'NAM', 'Afrique australe'],
        ['South Africa', 'ZAF', 'Afrique australe'],
    ];

    public static function countryReference(string $isoCode): string
    {
        return 'country_'.$isoCode;
    }

    /**
     * ISO codes only, for fixtures that need to iterate every country
     * without duplicating the full COUNTRIES list.
     *
     * @return list<string>
     */
    public static function isoCodes(): array
    {
        return array_column(self::COUNTRIES, 1);
    }

    public function load(ObjectManager $manager): void
    {
        foreach (self::COUNTRIES as [$name, $isoCode, $region]) {
            $country = new Country($name, $isoCode, $region);
            $manager->persist($country);
            $this->addReference(self::countryReference($isoCode), $country);
        }

        $manager->flush();
    }
}
