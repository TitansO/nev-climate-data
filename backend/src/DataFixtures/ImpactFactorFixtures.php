<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\ImpactFactor;
use App\Entity\Sector;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * C2.5 : facteurs d'impact par secteur, utilisés par le calculateur
 * d'impact et le simulateur de finance mixte.
 *
 * Fiabilisation 2026-10-06 (demande explicite utilisateur) : recherche
 * menée pour vérifier chaque chiffre contre une publication réelle -
 * conclusion importante, la "GCF Results Handbook" (citée auparavant
 * pour 4 des 5 secteurs) N'EST PAS une source de benchmarks chiffrés :
 * c'est un document méthodologique qui définit des indicateurs (ex.
 * tCO2/passager-km), sans tableau de valeurs de référence USD/tCO2 ou
 * USD/bénéficiaire - confirmé via la page officielle
 * (greenclimate.fund/document/results-handbook). La citation "GCF
 * Results Handbook" était donc incorrecte pour ces chiffres, pas
 * seulement "non vérifiée". Seul `usdPerMwInstalled` (renouvelable) a pu
 * être rattaché à une vraie source chiffrée (IRENA). Les autres restent
 * des ordres de grandeur plausibles (cohérents avec la littérature
 * d'abattement carbone generaliste type McKinsey/REDD+ académique) mais
 * explicitement non officiellement sourcés - `uncertaintyPercent` relevé
 * en conséquence pour refléter honnêtement ce niveau de confiance
 * réel, plutôt que de supprimer ces chiffres (le produit a besoin d'un
 * ordre de grandeur pour fonctionner ; mieux vaut une incertitude
 * affichée plus large qu'une suppression qui casse la fonctionnalité).
 * `null` pour une métrique qui ne s'applique pas au secteur (l'adaptation
 * n'évite pas d'émissions par définition, par exemple), jamais un chiffre
 * inventé pour combler.
 */
final class ImpactFactorFixtures extends Fixture implements DependentFixtureInterface
{
    /**
     * [sectorSlug, usdPerTco2Avoided|null, usdPerMwInstalled|null, usdPerBeneficiary|null, uncertaintyPercent, methodologySource].
     *
     * @var list<array{0: string, 1: ?string, 2: ?string, 3: ?string, 4: float, 5: string}>
     */
    private const FACTORS = [
        // usdPerMwInstalled : seul chiffre de ce fichier adossé à une
        // source officielle chiffrée - IRENA, "Renewable Power Generation
        // Costs in 2024" (2025) : capex moyen mondial solaire PV 691 000
        // USD/MW, eolien terrestre 1 041 000 USD/MW ; Afrique solaire PV
        // utility-scale specifiquement 1 093 000 USD/MW en 2024. 1 100 000
        // retenu comme proche de ce dernier chiffre (pertinent pour un
        // produit centre sur l'Afrique), dans un secteur qui melange
        // plusieurs technologies a couts tres differents (solaire a
        // offshore/hydro, jusqu'a ~2 850 000 USD/MW).
        // usdPerTco2Avoided : IRENA ne publie PAS de cout d'evitement
        // $/tCO2 (capex/LCOE seulement, pas une courbe d'abattement) -
        // aucune source officielle trouvee ; 50 USD/tCO2 reste un ordre
        // de grandeur plausible mais optimiste (bas de fourchette) pour
        // du solaire/eolien deplacant du charbon.
        ['renewable-energy', '50.00', '1100000.00', null, 40.0, 'MW : IRENA Renewable Power Generation Costs in 2024 (confiance elevee). tCO2 : ordre de grandeur non sourcé officiellement (confiance faible)'],

        // Aucune source officielle ou academique solide trouvee pour l'un
        // ou l'autre chiffre (recherche du 2026-10-06) - GCF Results
        // Handbook ne les couvre pas, aucune alternative fiable identifiee
        // dans le temps de recherche disponible. A rechercher en priorite
        // : etudes de cas BRT/rail chiffrees (Banque Mondiale, BAD).
        ['sustainable-transport', '80.00', null, '500.00', 55.0, 'Ordre de grandeur non sourcé officiellement - aucune publication vérifiée trouvée (confiance faible), à rechercher en priorité'],

        // usdPerTco2Avoided : coherent avec les courbes de cout
        // d'abattement generalistes (McKinsey, seuil ~40 EUR/tCO2e pour
        // l'agriculture/foresterie a 2030), mais pas un chiffre agriculture
        // isole ni sourcé GCF. usdPerBeneficiary : aucune source trouvee.
        ['agriculture', '40.00', null, '300.00', 50.0, 'tCO2 : ordre de grandeur cohérent avec les courbes MAC généralistes (McKinsey), pas un chiffre GCF (confiance faible à moyenne). Bénéficiaire : non sourcé (confiance faible)'],

        // usdPerTco2Avoided : bien dans la fourchette basse typiquement
        // citee pour l'evitement de deforestation/REDD+ dans la
        // litterature academique (~5-20 USD/tCO2, l'option d'abattement
        // la moins chere toutes categories confondues) - confiance
        // moyenne a elevee sur l'ordre de grandeur, mais toujours pas la
        // source GCF citee auparavant. usdPerBeneficiary : aucune source
        // trouvee.
        ['forestry', '15.00', null, '200.00', 40.0, 'tCO2 : cohérent avec la littérature académique REDD+/évitement de déforestation (confiance moyenne à élevée), pas une source GCF. Bénéficiaire : non sourcé (confiance faible)'],

        // Le GCF mesure en realite la rentabilite de l'adaptation en
        // USD/DALY (annee de vie ajustee sur l'incapacite) evite, pas en
        // USD/beneficiaire brut - la metrique elle-meme differe des
        // pratiques GCF documentees, pas seulement le chiffre. Aucune
        // source officielle ne confirme 150 USD/beneficiaire.
        ['adaptation', null, null, '150.00', 55.0, 'Non sourcé officiellement - le GCF mesure en USD/DALY évité, pas en USD/bénéficiaire brut (confiance faible)'],
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
