<?php

namespace App\Repository;

use App\Entity\Lead;
use App\Enum\LeadStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Lead>
 */
class LeadRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Lead::class);
    }

    /**
     * Newest first; $q matches the contact, the company, the phone or the email.
     *
     * @return list<Lead>
     */
    public function findForList(?LeadStatus $status = null, ?string $q = null, int $limit = 200): array
    {
        $qb = $this->createQueryBuilder('l')
            ->addSelect('i')
            ->leftJoin('l.items', 'i')
            ->orderBy('l.createdAt', 'DESC')
            ->addOrderBy('l.id', 'DESC')
            ->setMaxResults($limit);

        if (null !== $status) {
            $qb->andWhere('l.status = :status')->setParameter('status', $status);
        }

        if (null !== $q && '' !== trim($q)) {
            $qb->andWhere('l.contactName LIKE :q OR l.companyName LIKE :q OR l.phone LIKE :q OR l.email LIKE :q')
                ->setParameter('q', BookingRepository::like($q));
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @return array<string, int> status => leads
     */
    public function countByStatus(): array
    {
        $counts = array_fill_keys(array_map(static fn (LeadStatus $s) => $s->value, LeadStatus::cases()), 0);
        foreach ($this->createQueryBuilder('l')->select('l.status AS status', 'COUNT(l.id) AS n')->groupBy('l.status')->getQuery()->getArrayResult() as $row) {
            $status = $row['status'] instanceof LeadStatus ? $row['status'] : LeadStatus::from((string) $row['status']);
            $counts[$status->value] = (int) $row['n'];
        }

        return $counts;
    }

    /** Requests nobody has looked at yet, for the sidebar badge */
    public function countNew(): int
    {
        return (int) $this->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->andWhere('l.status = :new')
            ->setParameter('new', LeadStatus::New)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
