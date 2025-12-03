<?php

namespace App\Repository;

use App\Entity\Cigarette;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Cigarette>
 */
class CigaretteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Cigarette::class);
    }

    public function findByDate(\DateTimeInterface $date): array
    {
        $start = (clone $date)->setTime(0, 0, 0);
        $end = (clone $date)->setTime(23, 59, 59);

        return $this->createQueryBuilder('c')
            ->where('c.smokedAt >= :start')
            ->andWhere('c.smokedAt <= :end')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->orderBy('c.smokedAt', 'ASC')
            ->getQuery()
            ->getResult();
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
        return count($this->findByDate($date));
    }

    public function getFirstCigaretteDate(): ?\DateTimeInterface
    {
        $result = $this->createQueryBuilder('c')
            ->select('c.smokedAt')
            ->orderBy('c.smokedAt', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $result ? $result['smokedAt'] : null;
    }

    public function getDailyStats(int $days = 30): array
    {
        $startDate = new \DateTime("-{$days} days");
        $startDate->setTime(0, 0, 0);

        $conn = $this->getEntityManager()->getConnection();
        $sql = '
            SELECT DATE(smoked_at) as date, COUNT(id) as count
            FROM cigarette
            WHERE smoked_at >= :start
            GROUP BY DATE(smoked_at)
            ORDER BY date ASC
        ';

        $results = $conn->executeQuery($sql, ['start' => $startDate->format('Y-m-d H:i:s')])->fetchAllAssociative();

        $stats = [];
        foreach ($results as $row) {
            $stats[$row['date']] = (int) $row['count'];
        }

        return $stats;
    }

    public function getWeekdayStats(): array
    {
        $conn = $this->getEntityManager()->getConnection();

        // Compter les clopes par jour de la semaine ET le nombre de jours distincts
        $sql = '
            SELECT
                DAYOFWEEK(smoked_at) as day_num,
                COUNT(id) as count,
                COUNT(DISTINCT DATE(smoked_at)) as day_count
            FROM cigarette
            GROUP BY DAYOFWEEK(smoked_at)
            ORDER BY day_num
        ';

        $results = $conn->executeQuery($sql)->fetchAllAssociative();

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

    public function getHourlyStats(): array
    {
        $conn = $this->getEntityManager()->getConnection();

        // Nombre total de jours avec des données
        $totalDays = $conn->executeQuery('SELECT COUNT(DISTINCT DATE(smoked_at)) FROM cigarette')->fetchOne();
        $totalDays = max(1, (int) $totalDays);

        $sql = '
            SELECT HOUR(smoked_at) as hour, COUNT(id) as count
            FROM cigarette
            GROUP BY HOUR(smoked_at)
            ORDER BY hour
        ';

        $results = $conn->executeQuery($sql)->fetchAllAssociative();

        $stats = array_fill(0, 24, 0);
        foreach ($results as $row) {
            $avg = round((int) $row['count'] / $totalDays, 2);
            $stats[(int) $row['hour']] = $avg;
        }

        return $stats;
    }

    public function getTotalCount(): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function getMinDailyCount(): ?int
    {
        $conn = $this->getEntityManager()->getConnection();
        $sql = '
            SELECT COUNT(id) as count
            FROM cigarette
            GROUP BY DATE(smoked_at)
            ORDER BY count ASC
            LIMIT 1
        ';

        $result = $conn->executeQuery($sql)->fetchOne();
        return $result !== false ? (int) $result : null;
    }

    /**
     * Calcule l'intervalle moyen entre clopes pour chaque jour
     * @return array ['2024-12-01' => 45, '2024-12-02' => 52, ...] (en minutes)
     */
    public function getDailyAverageInterval(int $days = 7): array
    {
        $intervals = [];

        for ($i = $days - 1; $i >= 0; $i--) {
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
     * Stats pour comparaison semaine glissante
     * @return array ['current' => [...], 'previous' => [...]]
     */
    public function getWeeklyComparison(): array
    {
        $currentWeek = [];
        $previousWeek = [];

        // Semaine actuelle (7 derniers jours)
        for ($i = 6; $i >= 0; $i--) {
            $date = new \DateTime("-{$i} days");
            $currentWeek[$date->format('Y-m-d')] = $this->countByDate($date);
        }

        // Semaine précédente (jours 7 à 13)
        for ($i = 13; $i >= 7; $i--) {
            $date = new \DateTime("-{$i} days");
            $previousWeek[$date->format('Y-m-d')] = $this->countByDate($date);
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
}
