<?php

namespace App\Repository;

use App\Dto\ProductListQuery;
use App\Entity\Product;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Product>
 */
class ProductRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Product::class);
    }

    /**
     * Ids of products matching the text/category/type filters, newest changes first.
     * The availability filter is applied afterwards (status is computed, not stored).
     *
     * @return list<int>
     */
    public function findMatchingIds(ProductListQuery $query): array
    {
        $qb = $this->createQueryBuilder('p')
            ->select('p.id')
            ->orderBy('p.updatedAt', 'DESC')
            ->addOrderBy('p.id', 'DESC');

        if (null !== $query->q && '' !== trim($query->q)) {
            $this->applySearch($qb, $query->q);
        }

        if (null !== $query->category) {
            $qb->andWhere('IDENTITY(p.category) = :category')->setParameter('category', $query->category);
        }

        if (null !== $query->type) {
            $qb->andWhere('IDENTITY(p.productType) = :type')->setParameter('type', $query->type);
        }

        // "own" = Redbox's structures, a number = structures of that partner
        if (ProductListQuery::OWNER_OWN === $query->owner) {
            $qb->andWhere('p.owner IS NULL');
        } elseif (null !== $query->owner && '' !== $query->owner) {
            $qb->andWhere('IDENTITY(p.owner) = :owner')->setParameter('owner', (int) $query->owner);
        }

        return array_map('intval', $qb->getQuery()->getSingleColumnResult());
    }

    /**
     * Quick search for the command palette: name, description, district or category.
     *
     * @return list<Product>
     */
    public function search(string $term, int $limit): array
    {
        $qb = $this->createQueryBuilder('p')
            ->addSelect('c', 't')
            ->join('p.category', 'c')
            ->join('p.productType', 't')
            ->orderBy('p.updatedAt', 'DESC')
            ->setMaxResults($limit);

        return $this->applySearch($qb, $term, joined: true)->getQuery()->getResult();
    }

    /**
     * Matches a structure by its name, short description, district or category.
     */
    private function applySearch(QueryBuilder $qb, string $term, bool $joined = false): QueryBuilder
    {
        if (!$joined) {
            $qb->join('p.category', 'c');
        }

        return $qb
            ->leftJoin('p.district', 'sd')
            ->andWhere('p.name LIKE :q OR p.schemeNumber LIKE :q OR p.shortDescription LIKE :q OR sd.name LIKE :q OR c.name LIKE :q')
            ->setParameter('q', BookingRepository::like($term));
    }

    /**
     * Products with everything the list shows (category, type, district, sides, photos), in the order of $ids.
     *
     * @param list<int> $ids
     *
     * @return list<Product>
     */
    public function findForList(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        $products = $this->createQueryBuilder('p')
            ->addSelect('c', 't', 'd', 'o', 's', 'ph')
            ->join('p.category', 'c')
            ->join('p.productType', 't')
            ->leftJoin('p.district', 'd')
            ->leftJoin('p.owner', 'o')
            ->leftJoin('p.sides', 's')
            ->leftJoin('s.photos', 'ph')
            ->andWhere('p.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();

        $position = array_flip($ids);
        usort($products, static fn (Product $a, Product $b) => $position[$a->getId()] <=> $position[$b->getId()]);

        return $products;
    }

    /**
     * Number of products per related entity, e.g. countGroupedBy('category') => [categoryId => count].
     *
     * @param 'category'|'productType'|'district'|'owner' $association
     *
     * @return array<int, int>
     */
    public function countGroupedBy(string $association): array
    {
        if (!\in_array($association, ['category', 'productType', 'district', 'owner'], true)) {
            throw new \InvalidArgumentException(\sprintf('Cannot group products by "%s".', $association));
        }

        $rows = $this->createQueryBuilder('p')
            ->select(\sprintf('IDENTITY(p.%s) AS id, COUNT(p.id) AS total', $association))
            ->andWhere(\sprintf('p.%s IS NOT NULL', $association))
            ->groupBy('id')
            ->getQuery()
            ->getArrayResult();

        return array_column(array_map(static fn (array $row) => [(int) $row['id'], (int) $row['total']], $rows), 1, 0);
    }
}
