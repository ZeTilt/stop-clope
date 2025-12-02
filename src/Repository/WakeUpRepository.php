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
}
