<?php

namespace App\Repository;

use App\Entity\Cigarette;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @extends ServiceEntityRepository<Cigarette>
 */
class CigaretteRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private Security $security
    ) {
        parent::__construct($registry, Cigarette::class);
    }

    private function getCurrentUser(): ?User
    {
        $user = $this->security->getUser();
        return $user instanceof User ? $user : null;
    }

    private function getUserId(): ?int
    {
        return $this->getCurrentUser()?->getId();
    }

    public function findByDate(\DateTimeInterface $date): array
    {
        $start = (clone $date)->setTime(0, 0, 0);
        $end = (clone $date)->setTime(23, 59, 59);

        $qb = $this->createQueryBuilder('c')
            ->where('c.smokedAt >= :start')
            ->andWhere('c.smokedAt <= :end')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->orderBy('c.smokedAt', 'ASC');

        $user = $this->getCurrentUser();
        if ($user) {
            $qb->andWhere('c.user = :user')->setParameter('user', $user);
        }

        return $qb->getQuery()->getResult();
    }

    public function findTodayCigarettes(): array
    {
        return $this->findByDate(new \DateTime());
    }

    public function findYesterdayCigarettes(): array
    {
        return $this->findByDate(new \DateTime('-1 day'));
    }

    public function countByDate(\DateTimeInterface $date): int
    {
        $start = (clone $date)->setTime(0, 0, 0);
        $end = (clone $date)->setTime(23, 59, 59);

        $qb = $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.smokedAt >= :start')
            ->andWhere('c.smokedAt <= :end')
            ->setParameter('start', $start)
            ->setParameter('end', $end);

        $user = $this->getCurrentUser();
        if ($user) {
            $qb->andWhere('c.user = :user')->setParameter('user', $user);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    public function getFirstCigaretteDate(): ?\DateTimeInterface
    {
        $qb = $this->createQueryBuilder('c')
            ->select('c.smokedAt')
            ->orderBy('c.smokedAt', 'ASC')
            ->setMaxResults(1);

        $user = $this->getCurrentUser();
        if ($user) {
            $qb->where('c.user = :user')->setParameter('user', $user);
        }

        $result = $qb->getQuery()->getOneOrNullResult();

        return $result ? $result['smokedAt'] : null;
    }

    public function getDailyStats(int $days = 30, bool $excludeToday = true): array
    {
        $startDate = new \DateTime("-{$days} days");
        $startDate->setTime(0, 0, 0);

        // Exclure aujourd'hui pour ne pas fausser les stats (journée incomplète)
        $endDate = $excludeToday ? (new \DateTime('yesterday'))->setTime(23, 59, 59) : new \DateTime();

        $userId = $this->getUserId();
        $userCondition = $userId ? 'AND user_id = :user_id' : '';

        $conn = $this->getEntityManager()->getConnection();
        $sql = "
            SELECT DATE(smoked_at) as date, COUNT(id) as count
            FROM cigarette
            WHERE smoked_at >= :start AND smoked_at <= :end {$userCondition}
            GROUP BY DATE(smoked_at)
            ORDER BY date ASC
        ";

        $params = [
            'start' => $startDate->format('Y-m-d H:i:s'),
            'end' => $endDate->format('Y-m-d H:i:s'),
        ];
        if ($userId) {
            $params['user_id'] = $userId;
        }

        $results = $conn->executeQuery($sql, $params)->fetchAllAssociative();

        $stats = [];
        foreach ($results as $row) {
            $stats[$row['date']] = (int) $row['count'];
        }

        return $stats;
    }

    public function getWeekdayStats(bool $excludeToday = true): array
    {
        $conn = $this->getEntityManager()->getConnection();

        // Exclure aujourd'hui pour ne pas fausser les stats
        $endCondition = $excludeToday ? 'AND DATE(smoked_at) < CURDATE()' : '';

        $userId = $this->getUserId();
        $userCondition = $userId ? 'AND user_id = :user_id' : '';

        // Compter les clopes par jour de la semaine ET le nombre de jours distincts
        $sql = "
            SELECT
                DAYOFWEEK(smoked_at) as day_num,
                COUNT(id) as count,
                COUNT(DISTINCT DATE(smoked_at)) as day_count
            FROM cigarette
            WHERE 1=1 {$endCondition} {$userCondition}
            GROUP BY DAYOFWEEK(smoked_at)
            ORDER BY day_num
        ";

        $params = $userId ? ['user_id' => $userId] : [];
        $results = $conn->executeQuery($sql, $params)->fetchAllAssociative();

        // MySQL: 1=Dimanche, 2=Lundi, etc.
        $days = [1 => 'Dim', 2 => 'Lun', 3 => 'Mar', 4 => 'Mer', 5 => 'Jeu', 6 => 'Ven', 7 => 'Sam'];
        $stats = [];
        foreach ($results as $row) {
            $dayCount = (int) $row['day_count'];
            $avg = $dayCount > 0 ? round((int) $row['count'] / $dayCount, 1) : 0;
            $stats[$days[(int) $row['day_num']]] = $avg;
        }

        return $stats;
    }

    public function getHourlyStats(bool $excludeToday = true): array
    {
        $conn = $this->getEntityManager()->getConnection();

        $userId = $this->getUserId();

        // Exclure aujourd'hui pour ne pas fausser les stats
        $baseCondition = $excludeToday ? 'DATE(smoked_at) < CURDATE()' : '1=1';
        $userCondition = $userId ? 'AND user_id = :user_id' : '';
        $params = $userId ? ['user_id' => $userId] : [];

        // Nombre total de jours avec des données (hors aujourd'hui)
        $totalDays = $conn->executeQuery(
            "SELECT COUNT(DISTINCT DATE(smoked_at)) FROM cigarette WHERE {$baseCondition} {$userCondition}",
            $params
        )->fetchOne();
        $totalDays = max(1, (int) $totalDays);

        $sql = "
            SELECT HOUR(smoked_at) as hour, COUNT(id) as count
            FROM cigarette
            WHERE {$baseCondition} {$userCondition}
            GROUP BY HOUR(smoked_at)
            ORDER BY hour
        ";

        $results = $conn->executeQuery($sql, $params)->fetchAllAssociative();

        $stats = array_fill(0, 24, 0);
        foreach ($results as $row) {
            $avg = round((int) $row['count'] / $totalDays, 2);
            $stats[(int) $row['hour']] = $avg;
        }

        return $stats;
    }

    public function getTotalCount(): int
    {
        $qb = $this->createQueryBuilder('c')
            ->select('COUNT(c.id)');

        $user = $this->getCurrentUser();
        if ($user) {
            $qb->where('c.user = :user')->setParameter('user', $user);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    public function getMinDailyCount(bool $excludeToday = true): ?int
    {
        $conn = $this->getEntityManager()->getConnection();

        $userId = $this->getUserId();

        // Exclure aujourd'hui pour ne pas fausser le record (journée incomplète)
        $baseCondition = $excludeToday ? 'DATE(smoked_at) < CURDATE()' : '1=1';
        $userCondition = $userId ? 'AND user_id = :user_id' : '';
        $params = $userId ? ['user_id' => $userId] : [];

        $sql = "
            SELECT COUNT(id) as count
            FROM cigarette
            WHERE {$baseCondition} {$userCondition}
            GROUP BY DATE(smoked_at)
            ORDER BY count ASC
            LIMIT 1
        ";

        $result = $conn->executeQuery($sql, $params)->fetchOne();
        return $result !== false ? (int) $result : null;
    }

    /**
     * Calcule l'intervalle moyen entre clopes pour chaque jour
     * @return array ['2024-12-01' => 45, '2024-12-02' => 52, ...] (en minutes)
     */
    public function getDailyAverageInterval(int $days = 7, bool $excludeToday = true): array
    {
        $intervals = [];

        // Commencer à partir d'hier si on exclut aujourd'hui
        $startOffset = $excludeToday ? 1 : 0;

        for ($i = $days - 1 + $startOffset; $i >= $startOffset; $i--) {
            $date = new \DateTime("-{$i} days");
            $cigs = $this->findByDate($date);

            if (count($cigs) < 2) {
                continue;
            }

            $totalMinutes = 0;
            for ($j = 1; $j < count($cigs); $j++) {
                $diff = $cigs[$j]->getSmokedAt()->getTimestamp() - $cigs[$j - 1]->getSmokedAt()->getTimestamp();
                $totalMinutes += $diff / 60;
            }

            $avgMinutes = $totalMinutes / (count($cigs) - 1);
            $intervals[$date->format('Y-m-d')] = round($avgMinutes);
        }

        return $intervals;
    }

    /**
     * Stats pour comparaison semaine glissante (optimized - single query)
     * @return array ['current' => [...], 'previous' => [...]]
     */
    public function getWeeklyComparison(bool $excludeToday = true): array
    {
        $conn = $this->getEntityManager()->getConnection();

        $userId = $this->getUserId();

        // Exclure aujourd'hui pour ne pas fausser les stats
        $endCondition = $excludeToday ? 'AND DATE(smoked_at) < CURDATE()' : '';
        $userCondition = $userId ? 'AND user_id = :user_id' : '';

        // Single query for both weeks
        $sql = "
            SELECT DATE(smoked_at) as date, COUNT(id) as count
            FROM cigarette
            WHERE smoked_at >= :start {$endCondition} {$userCondition}
            GROUP BY DATE(smoked_at)
            ORDER BY date ASC
        ";

        // Décaler d'un jour si on exclut aujourd'hui
        $offset = $excludeToday ? 1 : 0;
        $startDate = new \DateTime('-' . (13 + $offset) . ' days');
        $startDate->setTime(0, 0, 0);

        $params = ['start' => $startDate->format('Y-m-d H:i:s')];
        if ($userId) {
            $params['user_id'] = $userId;
        }

        $results = $conn->executeQuery($sql, $params)->fetchAllAssociative();

        // Build lookup map
        $countsByDate = [];
        foreach ($results as $row) {
            $countsByDate[$row['date']] = (int) $row['count'];
        }

        $currentWeek = [];
        $previousWeek = [];

        // Semaine actuelle (7 derniers jours complets, hors aujourd'hui si excludeToday)
        for ($i = 6 + $offset; $i >= $offset; $i--) {
            $date = (new \DateTime("-{$i} days"))->format('Y-m-d');
            $currentWeek[$date] = $countsByDate[$date] ?? 0;
        }

        // Semaine précédente (jours 7 à 13 avant la semaine actuelle)
        for ($i = 13 + $offset; $i >= 7 + $offset; $i--) {
            $date = (new \DateTime("-{$i} days"))->format('Y-m-d');
            $previousWeek[$date] = $countsByDate[$date] ?? 0;
        }

        $currentTotal = array_sum($currentWeek);
        $previousTotal = array_sum($previousWeek);
        $currentAvg = count($currentWeek) > 0 ? round($currentTotal / count($currentWeek), 1) : 0;
        $previousAvg = count($previousWeek) > 0 ? round($previousTotal / count($previousWeek), 1) : 0;

        return [
            'current' => [
                'days' => $currentWeek,
                'total' => $currentTotal,
                'avg' => $currentAvg,
            ],
            'previous' => [
                'days' => $previousWeek,
                'total' => $previousTotal,
                'avg' => $previousAvg,
            ],
            'diff_total' => $currentTotal - $previousTotal,
            'diff_avg' => round($currentAvg - $previousAvg, 1),
        ];
    }

    /**
     * Find cigarettes for a date range (single query for batch operations)
     * @return array<string, array<Cigarette>>
     */
    public function findByDateRange(\DateTimeInterface $startDate, \DateTimeInterface $endDate): array
    {
        $start = (clone $startDate)->setTime(0, 0, 0);
        $end = (clone $endDate)->setTime(23, 59, 59);

        $qb = $this->createQueryBuilder('c')
            ->where('c.smokedAt >= :start')
            ->andWhere('c.smokedAt <= :end')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->orderBy('c.smokedAt', 'ASC');

        $user = $this->getCurrentUser();
        if ($user) {
            $qb->andWhere('c.user = :user')->setParameter('user', $user);
        }

        $cigarettes = $qb->getQuery()->getResult();

        // Group by date
        $grouped = [];
        foreach ($cigarettes as $cig) {
            $date = $cig->getSmokedAt()->format('Y-m-d');
            if (!isset($grouped[$date])) {
                $grouped[$date] = [];
            }
            $grouped[$date][] = $cig;
        }

        return $grouped;
    }
}
