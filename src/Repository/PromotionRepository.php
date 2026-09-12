<?php

namespace App\Repository;

use App\Entity\MediaPlan;
use App\Entity\MediaPlanItem;
use App\Entity\Promotion;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Promotion>
 */
class PromotionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Promotion::class);
    }

    /**
     * Switched on and within their dates on $date, targets loaded.
     *
     * @return list<Promotion>
     */
    public function findRunning(\DateTimeInterface $date): array
    {
        return $this->createQueryBuilder('p')
            ->addSelect('c', 'pr')
            ->leftJoin('p.categories', 'c')
            ->leftJoin('p.products', 'pr')
            ->andWhere('p.active = true')
            ->andWhere('p.startsAt <= :day')
            ->andWhere('p.endsAt IS NULL OR p.endsAt >= :day')
            ->setParameter('day', \DateTimeImmutable::createFromInterface($date)->setTime(0, 0), 'date_immutable')
            ->getQuery()
            ->getResult();
    }

    /**
     * All promotions for the list, newest first; $q matches the title or the promo code.
     *
     * @return list<Promotion>
     */
    public function findForList(?string $q = null): array
    {
        $qb = $this->createQueryBuilder('p')
            ->addSelect('c', 'pr')
            ->leftJoin('p.categories', 'c')
            ->leftJoin('p.products', 'pr')
            ->orderBy('p.startsAt', 'DESC')
            ->addOrderBy('p.id', 'DESC');

        if (null !== $q && '' !== trim($q)) {
            $qb->andWhere('p.title LIKE :q OR p.code LIKE :q')->setParameter('q', '%'.addcslashes(trim($q), '%_').'%');
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * How many media plan items got each promotion.
     *
     * @return array<int, int> promotion id => items
     */
    public function countUsage(): array
    {
        $rows = $this->getEntityManager()->createQueryBuilder()
            ->select('IDENTITY(i.promotion) AS promotion', 'COUNT(i.id) AS items')
            ->from(MediaPlanItem::class, 'i')
            ->where('i.promotion IS NOT NULL')
            ->groupBy('i.promotion')
            ->getQuery()
            ->getArrayResult();

        return array_column(array_map(static fn (array $row) => ['id' => (int) $row['promotion'], 'items' => (int) $row['items']], $rows), 'items', 'id');
    }

    /**
     * Media plans where the promotion lowered prices, newest first.
     *
     * @return list<array{plan: MediaPlan, items: int, savings: float}>
     */
    public function findUsage(Promotion $promotion): array
    {
        $rows = $this->getEntityManager()->createQueryBuilder()
            ->select('p', 'COUNT(i.id) AS items', 'SUM((i.basePrice - i.monthlyPrice) * p.months) AS savings')
            ->from(MediaPlan::class, 'p')
            ->join('p.items', 'i')
            ->where('i.promotion = :promotion')
            ->setParameter('promotion', $promotion)
            ->groupBy('p.id')
            ->orderBy('p.updatedAt', 'DESC')
            ->getQuery()
            ->getResult();

        return array_map(static fn (array $row) => ['plan' => $row[0], 'items' => (int) $row['items'], 'savings' => (float) $row['savings']], $rows);
    }

    public function findRunningByCode(string $code, \DateTimeInterface $date): ?Promotion
    {
        foreach ($this->findRunning($date) as $promotion) {
            if (null !== $promotion->getCode() && 0 === strcasecmp($promotion->getCode(), trim($code))) {
                return $promotion;
            }
        }

        return null;
    }
}
