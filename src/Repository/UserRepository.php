<?php

namespace App\Repository;

use App\Entity\User;
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
     * Clients, newest first; $q matches name, company, email or phone.
     *
     * @return list<User>
     */
    public function findClients(?string $q = null, ?int $limit = null): array
    {
        $qb = $this->createQueryBuilder('u')
            ->andWhere('u.roles LIKE :client')
            ->setParameter('client', '%'.User::ROLE_CLIENT.'%')
            ->orderBy('u.createdAt', 'DESC')
            ->addOrderBy('u.id', 'DESC')
            ->setMaxResults($limit);

        if (null !== $q && '' !== trim($q)) {
            $qb->andWhere('u.name LIKE :q OR u.company LIKE :q OR u.email LIKE :q OR u.phone LIKE :q')
                ->setParameter('q', BookingRepository::like($q));
        }

        return $qb->getQuery()->getResult();
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
