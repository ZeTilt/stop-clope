<?php

namespace App\Service;

use App\Repository\CigaretteRepository;
use App\Repository\SettingsRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Service dédié aux calculs statistiques
 * Extrait de HomeController pour une meilleure maintenabilité
 */
class StatsService
{
    public function __construct(
        private CigaretteRepository $cigaretteRepository,
        private SettingsRepository $settingsRepository,
        private ScoringService $scoringService,
        private Security $security,
        private CacheInterface $scoringCache
    ) {}

    /**
     * Calcule les économies réalisées depuis le début
     * Exclut aujourd'hui car la journée n'est pas terminée
     */
    public function calculateSavings(): array
    {
        $packPrice = (float) $this->settingsRepository->get('pack_price', '12.00');
        $cigsPerPack = (int) $this->settingsRepository->get('cigs_per_pack', '20');
        $initialDailyCigs = (int) $this->settingsRepository->get('initial_daily_cigs', '20');

        // Prevent division by zero
        if ($cigsPerPack <= 0) {
            $cigsPerPack = 20;
        }

        $pricePerCig = $packPrice / $cigsPerPack;

        // Calculer depuis le premier jour
        $firstDate = $this->cigaretteRepository->getFirstCigaretteDate();
        if (!$firstDate) {
            return [
                'total' => 0,
                'daily_avg' => 0,
                'cigs_avoided' => 0,
                'days' => 0,
                'initial_daily' => $initialDailyCigs,
                'price_per_cig' => round($pricePerCig, 2),
            ];
        }

        // Exclure aujourd'hui : compter uniquement les jours complets (jusqu'à hier)
        $yesterday = (new \DateTime())->modify('-1 day')->setTime(23, 59, 59);
        $firstDateNormalized = (clone $firstDate)->setTime(0, 0, 0);

        // Si on a commencé aujourd'hui, pas encore d'économies calculables
        if ($firstDateNormalized->format('Y-m-d') === (new \DateTime())->format('Y-m-d')) {
            return [
                'total' => 0,
                'daily_avg' => 0,
                'cigs_avoided' => 0,
                'days' => 0,
                'initial_daily' => $initialDailyCigs,
                'price_per_cig' => round($pricePerCig, 2),
                'message' => 'Les économies seront calculées à partir de demain',
            ];
        }

        // Compter les clopes jusqu'à hier (exclure aujourd'hui)
        $totalCigs = $this->cigaretteRepository->getTotalCountUntil($yesterday);

        // Nombre de jours complets (du premier jour jusqu'à hier inclus)
        $daysCompleted = max(1, $yesterday->diff($firstDateNormalized)->days + 1);

        // Clopes qu'on aurait fumées sans changement
        $expectedCigs = $initialDailyCigs * $daysCompleted;
        $cigsAvoided = max(0, $expectedCigs - $totalCigs);

        $totalSaved = $cigsAvoided * $pricePerCig;
        $dailyAvg = $totalCigs / $daysCompleted;

        return [
            'total' => round($totalSaved, 2),
            'cigs_avoided' => $cigsAvoided,
            'daily_avg' => round($dailyAvg, 1),
            'days' => $daysCompleted,
            'initial_daily' => $initialDailyCigs,
            'price_per_cig' => round($pricePerCig, 2),
            'expected_cigs' => $expectedCigs,
            'total_cigs' => $totalCigs,
        ];
    }

    /**
     * Calcule les scores hebdomadaires pour l'affichage
     */
    public function getWeeklyScores(): array
    {
        $firstDate = $this->cigaretteRepository->getFirstCigaretteDate();
        $weeklyScores = [];

        if (!$firstDate) {
            return $weeklyScores;
        }

        $date = new \DateTime('-7 days');
        $firstDateNormalized = (clone $firstDate)->setTime(0, 0, 0);
        $todayNormalized = (new \DateTime())->setTime(0, 0, 0);

        for ($i = 0; $i < 7; $i++) {
            $currentDateNormalized = (clone $date)->setTime(0, 0, 0);
            // N'inclure que les jours à partir du premier jour ET avant aujourd'hui
            if ($currentDateNormalized >= $firstDateNormalized && $currentDateNormalized < $todayNormalized) {
                $dailyScore = $this->scoringService->calculateDailyScore($date);
                $weeklyScores[$date->format('Y-m-d')] = $dailyScore;
            }
            $date->modify('+1 day');
        }

        return $weeklyScores;
    }

    /**
     * Obtient toutes les statistiques pour la page stats
     * Résultats mis en cache pour 60 secondes par utilisateur
     */
    public function getFullStats(): array
    {
        $user = $this->security->getUser();
        $userId = $user ? $user->getId() : 'anon';
        $cacheKey = "stats_full_{$userId}";

        return $this->scoringCache->get($cacheKey, function (ItemInterface $item) {
            $item->expiresAfter(60); // TTL 60 secondes

            return [
                'monthly_stats' => $this->cigaretteRepository->getDailyStats(30),
                'weekly_scores' => $this->getWeeklyScores(),
                'weekday_stats' => $this->cigaretteRepository->getWeekdayStats(),
                'hourly_stats' => $this->cigaretteRepository->getHourlyStats(),
                'daily_intervals' => $this->cigaretteRepository->getDailyAverageInterval(7),
                'weekly_comparison' => $this->cigaretteRepository->getTrendComparison(),
                'savings' => $this->calculateSavings(),
                'first_date' => $this->cigaretteRepository->getFirstCigaretteDate(),
            ];
        });
    }

    /**
     * Invalide le cache des stats (à appeler après modification des données)
     */
    public function invalidateCache(): void
    {
        $user = $this->security->getUser();
        $userId = $user ? $user->getId() : 'anon';
        $this->scoringCache->delete("stats_full_{$userId}");
    }

    /**
     * Calcule le meilleur et pire jour de la semaine
     */
    public function getBestAndWorstDays(): array
    {
        $weekdayStats = $this->cigaretteRepository->getWeekdayStats();

        if (empty($weekdayStats)) {
            return ['best' => null, 'worst' => null];
        }

        $best = null;
        $worst = null;
        $bestAvg = PHP_INT_MAX;
        $worstAvg = 0;

        foreach ($weekdayStats as $day => $stats) {
            $avg = $stats['avg'] ?? 0;
            if ($avg < $bestAvg && $avg > 0) {
                $bestAvg = $avg;
                $best = ['day' => $day, 'avg' => $avg];
            }
            if ($avg > $worstAvg) {
                $worstAvg = $avg;
                $worst = ['day' => $day, 'avg' => $avg];
            }
        }

        return ['best' => $best, 'worst' => $worst];
    }

    /**
     * Calcule les tendances sur les derniers jours
     */
    public function getTrends(int $days = 7): array
    {
        $stats = $this->cigaretteRepository->getDailyStats($days);

        if (count($stats) < 2) {
            return [
                'direction' => 'stable',
                'change' => 0,
                'trend_emoji' => '➡️',
            ];
        }

        // Comparer la première et la dernière moitié
        $half = (int) floor(count($stats) / 2);
        $firstHalf = array_slice($stats, 0, $half);
        $secondHalf = array_slice($stats, $half);

        $firstAvg = array_sum(array_column($firstHalf, 'count')) / count($firstHalf);
        $secondAvg = array_sum(array_column($secondHalf, 'count')) / count($secondHalf);

        $change = $secondAvg - $firstAvg;

        if ($change < -0.5) {
            return [
                'direction' => 'down',
                'change' => round($change, 1),
                'trend_emoji' => '📉',
            ];
        } elseif ($change > 0.5) {
            return [
                'direction' => 'up',
                'change' => round($change, 1),
                'trend_emoji' => '📈',
            ];
        } else {
            return [
                'direction' => 'stable',
                'change' => round($change, 1),
                'trend_emoji' => '➡️',
            ];
        }
    }

    /**
     * Projection : combien de jours pour atteindre l'objectif
     */
    public function getProjection(): ?array
    {
        $savings = $this->calculateSavings();
        $weeklyComparison = $this->cigaretteRepository->getWeeklyComparison();

        if (!$weeklyComparison || $savings['days'] < 7) {
            return null;
        }

        $currentAvg = $savings['daily_avg'];
        $weeklyReduction = -($weeklyComparison['diff_avg'] ?? 0); // Inverse car négatif = réduction

        if ($weeklyReduction <= 0) {
            // Pas de réduction, pas de projection possible
            return null;
        }

        // À ce rythme, combien de semaines pour atteindre 0 ?
        $weeksToZero = $currentAvg / $weeklyReduction;
        $daysToZero = (int) ceil($weeksToZero * 7);

        return [
            'current_avg' => round($currentAvg, 1),
            'weekly_reduction' => round($weeklyReduction, 1),
            'days_to_zero' => $daysToZero,
            'target_date' => (new \DateTime())->modify("+{$daysToZero} days")->format('d/m/Y'),
        ];
    }
}
