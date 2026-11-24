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
    /** Kinds of requests the list is filtered by: orders of structures, or messages from the feedback form */
    public const KIND_ORDERS = 'orders';
    public const KIND_FEEDBACK = 'feedback';
    public const KINDS = [self::KIND_ORDERS => 'Размещение', self::KIND_FEEDBACK => 'Обратная связь'];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Lead::class);
    }

    /**
     * Newest first; $q matches the contact, the company, the phone or the email.
     *
     * @return list<Lead>
     */
    public function findForList(?LeadStatus $status = null, ?string $q = null, int $limit = 200, ?string $kind = null): array
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
        // feedback from the website's form apart from orders of structures (from the site or made in the CRM)
        if (self::KIND_FEEDBACK === $kind) {
            $qb->andWhere('l.source = :feedback')->setParameter('feedback', Lead::SOURCE_FEEDBACK);
        } elseif (self::KIND_ORDERS === $kind) {
            $qb->andWhere('l.source <> :feedback')->setParameter('feedback', Lead::SOURCE_FEEDBACK);
        }

        if (null !== $q && '' !== trim($q)) {
            $qb->andWhere('l.contactName LIKE :q OR l.companyName LIKE :q OR l.phone LIKE :q OR l.email LIKE :q OR l.comment LIKE :q')
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

    /**
     * @return array<string, int> kind (KINDS) => requests
     */
    public function countByKind(): array
    {
        $feedback = (int) $this->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->andWhere('l.source = :feedback')
            ->setParameter('feedback', Lead::SOURCE_FEEDBACK)
            ->getQuery()
            ->getSingleScalarResult();
        $all = (int) $this->createQueryBuilder('l')->select('COUNT(l.id)')->getQuery()->getSingleScalarResult();

        return [self::KIND_ORDERS => $all - $feedback, self::KIND_FEEDBACK => $feedback];
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
