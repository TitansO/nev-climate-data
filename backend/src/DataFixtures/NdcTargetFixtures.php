<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Country;
use App\Entity\NdcTarget;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * F7 (fondation du sprint C1-C3) : cibles CDN de 12 des pays NEV les plus
 * documentés publiquement (sur les ~15 visés par le plan) - les 42 autres
 * pays n'ont volontairement aucune ligne (`NdcTargetRepository::findOneByCountry()`
 * retourne null pour eux, à distinguer d'une cible à 0%).
 *
 * IMPORTANT - fiabilité des chiffres : ces pourcentages et montants sont
 * une reconstitution de mémoire des cibles les plus souvent citées dans
 * la presse et les résumés publics des CDN de chaque pays au moment de
 * la rédaction de cette fixture - PAS une extraction vérifiée du registre
 * officiel des CDN de la CCNUCC (https://unfccc.int/NDCREG). Chaque
 * `reductionTargetPercent` est la cible CONDITIONNELLE (la plus ambitieuse,
 * la plus souvent citée) quand un pays en publie une distincte de sa
 * cible inconditionnelle - `conditional` vaut donc `true` sur toutes ces
 * lignes. `declaredNeedUsd` n'est renseigné que pour les 2 pays (Nigeria,
 * Maroc) dont le chiffre est suffisamment fréquemment cité pour être
 * repris avec confiance ; `null` ailleurs plutôt qu'un montant inventé.
 * À vérifier/corriger contre le registre officiel avant tout usage réel
 * en dehors de la démonstration de C2.6/C3.1/C3.3.
 */
final class NdcTargetFixtures extends Fixture implements DependentFixtureInterface
{
    private const TARGET_YEAR = 2030;

    /**
     * [isoCode, reductionTargetPercent, declaredNeedUsd|null].
     *
     * @var list<array{0: string, 1: float, 2: ?string}>
     */
    private const TARGETS = [
        ['NGA', 45.0, '177000000000.00'],
        ['KEN', 32.0, null],
        ['MAR', 45.5, '38800000000.00'],
        ['GHA', 64.0, null],
        ['ETH', 68.8, null],
        ['SEN', 29.5, null],
        ['RWA', 38.0, null],
        ['TZA', 30.0, null],
        ['CIV', 30.4, null],
        ['ZMB', 47.0, null],
        ['UGA', 24.7, null],
        ['CMR', 35.0, null],
    ];

    public static function reference(string $isoCode): string
    {
        return 'ndc_target_'.$isoCode;
    }

    public function getDependencies(): array
    {
        return [CountryFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        foreach (self::TARGETS as [$isoCode, $percent, $declaredNeedUsd]) {
            /** @var Country $country */
            $country = $this->getReference(CountryFixtures::countryReference($isoCode), Country::class);

            $ndcTarget = new NdcTarget($country, self::TARGET_YEAR);
            $ndcTarget->setReductionTargetPercent($percent);
            $ndcTarget->setDeclaredNeedUsd($declaredNeedUsd);
            $ndcTarget->setConditional(true);

            $manager->persist($ndcTarget);
            $this->addReference(self::reference($isoCode), $ndcTarget);
        }

        $manager->flush();
    }
}
