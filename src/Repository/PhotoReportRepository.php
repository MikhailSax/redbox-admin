<?php

namespace App\Repository;

use App\Entity\PhotoReport;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PhotoReport>
 */
class PhotoReportRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PhotoReport::class);
    }

    /**
     * A client's photo reports with their photos and structures, the latest shots first.
     *
     * @return list<PhotoReport>
     */
    public function findForClient(User $client): array
    {
        return $this->createQueryBuilder('r')
            ->addSelect('ph', 'p')
            ->leftJoin('r.photos', 'ph')
            ->leftJoin('r.product', 'p')
            ->andWhere('r.client = :client')
            ->setParameter('client', $client)
            ->orderBy('r.shotAt', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @param list<int> $clientIds
     *
     * @return array<int, int> client id => reports
     */
    public function countByClient(array $clientIds): array
    {
        if ([] === $clientIds) {
            return [];
        }
        $rows = $this->createQueryBuilder('r')
            ->select('IDENTITY(r.client) AS client', 'COUNT(r.id) AS n')
            ->andWhere('r.client IN (:ids)')
            ->setParameter('ids', $clientIds)
            ->groupBy('r.client')
            ->getQuery()
            ->getArrayResult();

        return array_combine(array_map('intval', array_column($rows, 'client')), array_map('intval', array_column($rows, 'n')));
    }
}
