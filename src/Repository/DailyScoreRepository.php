<?php

namespace App\Repository;

use App\Entity\DailyScore;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @extends ServiceEntityRepository<DailyScore>
 */
class DailyScoreRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private Security $security
    ) {
        parent::__construct($registry, DailyScore::class);
    }

    private function getCurrentUser(): ?User
    {
        $user = $this->security->getUser();
        return $user instanceof User ? $user : null;
    }

    public function findByDate(\DateTimeInterface $date): ?DailyScore
    {
        $user = $this->getCurrentUser();
        if (!$user) {
            return null;
        }

        $dateOnly = (clone $date)->setTime(0, 0, 0);

        return $this->findOneBy([
            'user' => $user,
            'date' => $dateOnly,
        ]);
    }

    public function getTotalScore(): int
    {
        $user = $this->getCurrentUser();
        if (!$user) {
            return 0;
        }

        $result = $this->createQueryBuilder('d')
            ->select('SUM(d.score)')
            ->where('d.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) ($result ?? 0);
    }

    public function getCurrentStreak(): int
    {
        $user = $this->getCurrentUser();
        if (!$user) {
            return 0;
        }

        // Récupérer le streak du jour le plus récent
        $latest = $this->createQueryBuilder('d')
            ->where('d.user = :user')
            ->setParameter('user', $user)
            ->orderBy('d.date', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $latest?->getStreak() ?? 0;
    }

    public function getBestStreak(): int
    {
        $user = $this->getCurrentUser();
        if (!$user) {
            return 0;
        }

        $result = $this->createQueryBuilder('d')
            ->select('MAX(d.streak)')
            ->where('d.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) ($result ?? 0);
    }

    /**
     * Récupère les scores des N derniers jours
     * @return array<string, DailyScore>
     */
    public function getRecentScores(int $days = 7): array
    {
        $user = $this->getCurrentUser();
        if (!$user) {
            return [];
        }

        $startDate = (new \DateTime("-{$days} days"))->setTime(0, 0, 0);

        $scores = $this->createQueryBuilder('d')
            ->where('d.user = :user')
            ->andWhere('d.date >= :start')
            ->setParameter('user', $user)
            ->setParameter('start', $startDate)
            ->orderBy('d.date', 'ASC')
            ->getQuery()
            ->getResult();

        $result = [];
        foreach ($scores as $score) {
            $result[$score->getDate()->format('Y-m-d')] = $score;
        }

        return $result;
    }

    public function upsert(DailyScore $dailyScore): void
    {
        $existing = $this->findOneBy([
            'user' => $dailyScore->getUser(),
            'date' => $dailyScore->getDate(),
        ]);

        if ($existing) {
            $existing->setScore($dailyScore->getScore());
            $existing->setCigaretteCount($dailyScore->getCigaretteCount());
            $existing->setStreak($dailyScore->getStreak());
            $existing->setAverageInterval($dailyScore->getAverageInterval());
            $existing->setCalculatedAt(new \DateTime());
        } else {
            $this->getEntityManager()->persist($dailyScore);
        }

        $this->getEntityManager()->flush();
    }
}
