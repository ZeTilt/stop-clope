<?php

namespace App\Repository;

use App\Entity\User;
use App\Entity\UserBadge;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserBadge>
 */
class UserBadgeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserBadge::class);
    }

    /**
     * @return string[] Liste des codes de badges déjà obtenus
     */
    public function findUserBadgeCodes(User $user): array
    {
        $badges = $this->createQueryBuilder('ub')
            ->select('ub.badgeCode')
            ->where('ub.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getResult();

        return array_column($badges, 'badgeCode');
    }

    /**
     * @return UserBadge[] Liste des badges avec dates d'obtention
     */
    public function findUserBadges(User $user): array
    {
        return $this->createQueryBuilder('ub')
            ->where('ub.user = :user')
            ->setParameter('user', $user)
            ->orderBy('ub.unlockedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function hasBadge(User $user, string $badgeCode): bool
    {
        return $this->count(['user' => $user, 'badgeCode' => $badgeCode]) > 0;
    }
}
