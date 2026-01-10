<?php

namespace App\Repository;

use App\Entity\ActiveBonus;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ActiveBonus>
 */
class ActiveBonusRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ActiveBonus::class);
    }

    /**
     * @return ActiveBonus[]
     */
    public function findActiveByUser(User $user): array
    {
        return $this->createQueryBuilder('ab')
            ->andWhere('ab.user = :user')
            ->andWhere('ab.expiresAt > :now')
            ->setParameter('user', $user)
            ->setParameter('now', new \DateTime())
            ->orderBy('ab.expiresAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return ActiveBonus[]
     */
    public function findActiveByUserAndType(User $user, string $bonusType): array
    {
        return $this->createQueryBuilder('ab')
            ->andWhere('ab.user = :user')
            ->andWhere('ab.bonusType = :type')
            ->andWhere('ab.expiresAt > :now')
            ->setParameter('user', $user)
            ->setParameter('type', $bonusType)
            ->setParameter('now', new \DateTime())
            ->getQuery()
            ->getResult();
    }

    public function deleteExpired(): int
    {
        return $this->createQueryBuilder('ab')
            ->delete()
            ->andWhere('ab.expiresAt < :now')
            ->setParameter('now', new \DateTime())
            ->getQuery()
            ->execute();
    }
}
