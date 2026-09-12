<?php

namespace App\Repository;

use App\Entity\AdditionalService;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AdditionalService>
 */
class AdditionalServiceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AdditionalService::class);
    }

    /**
     * @return list<AdditionalService>
     */
    public function findActive(): array
    {
        return $this->findBy(['active' => true], ['name' => 'ASC']);
    }
}
