<?php

namespace App\Repository;

use App\Entity\Booking;
use App\Entity\Product;
use App\Entity\ProductSide;
use App\Entity\User;
use App\Enum\BookingStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Booking>
 */
class BookingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Booking::class);
    }

    /**
     * Bookings that block the side at $now (confirmed, or on hold and not yet overdue)
     * and share a day with [$start, $end].
     *
     * @return list<Booking>
     */
    public function findActiveOverlapping(ProductSide $side, \DateTimeImmutable $start, \DateTimeImmutable $end, \DateTimeImmutable $now): array
    {
        return $this->active($this->createQueryBuilder('b'), $now)
            ->andWhere('b.side = :side')
            ->andWhere('b.startDate <= :end AND b.endDate >= :start')
            ->setParameter('side', $side)
            ->setParameter('start', $start, 'date_immutable')
            ->setParameter('end', $end, 'date_immutable')
            ->getQuery()
            ->getResult();
    }

    /**
     * Active bookings of all sides of a product overlapping [$start, $end], for the occupancy grid.
     *
     * @return list<Booking>
     */
    public function findActiveForProduct(Product $product, \DateTimeImmutable $start, \DateTimeImmutable $end, \DateTimeImmutable $now): array
    {
        return $this->active($this->createQueryBuilder('b'), $now)
            ->join('b.side', 's')
            ->andWhere('s.product = :product')
            ->andWhere('b.startDate <= :end AND b.endDate >= :start')
            ->setParameter('product', $product)
            ->setParameter('start', $start, 'date_immutable')
            ->setParameter('end', $end, 'date_immutable')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<Booking>
     */
    public function findForProduct(Product $product): array
    {
        return $this->createQueryBuilder('b')
            ->addSelect('s')
            ->join('b.side', 's')
            ->andWhere('s.product = :product')
            ->setParameter('product', $product)
            ->orderBy('b.startDate', 'DESC')
            ->addOrderBy('b.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Every booking made for the client, newest first.
     *
     * @return list<Booking>
     */
    public function findForClient(User $client): array
    {
        return $this->createQueryBuilder('b')
            ->addSelect('s', 'p')
            ->join('b.side', 's')
            ->join('s.product', 'p')
            ->andWhere('b.client = :client')
            ->setParameter('client', $client)
            ->orderBy('b.startDate', 'DESC')
            ->addOrderBy('b.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** Sides the client holds right now (confirmed, or on hold and not yet overdue) */
    public function countActiveForClient(User $client, \DateTimeImmutable $now): int
    {
        return (int) $this->active($this->createQueryBuilder('b'), $now)
            ->select('COUNT(b.id)')
            ->andWhere('b.client = :client')
            ->setParameter('client', $client)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Columns the list of bookings is sorted by: key => fields, the first one in the chosen direction, the rest
     * break ties (a structure's sides one after another, each side's bookings by date).
     */
    public const LIST_SORTS = [
        'product' => ['p.name', 's.name', 'b.startDate'],
        'side' => ['s.name', 'p.name', 'b.startDate'],
        'period' => ['b.startDate', 'b.endDate', 'p.name'],
        'client' => ['clientSort', 'b.startDate'],
        'status' => ['b.status', 'b.startDate'],
        'sold' => ['b.soldPrice', 'b.startDate'],
        'created' => ['b.createdAt'],
    ];

    /**
     * $status = null means every status. $search matches the client (card or typed-in contact), the phone or
     * the structure name. $unpaid: only confirmed bookings not paid yet (post-paying clients).
     * Sorted by $sort (a key of LIST_SORTS), newest first by default.
     *
     * @return list<Booking>
     */
    public function findForList(?BookingStatus $status, ?string $search = null, int $limit = 200, bool $unpaid = false, string $sort = 'created', bool $descending = true): array
    {
        $qb = $this->createQueryBuilder('b')
            ->addSelect('s', 'p', 'c')
            // the client as the list shows it: the card's company or name, otherwise the typed-in contact
            ->addSelect('COALESCE(c.company, c.name, b.clientName) AS HIDDEN clientSort')
            ->join('b.side', 's')
            ->join('s.product', 'p')
            ->leftJoin('b.client', 'c')
            ->setMaxResults($limit);
        foreach (self::LIST_SORTS[$sort] ?? self::LIST_SORTS['created'] as $i => $field) {
            $qb->addOrderBy($field, 0 === $i && $descending ? 'DESC' : 'ASC');
        }
        $qb->addOrderBy('b.id', $descending ? 'DESC' : 'ASC');

        if ($unpaid) {
            $qb->andWhere('b.paidAt IS NULL');
            $status = BookingStatus::Confirmed;
        }
        if (null !== $status) {
            $qb->andWhere('b.status = :status')->setParameter('status', $status);
        }

        if (null !== $search && '' !== trim($search)) {
            $qb->andWhere('b.clientName LIKE :search OR b.clientPhone LIKE :search OR p.name LIKE :search OR c.company LIKE :search OR c.name LIKE :search')
                ->setParameter('search', self::like($search));
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Unconfirmed holds that still block their slot, the ones expiring first on top.
     *
     * @return list<Booking>
     */
    public function findLiveHolds(\DateTimeImmutable $now, int $limit): array
    {
        return $this->createQueryBuilder('b')
            ->addSelect('s', 'p')
            ->join('b.side', 's')
            ->join('s.product', 'p')
            ->andWhere('b.status = :hold AND b.expiresAt > :now')
            ->setParameter('hold', BookingStatus::Hold)
            ->setParameter('now', $now)
            ->orderBy('b.expiresAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countLiveHolds(\DateTimeImmutable $now): int
    {
        return (int) $this->createQueryBuilder('b')
            ->select('COUNT(b.id)')
            ->andWhere('b.status = :hold AND b.expiresAt > :now')
            ->setParameter('hold', BookingStatus::Hold)
            ->setParameter('now', $now)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * "%term%" for LIKE with the term's own wildcards escaped.
     */
    public static function like(string $term): string
    {
        return '%'.addcslashes(trim($term), '%_\\').'%';
    }

    public function countActiveForProduct(Product $product, \DateTimeImmutable $now): int
    {
        return (int) $this->active($this->createQueryBuilder('b'), $now)
            ->select('COUNT(b.id)')
            ->join('b.side', 's')
            ->andWhere('s.product = :product')
            ->setParameter('product', $product)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countActiveForSide(ProductSide $side, \DateTimeImmutable $now): int
    {
        return (int) $this->active($this->createQueryBuilder('b'), $now)
            ->select('COUNT(b.id)')
            ->andWhere('b.side = :side')
            ->setParameter('side', $side)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Holds whose confirmation deadline has passed but are still marked as Hold.
     *
     * @return list<Booking>
     */
    public function findOverdueHolds(\DateTimeImmutable $now): array
    {
        return $this->createQueryBuilder('b')
            ->andWhere('b.status = :hold')
            ->andWhere('b.expiresAt <= :now')
            ->setParameter('hold', BookingStatus::Hold)
            ->setParameter('now', $now)
            ->getQuery()
            ->getResult();
    }

    private function active(QueryBuilder $qb, \DateTimeImmutable $now): QueryBuilder
    {
        return $qb
            ->andWhere('(b.status = :confirmed OR (b.status = :hold AND b.expiresAt > :now))')
            ->setParameter('confirmed', BookingStatus::Confirmed)
            ->setParameter('hold', BookingStatus::Hold)
            ->setParameter('now', $now);
    }
}
