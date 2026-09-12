<?php

namespace App\Repository;

use App\Entity\ClientDocument;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ClientDocument>
 */
class ClientDocumentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ClientDocument::class);
    }

    /**
     * A client's documents, newest first (the history the client sees in the personal account).
     *
     * @return list<ClientDocument>
     */
    public function findForClient(User $client): array
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.client = :client')
            ->setParameter('client', $client)
            ->orderBy('d.createdAt', 'DESC')
            ->addOrderBy('d.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @param list<int> $clientIds
     *
     * @return array<int, int> client id => documents
     */
    public function countByClient(array $clientIds): array
    {
        return $this->countBy(ClientDocument::class, $clientIds);
    }

    /**
     * @return array<int, int>
     */
    private function countBy(string $class, array $clientIds): array
    {
        if ([] === $clientIds) {
            return [];
        }
        $rows = $this->getEntityManager()->createQueryBuilder()
            ->select('IDENTITY(e.client) AS client', 'COUNT(e.id) AS n')
            ->from($class, 'e')
            ->andWhere('e.client IN (:ids)')
            ->setParameter('ids', $clientIds)
            ->groupBy('e.client')
            ->getQuery()
            ->getArrayResult();

        return array_combine(array_map('intval', array_column($rows, 'client')), array_map('intval', array_column($rows, 'n')));
    }
}
