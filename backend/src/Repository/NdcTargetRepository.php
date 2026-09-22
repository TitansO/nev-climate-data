<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\NdcTarget;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<NdcTarget>
 */
class NdcTargetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NdcTarget::class);
    }

    public function findOneByCountry(int $countryId): ?NdcTarget
    {
        return $this->findOneBy(['country' => $countryId]);
    }

    /**
     * C3.1 (Gap Detector) : uniquement les cibles CDN chiffrées
     * (`declaredNeedUsd` non NULL) - un besoin non déclaré publiquement
     * n'a rien à comparer, jamais traité comme un besoin nul. `sector`
     * distingue une cible économie entière (NULL, comparée au total reçu
     * par pays) d'une cible sectorielle (comparée au reçu de ce
     * secteur précis).
     *
     * @return list<NdcTarget>
     */
    public function findAllWithDeclaredNeed(): array
    {
        return $this->createQueryBuilder('target')
            ->addSelect('country')
            ->addSelect('sector')
            ->join('target.country', 'country')
            ->leftJoin('target.sector', 'sector')
            ->andWhere('target.declaredNeedUsd IS NOT NULL')
            ->getQuery()
            ->getResult();
    }
}
