<?php

namespace App\Repository;

use App\Entity\User;
use App\Enum\ClientType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * Used to upgrade (rehash) the user's password automatically over time.
     */
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }

    //    /**
    //     * @return User[] Returns an array of User objects
    //     */
    /**
     * CRM staff (admins, super managers), by name.
     *
     * @return list<User>
     */
    public function findStaff(): array
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.roles NOT LIKE :client')
            ->setParameter('client', '%'.User::ROLE_CLIENT.'%')
            ->orderBy('u.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Clients, newest first; $q matches name, company, ИНН, email or phone.
     *
     * @return list<User>
     */
    public function findClients(?string $q = null, ?int $limit = null, ?ClientType $type = null): array
    {
        $qb = $this->createQueryBuilder('u')
            ->andWhere('u.roles LIKE :client')
            ->setParameter('client', '%'.User::ROLE_CLIENT.'%')
            ->orderBy('u.createdAt', 'DESC')
            ->addOrderBy('u.id', 'DESC')
            ->setMaxResults($limit);

        if (null !== $q && '' !== trim($q)) {
            $qb->andWhere('u.name LIKE :q OR u.company LIKE :q OR u.inn LIKE :q OR u.email LIKE :q OR u.phone LIKE :q')
                ->setParameter('q', BookingRepository::like($q));
        }
        if (null !== $type) {
            $qb->andWhere('u.clientType = :type')->setParameter('type', $type);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @return array<string, int> ClientType value => number of clients
     */
    public function countClientsByType(): array
    {
        $rows = $this->createQueryBuilder('u')
            ->select('u.clientType AS type', 'COUNT(u.id) AS count')
            ->andWhere('u.roles LIKE :client')
            ->setParameter('client', '%'.User::ROLE_CLIENT.'%')
            ->groupBy('u.clientType')
            ->getQuery()
            ->getArrayResult();

        $counts = array_fill_keys(array_map(static fn (ClientType $t) => $t->value, ClientType::cases()), 0);
        foreach ($rows as $row) {
            $type = $row['type'] instanceof ClientType ? $row['type']->value : $row['type'];
            if (null !== $type) {
                $counts[$type] = (int) $row['count'];
            }
        }

        return $counts;
    }

    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('u')
    //            ->andWhere('u.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('u.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?User
    //    {
    //        return $this->createQueryBuilder('u')
    //            ->andWhere('u.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
