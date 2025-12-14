<?php

namespace App\Service;

use App\Entity\DailyScore;
use App\Entity\User;
use App\Repository\CigaretteRepository;
use App\Repository\DailyScoreRepository;
use App\Repository\SettingsRepository;
use App\Repository\WakeUpRepository;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Service principal de scoring
 * Délègue les calculs spécifiques aux services dédiés :
 * - IntervalCalculator : calculs d'intervalles
 * - StreakService : gestion des streaks
 * - RankService : calcul des rangs
 */
class ScoringService
{
    public function __construct(
        private CigaretteRepository $cigaretteRepository,
        private WakeUpRepository $wakeUpRepository,
        private DailyScoreRepository $dailyScoreRepository,
        private SettingsRepository $settingsRepository,
        private Security $security,
        private IntervalCalculator $intervalCalculator,
        private StreakService $streakService,
        private RankService $rankService
    ) {}

    private function getCurrentUser(): ?User
    {
        $user = $this->security->getUser();
        return $user instanceof User ? $user : null;
    }

    /**
     * Invalide le cache (à appeler après modification des données)
     */
    public function invalidateCache(): void
    {
        $this->intervalCalculator->invalidateCache();
    }

    // ========== Délégation aux services spécialisés ==========

    /**
     * @deprecated Utiliser IntervalCalculator::timeToMinutes() directement
     */
    public static function timeToMinutes(\DateTimeInterface $time): int
    {
        return IntervalCalculator::timeToMinutes($time);
    }

    /**
     * @deprecated Utiliser IntervalCalculator::minutesSinceWakeUp() directement
     */
    public static function minutesSinceWakeUp(\DateTimeInterface $time, \DateTimeInterface $wakeTime): int
    {
        return IntervalCalculator::minutesSinceWakeUp($time, $wakeTime);
    }

    /**
     * @deprecated Utiliser IntervalCalculator::getPointsForDiff() directement
     */
    public static function getPointsForDiff(float $diff, float $interval): int
    {
        return IntervalCalculator::getPointsForDiff($diff, $interval);
    }

    public function getDayAverageInterval(array $cigs): float
    {
        return $this->intervalCalculator->getDayAverageInterval($cigs);
    }

    public function getSmoothedAverageInterval(\DateTimeInterface $today): float
    {
        return $this->intervalCalculator->getSmoothedAverageInterval($today);
    }

    public function getSmoothedFirstCigTime(\DateTimeInterface $today): float
    {
        return $this->intervalCalculator->getSmoothedFirstCigTime($today);
    }

    public function calculateTargetMinutes(int $index, array $todayCigs, $todayWakeUp, \DateTimeInterface $today): float
    {
        return $this->intervalCalculator->calculateTargetMinutes($index, $todayCigs, $todayWakeUp, $today);
    }

    public function hasHistoricalData(\DateTimeInterface $today): bool
    {
        return $this->intervalCalculator->hasHistoricalData($today);
    }

    /**
     * Récupère le streak pour l'affichage (version optimisée)
     * Utilise les DailyScore pré-calculés
     */
    public function getStreak(): array
    {
        return $this->streakService->getStreakOptimized();
    }

    /**
     * Calcule le streak en recalculant tout (utilisé pour persistance)
     * Plus coûteux mais nécessaire pour la mise à jour des DailyScore
     */
    public function getStreakFull(): array
    {
        return $this->streakService->getStreak();
    }

    public function getCurrentRank(): array
    {
        return $this->rankService->getCurrentRank();
    }

    // ========== Méthodes de persistance ==========

    /**
     * Persiste le score du jour dans DailyScore (optimisation performance)
     */
    public function persistDailyScore(\DateTimeInterface $date): void
    {
        $user = $this->getCurrentUser();
        if (!$user) {
            return;
        }

        $dateOnly = (clone $date)->setTime(0, 0, 0);
        $dailyScoreData = $this->calculateDailyScore($date);
        $cigs = $this->cigaretteRepository->findByDate($date);

        // Calculer l'intervalle moyen
        $avgInterval = null;
        if (count($cigs) >= 2) {
            $totalMinutes = 0;
            for ($i = 1; $i < count($cigs); $i++) {
                $diff = $cigs[$i]->getSmokedAt()->getTimestamp() - $cigs[$i - 1]->getSmokedAt()->getTimestamp();
                $totalMinutes += $diff / 60;
            }
            $avgInterval = $totalMinutes / (count($cigs) - 1);
        }

        // Calculer le streak (version complète pour persistance)
        $streakData = $this->getStreakFull();

        // Score final = total_score + tous les bonus potentiels (maintenant réalisés)
        $finalScore = $dailyScoreData['total_score']
            + ($dailyScoreData['potential_reduction_bonus'] ?? 0)
            + ($dailyScoreData['potential_regularity_bonus'] ?? 0)
            + ($dailyScoreData['potential_weekly_bonus'] ?? 0);

        $dailyScore = new DailyScore();
        $dailyScore->setUser($user);
        $dailyScore->setDate($dateOnly);
        $dailyScore->setScore($finalScore);
        $dailyScore->setCigaretteCount(count($cigs));
        $dailyScore->setStreak($streakData['current']);
        $dailyScore->setAverageInterval($avgInterval);

        $this->dailyScoreRepository->upsert($dailyScore);
    }

    /**
     * Récupère le score total depuis les DailyScore pré-calculés (O(1))
     */
    public function getTotalScoreOptimized(): int
    {
        return $this->dailyScoreRepository->getTotalScore();
    }

    // ========== Calculs de score ==========

    /**
     * Calcule les infos pour la prochaine clope (utilisé par le timer)
     */
    public function getNextCigaretteInfo(\DateTimeInterface $date): array
    {
        $todayCigs = $this->cigaretteRepository->findByDate($date);
        $todayWakeUp = $this->wakeUpRepository->findByDate($date);

        // Premier jour : pas de données historiques
        if (!$this->hasHistoricalData($date)) {
            return ['status' => 'first_day', 'message' => 'Premier jour - pas de comparaison'];
        }

        // Pas de réveil aujourd'hui
        if (!$todayWakeUp) {
            return ['status' => 'no_wakeup', 'message' => 'Enregistre ton heure de réveil'];
        }

        $nextIndex = count($todayCigs);
        $wakeUpMinutes = IntervalCalculator::timeToMinutes($todayWakeUp->getWakeTime());

        // Calculer la cible et l'intervalle moyen lissé sur 7 jours
        $targetMinutes = $this->calculateTargetMinutes($nextIndex, $todayCigs, $todayWakeUp, $date);
        $avgInterval = $this->getSmoothedAverageInterval($date);

        // Nombre de clopes hier (pour info)
        $yesterday = (clone $date)->modify('-1 day');
        $yesterdayCigs = $this->cigaretteRepository->findByDate($yesterday);
        $yesterdayTotal = count($yesterdayCigs);

        // Déterminer le statut
        $status = 'active';
        $exceeded = false;
        if ($yesterdayTotal > 0 && $nextIndex >= $yesterdayTotal) {
            $exceeded = true;
            $status = 'exceeded';
        }

        return [
            'status' => $status,
            'wake_time' => $todayWakeUp->getWakeTime()->format('H:i'),
            'wake_minutes' => $wakeUpMinutes,
            'target_minutes' => round($targetMinutes, 1),
            'avg_interval' => round($avgInterval, 1),
            'exceeded' => $exceeded,
            'yesterday_count' => $yesterdayTotal,
            'today_count' => $nextIndex,
        ];
    }

    /**
     * Calcule le score du jour
     */
    public function calculateDailyScore(\DateTimeInterface $date): array
    {
        $todayCigs = $this->cigaretteRepository->findByDate($date);
        $todayWakeUp = $this->wakeUpRepository->findByDate($date);

        // Premier jour : pas de données historiques
        if (!$this->hasHistoricalData($date)) {
            return [
                'date' => $date->format('Y-m-d'),
                'total_score' => 0,
                'cigarette_count' => count($todayCigs),
                'details' => ['message' => 'Premier jour - pas de comparaison'],
            ];
        }

        // Nombre de clopes hier (pour info)
        $yesterday = (clone $date)->modify('-1 day');
        $yesterdayCigs = $this->cigaretteRepository->findByDate($yesterday);
        $yesterdayCount = count($yesterdayCigs);

        // Intervalle moyen lissé (pour calcul des points)
        $avgInterval = $this->getSmoothedAverageInterval($date);

        $totalScore = 0;
        $comparisons = [];

        foreach ($todayCigs as $index => $todayCig) {
            // Calculer la cible avec moyenne lissée sur 7 jours
            $targetMinutes = $this->calculateTargetMinutes($index, $todayCigs, $todayWakeUp, $date);

            if ($todayWakeUp) {
                $actualMinutes = IntervalCalculator::minutesSinceWakeUp(
                    $todayCig->getSmokedAt(),
                    $todayWakeUp->getWakeTime()
                );
            } else {
                $actualMinutes = IntervalCalculator::timeToMinutes($todayCig->getSmokedAt());
            }

            $diff = $actualMinutes - $targetMinutes;
            $points = IntervalCalculator::getPointsForDiff($diff, $avgInterval);

            $totalScore += $points;
            $comparisons[] = [
                'index' => $index + 1,
                'target' => round($targetMinutes),
                'actual' => round($actualMinutes),
                'diff' => round($diff),
                'points' => $points,
                'exceeded' => $yesterdayCount > 0 && $index >= $yesterdayCount,
            ];
        }

        // === BONUS POTENTIELS ===
        // Ces bonus ne sont PAS inclus dans le score temps réel
        // Ils seront ajoutés a posteriori lors de la persistance (fin de journée)
        // car la journée n'est pas terminée et l'utilisateur peut encore fumer

        // Bonus de réduction vs hier (potentiel)
        $potentialReductionBonus = 0;
        if ($yesterdayCount > 0 && count($todayCigs) < $yesterdayCount) {
            $potentialReductionBonus = ($yesterdayCount - count($todayCigs)) * 5; // 5 pts par clope en moins
        }

        // Bonus de régularité (potentiel) : si tous les intervalles sont positifs
        $potentialRegularityBonus = 0;
        if (count($todayCigs) >= 3) {
            $allPositive = true;
            foreach ($comparisons as $comp) {
                if ($comp['points'] < 0) {
                    $allPositive = false;
                    break;
                }
            }
            if ($allPositive) {
                $potentialRegularityBonus = 10;
            }
        }

        // Bonus tendance hebdo (potentiel)
        $potentialWeeklyBonus = $this->calculateWeeklyReductionBonus($date);

        // total_score = uniquement les points des cigarettes (pas les bonus)
        // Les bonus seront ajoutés lors de la persistance en fin de journée

        return [
            'date' => $date->format('Y-m-d'),
            'total_score' => $totalScore,
            'cigarette_count' => count($todayCigs),
            'yesterday_count' => $yesterdayCount,
            // Bonus potentiels (non inclus dans total_score, affichés séparément)
            'potential_reduction_bonus' => $potentialReductionBonus,
            'potential_regularity_bonus' => $potentialRegularityBonus,
            'potential_weekly_bonus' => $potentialWeeklyBonus,
            'details' => [
                'comparisons' => $comparisons,
            ],
        ];
    }

    /**
     * Récupère le palier automatique actuel depuis les settings
     * Le calcul est fait par GoalService::getTierInfo()
     */
    private function calculateAutoTier(\DateTimeInterface $date): int
    {
        // Le palier est calculé et stocké par GoalService
        $currentTier = $this->settingsRepository->get('current_auto_tier');

        if ($currentTier !== null) {
            return (int) $currentTier;
        }

        // Fallback sur la valeur initiale si pas encore calculé
        return (int) $this->settingsRepository->get('initial_daily_cigs', '20');
    }

    /**
     * Calcule le bonus de réduction semaine/semaine
     * Compare la moyenne de cette semaine vs semaine précédente
     * +15 pts si réduction >= 1 clope/jour, +5 si stable
     */
    private function calculateWeeklyReductionBonus(\DateTimeInterface $date): int
    {
        $comparison = $this->cigaretteRepository->getWeeklyComparison();

        if ($comparison === null) {
            return 0; // Pas assez de données
        }

        $diffAvg = $comparison['diff_avg']; // Négatif = réduction

        if ($diffAvg <= -1) {
            // Réduction significative (>= 1 clope/jour de moins)
            return 15;
        } elseif ($diffAvg <= 0) {
            // Légère réduction ou stable
            return 5;
        } else {
            // Augmentation : pas de bonus
            return 0;
        }
    }

    /**
     * Calcule le score total depuis le début
     * Optimisé : charge toutes les données en 2 requêtes au lieu de N*14
     */
    public function getTotalScore(): int
    {
        $firstDate = $this->cigaretteRepository->getFirstCigaretteDate();
        if (!$firstDate) {
            return 0;
        }

        $today = new \DateTime();
        $today->setTime(23, 59, 59);

        // Charger TOUTES les données en 2 requêtes
        $allCigarettes = $this->cigaretteRepository->findByDateRange($firstDate, $today);
        $allWakeups = $this->wakeUpRepository->findByDateRange($firstDate, $today);

        $total = 0;
        $currentDate = clone $firstDate;

        while ($currentDate <= $today) {
            $dailyScore = $this->intervalCalculator->calculateDailyScoreFromData(
                $currentDate,
                $allCigarettes,
                $allWakeups
            );
            $total += $dailyScore;
            $currentDate->modify('+1 day');
        }

        return $total;
    }
}
