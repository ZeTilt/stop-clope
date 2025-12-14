<?php

namespace App\Repository;

use App\Entity\User;
use App\Entity\WakeUp;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @extends ServiceEntityRepository<WakeUp>
 */
class WakeUpRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private Security $security
    ) {
        parent::__construct($registry, WakeUp::class);
    }

    private function getCurrentUser(): ?User
    {
        $user = $this->security->getUser();
        return $user instanceof User ? $user : null;
    }

    public function findByDate(\DateTimeInterface $date): ?WakeUp
    {
        $dateOnly = (clone $date)->setTime(0, 0, 0);
        $user = $this->getCurrentUser();

        $qb = $this->createQueryBuilder('w')
            ->where('w.date = :date')
            ->setParameter('date', $dateOnly);

        if ($user) {
            $qb->andWhere('w.user = :user')
               ->setParameter('user', $user);
        } else {
            $qb->andWhere('w.user IS NULL');
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    public function findTodayWakeUp(): ?WakeUp
    {
        return $this->findByDate(new \DateTime());
    }

    public function findYesterdayWakeUp(): ?WakeUp
    {
        return $this->findByDate(new \DateTime('-1 day'));
    }

    /**
     * Find wake-ups for a date range (single query for batch operations)
     * @return array<string, WakeUp> Indexed by date string (Y-m-d)
     */
    public function findByDateRange(\DateTimeInterface $startDate, \DateTimeInterface $endDate): array
    {
        $start = (clone $startDate)->setTime(0, 0, 0);
        $end = (clone $endDate)->setTime(0, 0, 0);
        $user = $this->getCurrentUser();

        $qb = $this->createQueryBuilder('w')
            ->where('w.date >= :start')
            ->andWhere('w.date <= :end')
            ->setParameter('start', $start)
            ->setParameter('end', $end);

        if ($user) {
            $qb->andWhere('w.user = :user')
               ->setParameter('user', $user);
        } else {
            $qb->andWhere('w.user IS NULL');
        }

        $wakeUps = $qb->getQuery()->getResult();

        $indexed = [];
        foreach ($wakeUps as $wakeUp) {
            $indexed[$wakeUp->getDate()->format('Y-m-d')] = $wakeUp;
        }

        return $indexed;
    }
}
