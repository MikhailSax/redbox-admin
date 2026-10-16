<?php

namespace App\Repository;

use App\Entity\ProductSide;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProductSide>
 */
class ProductSideRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProductSide::class);
    }

    /**
     * Sides (advertising surfaces) of every structure, or only those named $names (one side letter).
     *
     * @param list<string> $names
     */
    public function countNamed(array $names = []): int
    {
        $qb = $this->createQueryBuilder('s')->select('COUNT(s.id)');
        if ([] !== $names) {
            $qb->andWhere('s.name IN (:names)')->setParameter('names', $names);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }
}
