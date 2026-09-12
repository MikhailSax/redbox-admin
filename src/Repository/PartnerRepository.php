<?php

namespace App\Repository;

use App\Entity\Partner;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Partner>
 */
class PartnerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Partner::class);
    }

    /**
     * @return list<Partner>
     */
    public function search(string $term, int $limit): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.name LIKE :term OR p.contactName LIKE :term OR p.phone LIKE :term OR p.inn LIKE :term')
            ->setParameter('term', BookingRepository::like($term))
            ->orderBy('p.name', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
