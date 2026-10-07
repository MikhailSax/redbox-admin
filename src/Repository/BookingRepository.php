<?php

namespace App\Repository;

use App\Dto\BookingListQuery;
use App\Entity\Booking;
use App\Entity\Product;
use App\Entity\ProductSide;
use App\Entity\User;
use App\Enum\BookingMode;
use App\Enum\BookingStatus;
use App\Service\MonthCalendar;
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
     * The admin list: $query filters by status (or "unpaid": confirmed bookings not paid yet), a search over the
     * client (card or typed-in contact), the phone and the structure name, a month the booking takes a day of,
     * the way the side is sold and who made the booking. Sorted by $query->sortKey() (a key of LIST_SORTS).
     *
     * @return list<Booking>
     */
    public function findForList(BookingListQuery $query, int $limit = 200): array
    {
        $qb = $this->listQuery($query)
            ->addSelect('s', 'p', 'c')
            // the client as the list shows it: the card's company or name, otherwise the typed-in contact
            ->addSelect('COALESCE(c.company, c.name, b.clientName) AS HIDDEN clientSort')
            ->setMaxResults($limit);
        $descending = $query->isDescending();
        foreach (self::LIST_SORTS[$query->sortKey()] ?? self::LIST_SORTS['created'] as $i => $field) {
            $qb->addOrderBy($field, 0 === $i && $descending ? 'DESC' : 'ASC');
        }
        $qb->addOrderBy('b.id', $descending ? 'DESC' : 'ASC');

        $this->filterStatus($qb, $query);

        return $qb->getQuery()->getResult();
    }

    /**
     * Number of bookings by status under every filter of $query but the status itself, plus "unpaid".
     *
     * @return array<string, int> keyed by BookingStatus values and BookingListQuery::UNPAID
     */
    public function countByStatus(BookingListQuery $query): array
    {
        $rows = $this->listQuery($query)
            ->select('b.status AS status', 'COUNT(b.id) AS total', 'SUM(CASE WHEN b.paidAt IS NULL THEN 1 ELSE 0 END) AS unpaid')
            ->groupBy('b.status')
            ->getQuery()
            ->getArrayResult();

        $counts = array_fill_keys(array_map(static fn (BookingStatus $s) => $s->value, BookingStatus::cases()), 0) + [BookingListQuery::UNPAID => 0];
        foreach ($rows as $row) {
            $status = $row['status'] instanceof BookingStatus ? $row['status'] : BookingStatus::from($row['status']);
            $counts[$status->value] = (int) $row['total'];
            if (BookingStatus::Confirmed === $status) {
                $counts[BookingListQuery::UNPAID] = (int) $row['unpaid'];
            }
        }

        return $counts;
    }

    /**
     * Everyone who has made a booking, for the "made by" filter.
     *
     * @return list<User>
     */
    public function findAuthors(): array
    {
        return $this->getEntityManager()->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->andWhere(\sprintf('EXISTS (SELECT 1 FROM %s ab WHERE ab.createdBy = u)', Booking::class))
            ->orderBy('u.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Sales of [$from, $to] for the reports: bookings confirmed (or paid, which confirms) within the days and still
     * confirmed. Imported bookings may carry no confirmation date: their creation date counts then.
     *
     * @return list<Booking> with the side, the structure, its type, district, the author and the services
     */
    public function findSold(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->createQueryBuilder('b')
            ->addSelect('s', 'p', 'pt', 'st', 'd', 'u', 'l')
            ->join('b.side', 's')
            ->join('s.product', 'p')
            ->join('p.productType', 'pt')
            ->leftJoin('s.productType', 'st')
            ->leftJoin('p.district', 'd')
            ->leftJoin('b.createdBy', 'u')
            ->leftJoin('b.serviceLines', 'l')
            ->andWhere('b.status = :confirmed')
            ->andWhere('COALESCE(b.confirmedAt, b.createdAt) >= :from AND COALESCE(b.confirmedAt, b.createdAt) < :until')
            ->setParameter('confirmed', BookingStatus::Confirmed)
            ->setParameter('from', $from->setTime(0, 0))
            ->setParameter('until', $to->setTime(0, 0)->modify('+1 day'))
            ->getQuery()
            ->getResult();
    }

    /**
     * Holds that ran out unconfirmed within [$from, $to] (expired, or overdue and not swept yet), by author id
     * (0 = no author: imported or made from the website).
     *
     * @return array<int, int>
     */
    public function countBurnedHoldsByAuthor(\DateTimeImmutable $from, \DateTimeImmutable $to, \DateTimeImmutable $now): array
    {
        $rows = $this->createQueryBuilder('b')
            ->select('IDENTITY(b.createdBy) AS author', 'COUNT(b.id) AS total')
            ->andWhere('b.expiresAt >= :from AND b.expiresAt < :until')
            ->andWhere('b.status = :expired OR (b.status = :hold AND b.expiresAt <= :now)')
            ->setParameter('from', $from->setTime(0, 0))
            ->setParameter('until', $to->setTime(0, 0)->modify('+1 day'))
            ->setParameter('expired', BookingStatus::Expired)
            ->setParameter('hold', BookingStatus::Hold)
            ->setParameter('now', $now)
            ->groupBy('author')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['author']] = (int) $row['total'];
        }

        return $counts;
    }

    /** The list's joins and every filter but the status */
    private function listQuery(BookingListQuery $query): QueryBuilder
    {
        $qb = $this->createQueryBuilder('b')
            ->join('b.side', 's')
            ->join('s.product', 'p')
            ->leftJoin('b.client', 'c');

        if (null !== $query->q && '' !== trim($query->q)) {
            $qb->andWhere('b.clientName LIKE :search OR b.clientPhone LIKE :search OR p.name LIKE :search OR c.company LIKE :search OR c.name LIKE :search')
                ->setParameter('search', self::like($query->q));
        }

        if (null !== $month = $query->monthFilter()) {
            $qb->andWhere('b.startDate <= :monthEnd AND b.endDate >= :monthStart')
                ->setParameter('monthStart', $month)
                ->setParameter('monthEnd', MonthCalendar::lastDay($month));
        }

        if (null !== $query->kind) {
            // the side's own type, otherwise the structure's (ProductSide::getEffectiveProductType())
            $qb->leftJoin('s.productType', 'st')
                ->join('p.productType', 'pt')
                ->andWhere('(st.id IS NOT NULL AND st.bookingMode = :mode) OR (st.id IS NULL AND pt.bookingMode = :mode)')
                ->setParameter('mode', BookingListQuery::KIND_AIRTIME === $query->kind ? BookingMode::Airtime : BookingMode::Side);
        }

        if (null !== $query->author) {
            $qb->andWhere('IDENTITY(b.createdBy) = :author')->setParameter('author', $query->author);
        }

        return $qb;
    }

    private function filterStatus(QueryBuilder $qb, BookingListQuery $query): void
    {
        $status = $query->statusFilter();
        if ($query->isUnpaid()) {
            $qb->andWhere('b.paidAt IS NULL');
            $status = BookingStatus::Confirmed;
        }
        if (null !== $status) {
            $qb->andWhere('b.status = :status')->setParameter('status', $status);
        }
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
