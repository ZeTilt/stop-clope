<?php

namespace App\Repository;

use App\Entity\User;
use App\Entity\UserState;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserState>
 */
class UserStateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserState::class);
    }

    public function findByUser(User $user): ?UserState
    {
        return $this->findOneBy(['user' => $user]);
    }

    public function findOrCreateByUser(User $user): UserState
    {
        $userState = $this->findByUser($user);

        if ($userState === null) {
            $userState = new UserState();
            $userState->setUser($user);
            $this->getEntityManager()->persist($userState);
        }

        return $userState;
    }

    public function save(UserState $userState, bool $flush = true): void
    {
        $this->getEntityManager()->persist($userState);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
