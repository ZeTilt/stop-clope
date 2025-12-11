<?php

namespace App\Repository;

use App\Entity\WakeUp;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WakeUp>
 */
class WakeUpRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WakeUp::class);
    }

    public function findByDate(\DateTimeInterface $date): ?WakeUp
    {
        $dateOnly = (clone $date)->setTime(0, 0, 0);

        return $this->createQueryBuilder('w')
            ->where('w.date = :date')
            ->setParameter('date', $dateOnly)
            ->getQuery()
            ->getOneOrNullResult();
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

        $wakeUps = $this->createQueryBuilder('w')
            ->where('w.date >= :start')
            ->andWhere('w.date <= :end')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->getQuery()
            ->getResult();

        $indexed = [];
        foreach ($wakeUps as $wakeUp) {
            $indexed[$wakeUp->getDate()->format('Y-m-d')] = $wakeUp;
        }

        return $indexed;
    }
}
