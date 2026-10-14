<?php

namespace App\Repository;

use App\Entity\Lead;
use App\Entity\Package;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Package>
 */
class PackageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Package::class);
    }

    /**
     * Switched on and within their dates on $date, in the website's order, slots and their sides loaded.
     *
     * @return list<Package>
     */
    public function findRunning(\DateTimeInterface $date): array
    {
        return $this->withSlots()
            ->andWhere('p.active = true')
            ->andWhere('p.startsAt <= :day')
            ->andWhere('p.endsAt IS NULL OR p.endsAt >= :day')
            ->setParameter('day', \DateTimeImmutable::createFromInterface($date)->setTime(0, 0), 'date_immutable')
            ->orderBy('p.position', 'ASC')
            ->addOrderBy('p.id', 'ASC')
            ->addOrderBy('s.position', 'ASC')
            ->addOrderBy('s.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findRunningById(int $id, \DateTimeInterface $date): ?Package
    {
        foreach ($this->findRunning($date) as $package) {
            if ($package->getId() === $id) {
                return $package;
            }
        }

        return null;
    }

    /**
     * Every package for the list; $q matches the title.
     *
     * @return list<Package>
     */
    public function findForList(?string $q = null): array
    {
        $qb = $this->createQueryBuilder('p')
            ->addSelect('s')
            ->leftJoin('p.slots', 's')
            ->orderBy('p.position', 'ASC')
            ->addOrderBy('p.id', 'DESC')
            ->addOrderBy('s.position', 'ASC');

        if (null !== $q && '' !== trim($q)) {
            $qb->andWhere('p.title LIKE :q')->setParameter('q', '%'.addcslashes(trim($q), '%_').'%');
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * How many requests from the website came with each package.
     *
     * @return array<int, int> package id => requests
     */
    public function countLeads(): array
    {
        $rows = $this->getEntityManager()->createQueryBuilder()
            ->select('IDENTITY(l.package) AS package', 'COUNT(l.id) AS leads')
            ->from(Lead::class, 'l')
            ->where('l.package IS NOT NULL')
            ->groupBy('l.package')
            ->getQuery()
            ->getArrayResult();

        return array_combine(array_map(static fn (array $row) => (int) $row['package'], $rows), array_map(static fn (array $row) => (int) $row['leads'], $rows));
    }

    /**
     * Requests that came with the package, newest first.
     *
     * @return list<Lead>
     */
    public function findLeads(Package $package, int $limit = 100): array
    {
        return $this->getEntityManager()->createQueryBuilder()
            ->select('l')
            ->from(Lead::class, 'l')
            ->where('l.package = :package')
            ->setParameter('package', $package)
            ->orderBy('l.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    private function withSlots(): QueryBuilder
    {
        return $this->createQueryBuilder('p')
            ->addSelect('s', 'sd', 'pr')
            ->leftJoin('p.slots', 's')
            ->leftJoin('s.sides', 'sd')
            ->leftJoin('sd.product', 'pr');
    }
}
