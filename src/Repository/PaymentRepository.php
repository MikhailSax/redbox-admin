<?php

namespace App\Repository;

use App\Entity\MediaPlan;
use App\Entity\Payment;
use App\Entity\User;
use App\Enum\ClientType;
use App\Enum\PaymentStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Payment>
 */
class PaymentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Payment::class);
    }

    /**
     * Payments due between two days (inclusive), earliest first; optionally by status, client type and a search.
     *
     * @return list<Payment>
     */
    public function findDueBetween(\DateTimeImmutable $from, \DateTimeImmutable $to, \DateTimeImmutable $now, ?PaymentStatus $status = null, ?ClientType $clientType = null, ?string $q = null): array
    {
        $qb = $this->listQuery()
            ->andWhere('p.dueDate BETWEEN :from AND :to')
            ->setParameter('from', $from, 'date_immutable')
            ->setParameter('to', $to, 'date_immutable');

        return $this->filter($qb, $now, $status, $clientType, $q)->getQuery()->getResult();
    }

    /**
     * Unpaid payments past their due date, the oldest first.
     *
     * @return list<Payment>
     */
    public function findOverdue(\DateTimeImmutable $now, ?int $limit = null, ?ClientType $clientType = null, ?string $q = null): array
    {
        return $this->filter($this->listQuery(), $now, PaymentStatus::Overdue, $clientType, $q)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Unpaid payments due today or within PaymentStatus::SOON_DAYS.
     *
     * @return list<Payment>
     */
    public function findDueSoon(\DateTimeImmutable $now, ?int $limit = null): array
    {
        return $this->status($this->listQuery(), PaymentStatus::DueSoon, $now)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Number of payments with the status and their sum; only those due in [$from, $to] when given.
     *
     * @return array{count: int, sum: float}
     */
    public function totals(PaymentStatus $status, \DateTimeImmutable $now, ?\DateTimeImmutable $from = null, ?\DateTimeImmutable $to = null): array
    {
        $qb = $this->status($this->createQueryBuilder('p'), $status, $now)
            ->select('COUNT(p.id) AS count', 'COALESCE(SUM(p.amount), 0) AS sum');
        if (null !== $from && null !== $to) {
            $qb->andWhere('p.dueDate BETWEEN :from AND :to')
                ->setParameter('from', $from, 'date_immutable')
                ->setParameter('to', $to, 'date_immutable');
        }
        $row = $qb->getQuery()->getSingleResult();

        return ['count' => (int) $row['count'], 'sum' => (float) $row['sum']];
    }

    public function countOverdue(\DateTimeImmutable $now): int
    {
        return $this->totals(PaymentStatus::Overdue, $now)['count'];
    }

    /**
     * @param list<int> $clientIds
     *
     * @return array<int, float> client id => sum of their overdue payments (clients without any are left out)
     */
    public function overdueSumsByClient(array $clientIds, \DateTimeImmutable $now): array
    {
        if ([] === $clientIds) {
            return [];
        }

        $rows = $this->status($this->createQueryBuilder('p'), PaymentStatus::Overdue, $now)
            ->select('IDENTITY(p.client) AS client', 'SUM(p.amount) AS sum')
            ->andWhere('p.client IN (:clients)')
            ->setParameter('clients', $clientIds)
            ->groupBy('p.client')
            ->getQuery()
            ->getArrayResult();

        return array_combine(array_map('intval', array_column($rows, 'client')), array_map('floatval', array_column($rows, 'sum')));
    }

    /**
     * @return list<Payment>
     */
    public function findForClient(User $client): array
    {
        return $this->listQuery()
            ->andWhere('p.client = :client')
            ->setParameter('client', $client)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<Payment>
     */
    public function findForMediaPlan(MediaPlan $plan): array
    {
        return $this->listQuery()
            ->andWhere('p.mediaPlan = :plan')
            ->setParameter('plan', $plan)
            ->getQuery()
            ->getResult();
    }

    private function listQuery(): QueryBuilder
    {
        return $this->createQueryBuilder('p')
            ->addSelect('c', 'm')
            ->join('p.client', 'c')
            ->leftJoin('p.mediaPlan', 'm')
            ->orderBy('p.dueDate', 'ASC')
            ->addOrderBy('p.id', 'ASC');
    }

    private function filter(QueryBuilder $qb, \DateTimeImmutable $now, ?PaymentStatus $status, ?ClientType $clientType, ?string $q): QueryBuilder
    {
        if (null !== $status) {
            $this->status($qb, $status, $now);
        }
        if (null !== $clientType) {
            $qb->andWhere('c.clientType = :clientType')->setParameter('clientType', $clientType);
        }
        if (null !== $q && '' !== trim($q)) {
            $qb->andWhere('c.name LIKE :q OR c.company LIKE :q OR c.inn LIKE :q OR p.title LIKE :q')
                ->setParameter('q', BookingRepository::like($q));
        }

        return $qb;
    }

    /**
     * The same rules as Payment::statusAt(), in SQL.
     */
    private function status(QueryBuilder $qb, PaymentStatus $status, \DateTimeImmutable $now): QueryBuilder
    {
        $today = $now->setTime(0, 0);
        $soon = $today->modify(\sprintf('+%d days', PaymentStatus::SOON_DAYS));

        return match ($status) {
            PaymentStatus::Paid => $qb->andWhere('p.paidAt IS NOT NULL'),
            PaymentStatus::Overdue => $qb->andWhere('p.paidAt IS NULL AND p.dueDate < :today')
                ->setParameter('today', $today, 'date_immutable'),
            PaymentStatus::DueSoon => $qb->andWhere('p.paidAt IS NULL AND p.dueDate >= :today AND p.dueDate <= :soon')
                ->setParameter('today', $today, 'date_immutable')
                ->setParameter('soon', $soon, 'date_immutable'),
            PaymentStatus::Upcoming => $qb->andWhere('p.paidAt IS NULL AND p.dueDate > :soon')
                ->setParameter('soon', $soon, 'date_immutable'),
        };
    }
}
