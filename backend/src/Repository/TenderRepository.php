<?php

declare(strict_types=1);

namespace App\Repository;

use App\Dto\TenderCriteria;
use App\Entity\Tender;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Tender>
 */
class TenderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Tender::class);
    }

    /**
     * A3.13 : GET /api/tenders. Même raisonnement que
     * OpportunityRepository::findByCriteria() (C1.8) - `status` n'est
     * jamais filtré ici en SQL (calculé en PHP par App\Service\
     * TenderService), `sector`/`category`/`search` le sont puisqu'ils sont
     * de vraies colonnes/champs stockés. Trié par échéance de dépôt des
     * offres croissante (le plus urgent d'abord).
     *
     * @return list<Tender>
     */
    public function findByCriteria(TenderCriteria $criteria): array
    {
        $qb = $this->createQueryBuilder('tender')->addSelect('sector')->leftJoin('tender.sector', 'sector');

        if (null !== $criteria->sectorId) {
            $qb->andWhere('tender.sector = :sectorId')->setParameter('sectorId', $criteria->sectorId);
        }

        if (null !== $criteria->category) {
            $qb->andWhere('tender.category = :category')->setParameter('category', $criteria->category);
        }

        if (null !== $criteria->searchTerm) {
            $qb->andWhere('UNACCENT(LOWER(tender.title)) LIKE UNACCENT(:searchPattern) OR UNACCENT(LOWER(tender.issuingOrganization)) LIKE UNACCENT(:searchPattern)')
                ->setParameter('searchPattern', $criteria->searchLikePattern());
        }

        return $qb
            ->orderBy('tender.submissionDeadline', 'ASC')
            ->addOrderBy('tender.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
