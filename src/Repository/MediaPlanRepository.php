<?php

namespace App\Repository;

use App\Entity\MediaPlan;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MediaPlan>
 */
class MediaPlanRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MediaPlan::class);
    }

    /**
     * Newest first, items fetched along (the list shows counts and totals).
     *
     * @return list<MediaPlan>
     */
    public function findForList(?string $search = null, int $limit = 200): array
    {
        $qb = $this->createQueryBuilder('m')
            ->addSelect('i')
            ->leftJoin('m.items', 'i')
            ->orderBy('m.updatedAt', 'DESC');

        if (null !== $search && '' !== trim($search)) {
            $qb->andWhere('m.title LIKE :search OR m.clientName LIKE :search OR m.clientContact LIKE :search')
                ->setParameter('search', BookingRepository::like($search));
        }

        return array_slice($qb->getQuery()->getResult(), 0, $limit);
    }

    /**
     * @return list<MediaPlan>
     */
    public function findRecent(int $limit): array
    {
        return $this->createQueryBuilder('m')
            ->orderBy('m.updatedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
