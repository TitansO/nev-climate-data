<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Country;
use App\Entity\NdcTarget;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * F7 (fondation du sprint C1-C3) : cibles CDN de pays NEV documentés
 * publiquement - les autres pays n'ont volontairement aucune ligne
 * (`NdcTargetRepository::findOneByCountry()` retourne null pour eux, à
 * distinguer d'une cible à 0%).
 *
 * Fiabilisation 2026-10-06 (demande explicite utilisateur) : les valeurs
 * précédentes étaient une "reconstitution de mémoire, non vérifiée".
 * Remplacées ici par des données réelles, chacune sourcée individuellement
 * ci-dessous - recherche effectuée le 2026-10-06 via Climate Action
 * Tracker, Climate Watch, Enerdata, Climate Policy Initiative, lecture
 * directe de PDF officiels CCNUCC quand possible, et recoupement presse
 * spécialisée. 2025-2026 est la période de soumission des "CDN 3.0"
 * (nouveaux objectifs à horizon 2035) sous l'Accord de Paris - plusieurs
 * pays ci-dessous ont déjà basculé sur cette nouvelle version, d'autres
 * restent sur leur CDN actualisée précédente (horizon 2030) faute de CDN
 * 3.0 confirmée. `reductionTargetPercent` est systématiquement la cible
 * CONDITIONNELLE TOTALE (inconditionnel + effort additionnel sous
 * assistance internationale), jamais la part inconditionnelle isolée -
 * `conditional` vaut donc `true` sur toutes ces lignes, même convention
 * qu'avant. `declaredNeedUsd` : `null` chaque fois qu'un montant total
 * fiable et non ambigu n'a pas pu être établi, plutôt qu'un chiffre
 * approximatif présenté comme certain.
 *
 * Sénégal et Tanzanie, présents dans la version précédente de cette
 * fixture, ont été RETIRÉS (recherche du 2026-10-06) : aucune preuve de
 * dépôt officiel de CDN 3.0 à la CCNUCC pour le Sénégal (absent du
 * registre alors que les pays voisins y figurent déjà) et confiance
 * insuffisante même sur la répartition de base de la CDN tanzanienne
 * actuelle - mieux vaut l'absence de donnée qu'une donnée non confirmée
 * dans un produit vendu à des clients.
 *
 * À revérifier périodiquement : plusieurs CDN 3.0 ci-dessous ont été
 * soumises entre septembre 2025 et février 2026 - un nouveau cycle de
 * mise à jour peut survenir. Registre officiel : https://unfccc.int/NDCREG
 */
final class NdcTargetFixtures extends Fixture implements DependentFixtureInterface
{
    /**
     * [isoCode, reductionTargetPercent, declaredNeedUsd|null, targetYear, sourceNote].
     *
     * @var list<array{0: string, 1: float, 2: ?string, 3: int, 4: string}>
     */
    private const TARGETS = [
        // CDN 3.0, soumise le 22/09/2025, horizon 2035. 32% vs niveau
        // absolu de 2018 (PAS vs un scénario BAU - exception méthodo-
        // logique propre à ce pays). Structure 80/20 : 270 Md USD
        // conditionnels (80%) + 67 Md USD inconditionnels (20%) = 337 Md
        // USD, confirmé comme le besoin TOTAL par 2 sources indépendantes
        // (afripoli.org, gazettengr.com). Source : unfccc.int (PDF
        // "Nigeria NDC 3.0 - Transmission Version 2"), climateactiontracker.org.
        ['NGA', 32.0, '337000000000.00', 2035, 'NDC 3.0 (22/09/2025) - unfccc.int + afripoli.org, confiance elevee'],

        // CDN "2031-2035", soumise le 30/04/2025, horizon 2035. 35% vs
        // BAU (215 MtCO2e). La part inconditionnelle (~7%) N'EST PAS
        // citee explicitement dans le texte officiel pour cette version
        // 3.0 - seul "20% de l'effort total (15 MtCO2e) est domestique"
        // y figure ; le "7%" vient de l'ancienne CDN 2020 et n'est repris
        // qu'par coincidence arithmetique (confirme par Climate Action
        // Tracker, lecture directe du texte officiel cite). Financement :
        // KES 7 000 milliards (2031-2035), soit environ 54 Md USD a un
        // taux approximatif de ~130 KES/USD - CONVERSION, pas un montant
        // donne en USD par la source elle-meme.
        ['KEN', 35.0, '54000000000.00', 2035, 'NDC 2031-2035 (30/04/2025) - climateactiontracker.org, conversion KES->USD approximative'],

        // CDN 3.0, soumise le 30/09/2025, horizon 2035. 21,6% inconditionnel
        // + 31,4% additionnel conditionnel = 53% total vs BAU. Besoin
        // total declare : portefeuille de 96 Md USD (60 Md mitigation +
        // 36 Md adaptation) - fnh.ma. Le sous-montant "~33 Md USD" parfois
        // cite correspond seulement a la part conditionnelle (agregation
        // sectorielle de presse specialisee, pas une ligne unique du
        // document officiel - capmad.com).
        ['MAR', 53.0, '96000000000.00', 2035, 'NDC 3.0 (30/09/2025) - fnh.ma + capmad.com, confiance elevee (ordre de grandeur)'],

        // NDC actualisee de 2021 (approuvee janvier 2022) - PAS de CDN 3.0
        // officiellement deposee a la CCNUCC a la date de cette recherche
        // (une version 2025-2035 est en preparation mais non finalisee).
        // 15% inconditionnel + 30% additionnel conditionnel = 45% total
        // vs BAU (73,95 MtCO2e a 2030). Besoin total pour les composantes
        // chiffrees : 3,9 Md USD (16 programmes inconditionnels) + 5,4 Md
        // USD (31 programmes conditionnels) = 9,3 Md USD - ghanaiantimes.com.gh,
        // unep.org/ndc/action-area/ghana.
        ['GHA', 45.0, '9300000000.00', 2030, 'NDC actualisee 2021 - unep.org, confiance elevee'],

        // CDN 3.0, soumise le 26/09/2025, horizon 2035. 30,7% inconditionnel
        // + 39,6% additionnel conditionnel = 70,3% total vs BAU revise.
        // Financement : AMBIGU entre un besoin annuel (10,6 Md USD/an,
        // confirme par Climate Policy Initiative) et une enveloppe
        // cumulee de mobilisation domestique (22,13 Md USD sur 2025-2035,
        // PAS confirme comme le besoin TOTAL du plan) - laisse a `null`
        // plutot que de presenter l'un des deux comme le montant total.
        ['ETH', 70.3, null, 2035, 'NDC 3.0 (26/09/2025) - climatepolicyinitiative.org, % confiance elevee, financement ambigu'],

        // CDN 3.0 (08/12/2025), horizon 2035. 7% inconditionnel + 53%
        // additionnel conditionnel... en realite le texte officiel donne
        // 7% inconditionnel / 53% TOTAL conditionnel (pas additionnel) -
        // lu directement dans le PDF officiel CCNUCC "Rwanda NDC3.0 Final".
        // Financement : 12 Md USD pour la feuille de route complete,
        // confirme par presse rwandaise (newtimes.co.rw) et le PDF
        // officiel. ATTENTION (documente dans le PDF source) : le Rwanda
        // a aussi la vulnerabilite ND-GAIN la plus elevee du pool equite
        // (voir CountryEquityDataFixtures) malgre sa bonne gouvernance
        // climatique - deux donnees independantes, pas une erreur.
        ['RWA', 53.0, '12000000000.00', 2035, 'NDC 3.0 (08/12/2025) - PDF officiel CCNUCC + newtimes.co.rw, confiance elevee'],

        // CDN 3.0 pour la periode 2031-2035, soumise le 28/10/2025 (date
        // confirmee par enerdata.net, source independante). 33% inconditionnel
        // + 41% additionnel conditionnel = 74% total vs BAU (quasi-neutralite
        // carbone). Financement : 10,6 Md USD (mesures inconditionnelles,
        // dont 10,1 Md pour l'attenuation) + 48,2 Md USD (mesures
        // conditionnelles, dont 16,9 Md pour l'attenuation) = 58,8 Md USD
        // total - enerdata.net, carbon-pulse.com, climate-laws.org.
        ['CIV', 74.0, '58800000000.00', 2035, 'NDC 3.0 (28/10/2025) - enerdata.net + carbon-pulse.com, confiance elevee'],

        // CDN 3.0 confirmee par lecture DIRECTE du PDF officiel CCNUCC
        // ("Final Submission of Zambia NDC 3.0") : 25% inconditionnel /
        // jusqu'a 47% conditionnel vs BAU, reference 2010, horizon UNIQUE
        // 2030 (pas 2035 - le texte officiel precise explicitement que
        // "les reductions relatives vs BAU restent globalement conformes
        // a la CDN 2.0", seule la trajectoire BAU absolue est recalculee).
        // Confirme independamment par Zambia Monitor. Aucun montant de
        // financement chiffre trouve pour cette version.
        ['ZMB', 47.0, null, 2030, 'NDC 3.0 - lecture directe PDF officiel CCNUCC, confiance elevee'],

        // NDC actualisee de 2022 - pas de CDN 3.0 confirmee a la CCNUCC a
        // la date de cette recherche (en preparation depuis juillet 2025,
        // non finalisee). 5,9% inconditionnel + 18,8% additionnel
        // conditionnel = 24,7% total vs BAU. Aucun montant en USD trouve
        // (seule une reduction absolue de 36,75 MtCO2e est mentionnee).
        ['UGA', 24.7, null, 2030, 'NDC actualisee 2022 - development.finance.go.ug, confiance elevee'],

        // CDN 3.0 ("CDN 3.0 CMR FINAL") confirmee par lecture DIRECTE du
        // PDF officiel CCNUCC : 14% inconditionnel + 27% conditionnel =
        // 41% total a horizon 2035 (palier intermediaire 2030 : 13% + 25%
        // = 38%), reference BAU2035/inventaire base 2022. Aucun montant
        // de financement chiffre trouve dans l'extrait lu.
        ['CMR', 41.0, null, 2035, 'CDN 3.0 - lecture directe PDF officiel CCNUCC, confiance elevee'],
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
        foreach (self::TARGETS as [$isoCode, $percent, $declaredNeedUsd, $targetYear]) {
            /** @var Country $country */
            $country = $this->getReference(CountryFixtures::countryReference($isoCode), Country::class);

            $ndcTarget = new NdcTarget($country, $targetYear);
            $ndcTarget->setReductionTargetPercent($percent);
            $ndcTarget->setDeclaredNeedUsd($declaredNeedUsd);
            $ndcTarget->setConditional(true);

            $manager->persist($ndcTarget);
            $this->addReference(self::reference($isoCode), $ndcTarget);
        }

        $manager->flush();
    }
}
