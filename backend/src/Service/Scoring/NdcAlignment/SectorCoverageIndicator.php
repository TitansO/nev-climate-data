<?php

declare(strict_types=1);

namespace App\Service\Scoring\NdcAlignment;

use App\Entity\NdcTarget;
use App\Repository\FundingRepository;
use App\Repository\SectorRepository;
use App\Service\Scoring\ScoreIndicatorInterface;

/**
 * C2.6 (0,5) : "couverture des secteurs prioritaires CDN par des
 * financements réels". NdcTarget::$sector est nullable (la plupart des
 * CDN fixent une cible économie entière, jamais une donnée manquante -
 * voir le docblock de l'entité ; confirmé par NdcTargetFixtures, dont les
 * 12 lignes sont toutes économie entière) : cible sectorielle précise ->
 * ce secteur précis a-t-il du financement réel (0/1) ; cible économie
 * entière -> part des secteurs de la plateforme ayant du financement réel
 * pour ce pays, seul proxy disponible pour "couverture" quand aucun
 * secteur prioritaire n'est distingué.
 *
 * @implements ScoreIndicatorInterface<NdcTarget>
 */
final class SectorCoverageIndicator implements ScoreIndicatorInterface
{
    public function __construct(
        private readonly FundingRepository $fundingRepository,
        private readonly SectorRepository $sectorRepository,
    ) {
    }

    public function code(): string
    {
        return 'sector_coverage';
    }

    public function weight(): float
    {
        return 0.5;
    }

    public function compute(mixed $subject): float
    {
        $countryId = (int) $subject->getCountry()->getId();
        $fundedSectorIds = $this->fundingRepository->findFundedSectorIdsForCountry($countryId);

        $prioritySector = $subject->getSector();
        if (null !== $prioritySector) {
            return \in_array((int) $prioritySector->getId(), $fundedSectorIds, true) ? 1.0 : 0.0;
        }

        $totalSectors = $this->sectorRepository->count([]);

        return $totalSectors > 0 ? \count($fundedSectorIds) / $totalSectors : 0.0;
    }

    public function explain(mixed $subject): string
    {
        $countryId = (int) $subject->getCountry()->getId();
        $fundedSectorIds = $this->fundingRepository->findFundedSectorIdsForCountry($countryId);
        $prioritySector = $subject->getSector();

        if (null !== $prioritySector) {
            $covered = \in_array((int) $prioritySector->getId(), $fundedSectorIds, true) ? 'oui' : 'non';

            return \sprintf('Secteur prioritaire CDN : "%s". Financement réel dans ce secteur : %s.', $prioritySector->getName(), $covered);
        }

        $totalSectors = $this->sectorRepository->count([]);

        return \sprintf('Cible CDN économie entière (pas de secteur prioritaire distingué). Secteurs avec financement réel : %d/%d.', \count($fundedSectorIds), $totalSectors);
    }
}
