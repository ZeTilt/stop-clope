<?php

namespace App\Service;

use App\Entity\DailyScore;
use App\Entity\User;
use App\Repository\CigaretteRepository;
use App\Repository\DailyScoreRepository;
use App\Repository\WakeUpRepository;
use Symfony\Bundle\SecurityBundle\Security;

class ScoringService
{
    private ?array $cachedHistoricalData = null;
    private ?string $cacheDate = null;

    public function __construct(
        private CigaretteRepository $cigaretteRepository,
        private WakeUpRepository $wakeUpRepository,
        private DailyScoreRepository $dailyScoreRepository,
        private Security $security
    ) {}

    private function getCurrentUser(): ?User
    {
        $user = $this->security->getUser();
        return $user instanceof User ? $user : null;
    }

    /**
     * Charge les données historiques des 7 derniers jours en 2 requêtes
     * @return array ['cigarettes' => [...], 'wakeups' => [...]]
     */
    private function loadHistoricalData(\DateTimeInterface $today): array
    {
        $todayStr = $today->format('Y-m-d');

        // Utiliser le cache si disponible pour la même date
        if ($this->cachedHistoricalData !== null && $this->cacheDate === $todayStr) {
            return $this->cachedHistoricalData;
        }

        $startDate = (clone $today)->modify('-7 days');
        $endDate = (clone $today)->modify('-1 day');

        // 2 requêtes au lieu de 14
        $cigarettes = $this->cigaretteRepository->findByDateRange($startDate, $endDate);
        $wakeups = $this->wakeUpRepository->findByDateRange($startDate, $endDate);

        $this->cachedHistoricalData = [
            'cigarettes' => $cigarettes,
            'wakeups' => $wakeups,
        ];
        $this->cacheDate = $todayStr;

        return $this->cachedHistoricalData;
    }

    /**
     * Invalide le cache (à appeler après modification des données)
     */
    public function invalidateCache(): void
    {
        $this->cachedHistoricalData = null;
        $this->cacheDate = null;
    }

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

        // Calculer le streak
        $streakData = $this->getStreak();

        $dailyScore = new DailyScore();
        $dailyScore->setUser($user);
        $dailyScore->setDate($dateOnly);
        $dailyScore->setScore($dailyScoreData['total_score']);
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

    /**
     * Récupère le streak depuis les DailyScore pré-calculés (O(1))
     */
    public function getStreakOptimized(): array
    {
        return [
            'current' => $this->dailyScoreRepository->getCurrentStreak(),
            'best' => $this->dailyScoreRepository->getBestStreak(),
            'today_positive' => false, // À calculer en temps réel si besoin
        ];
    }

    /**
     * Convertit une heure (HH:MM) en minutes depuis minuit
     */
    public static function timeToMinutes(\DateTimeInterface $time): int
    {
        return (int) $time->format('H') * 60 + (int) $time->format('i');
    }

    /**
     * Calcule les minutes depuis le réveil pour une heure donnée
     */
    public static function minutesSinceWakeUp(\DateTimeInterface $time, \DateTimeInterface $wakeTime): int
    {
        return self::timeToMinutes($time) - self::timeToMinutes($wakeTime);
    }

    /**
     * Calcule les points pour une différence donnée (en minutes)
     * Proportionnel à l'intervalle cible, sans plafond pour les bonus
     *
     * - diff = intervalle → 20 pts (tu as attendu 2x la cible)
     * - diff = 2*intervalle → 40 pts, etc. (linéaire, sans plafond)
     * - diff négatif → malus proportionnel, plafonné à -20
     */
    public static function getPointsForDiff(float $diff, float $interval): int
    {
        if ($interval <= 0) {
            $interval = 60; // Défaut 1h pour éviter division par 0
        }

        // Ratio : combien de fois l'intervalle on a attendu en plus/moins
        $ratio = $diff / $interval;

        if ($diff > 0.001) {
            // Positif : 20 pts par intervalle attendu en plus, minimum 1 pt
            $points = (int) round($ratio * 20);
            return max(1, $points);
        } elseif (abs($diff) < 0.001) {
            // Fix: comparaison float avec tolérance (pile à l'heure = léger malus)
            return -1;
        } else {
            // Négatif : malus proportionnel, plafonné à -20
            $points = (int) round($ratio * 20);
            return max(-20, $points);
        }
    }

    /**
     * Calcule l'intervalle moyen d'une journée (en minutes)
     */
    public function getDayAverageInterval(array $cigs): float
    {
        if (count($cigs) < 2) {
            return 0; // Pas assez de données
        }

        $firstCig = $cigs[0];
        $lastCig = $cigs[count($cigs) - 1];

        $firstMinutes = self::timeToMinutes($firstCig->getSmokedAt());
        $lastMinutes = self::timeToMinutes($lastCig->getSmokedAt());

        return ($lastMinutes - $firstMinutes) / (count($cigs) - 1);
    }

    /**
     * Calcule l'intervalle moyen lissé sur les 7 derniers jours
     * Optimisé : utilise les données en cache (2 requêtes au lieu de 7)
     */
    public function getSmoothedAverageInterval(\DateTimeInterface $today): float
    {
        $historical = $this->loadHistoricalData($today);
        $intervals = [];

        for ($i = 1; $i <= 7; $i++) {
            $dateStr = (clone $today)->modify("-{$i} day")->format('Y-m-d');
            $cigs = $historical['cigarettes'][$dateStr] ?? [];

            $dayInterval = $this->getDayAverageInterval($cigs);
            if ($dayInterval > 0) {
                $intervals[] = $dayInterval;
            }
        }

        if (empty($intervals)) {
            return 60; // Défaut : 1 heure
        }

        return array_sum($intervals) / count($intervals);
    }

    /**
     * Calcule le temps moyen de la 1ère clope (depuis réveil) sur les 7 derniers jours
     * Optimisé : utilise les données en cache (0 requête supplémentaire)
     */
    public function getSmoothedFirstCigTime(\DateTimeInterface $today): float
    {
        $historical = $this->loadHistoricalData($today);
        $times = [];

        for ($i = 1; $i <= 7; $i++) {
            $dateStr = (clone $today)->modify("-{$i} day")->format('Y-m-d');
            $cigs = $historical['cigarettes'][$dateStr] ?? [];
            $wakeUp = $historical['wakeups'][$dateStr] ?? null;

            if (!empty($cigs) && $wakeUp) {
                $times[] = self::minutesSinceWakeUp($cigs[0]->getSmokedAt(), $wakeUp->getWakeTime());
            }
        }

        if (empty($times)) {
            return 30; // Défaut : 30 min après réveil
        }

        return array_sum($times) / count($times);
    }

    /**
     * Calcule la cible (en minutes depuis réveil) pour la prochaine clope
     * Basé sur la dernière clope d'aujourd'hui + intervalle MOYEN lissé sur 7 jours
     */
    public function calculateTargetMinutes(int $index, array $todayCigs, $todayWakeUp, \DateTimeInterface $today): float
    {
        if (!$todayWakeUp) {
            return 0;
        }

        // Première clope : moyenne du temps de 1ère clope sur 7 jours
        if ($index === 0) {
            return $this->getSmoothedFirstCigTime($today);
        }

        // Clopes suivantes : dernière clope d'aujourd'hui + intervalle lissé
        $avgInterval = $this->getSmoothedAverageInterval($today);
        $todayPrevCig = $todayCigs[$index - 1];
        $todayPrevMinutes = self::minutesSinceWakeUp($todayPrevCig->getSmokedAt(), $todayWakeUp->getWakeTime());

        return $todayPrevMinutes + $avgInterval;
    }

    /**
     * Vérifie si on a des données historiques (au moins 1 jour dans les 7 derniers)
     * Optimisé : utilise les données en cache
     */
    public function hasHistoricalData(\DateTimeInterface $today): bool
    {
        $historical = $this->loadHistoricalData($today);
        return !empty($historical['cigarettes']);
    }

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
        $wakeUpMinutes = self::timeToMinutes($todayWakeUp->getWakeTime());

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
                $actualMinutes = self::minutesSinceWakeUp($todayCig->getSmokedAt(), $todayWakeUp->getWakeTime());
            } else {
                $actualMinutes = self::timeToMinutes($todayCig->getSmokedAt());
            }

            $diff = $actualMinutes - $targetMinutes;
            $points = self::getPointsForDiff($diff, $avgInterval);

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

        // Bonus de réduction : si moins de clopes qu'hier
        $reductionBonus = 0;
        if ($yesterdayCount > 0 && count($todayCigs) < $yesterdayCount) {
            $reductionBonus = ($yesterdayCount - count($todayCigs)) * 5; // 5 pts par clope en moins
        }

        // Bonus de régularité : si tous les intervalles sont positifs (aucun malus)
        $regularityBonus = 0;
        if (count($todayCigs) >= 3) {
            $allPositive = true;
            foreach ($comparisons as $comp) {
                if ($comp['points'] < 0) {
                    $allPositive = false;
                    break;
                }
            }
            if ($allPositive) {
                $regularityBonus = 10; // Bonus de régularité
            }
        }

        $totalScore += $reductionBonus + $regularityBonus;

        return [
            'date' => $date->format('Y-m-d'),
            'total_score' => $totalScore,
            'cigarette_count' => count($todayCigs),
            'yesterday_count' => $yesterdayCount,
            'reduction_bonus' => $reductionBonus,
            'regularity_bonus' => $regularityBonus,
            'details' => [
                'comparisons' => $comparisons,
            ],
        ];
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
            $dateStr = $currentDate->format('Y-m-d');
            $dailyScore = $this->calculateDailyScoreFromData(
                $currentDate,
                $allCigarettes,
                $allWakeups
            );
            $total += $dailyScore;
            $currentDate->modify('+1 day');
        }

        return $total;
    }

    /**
     * Calcule le score d'un jour à partir des données pré-chargées
     * Version optimisée sans requêtes supplémentaires
     */
    private function calculateDailyScoreFromData(
        \DateTimeInterface $date,
        array $allCigarettes,
        array $allWakeups
    ): int {
        $dateStr = $date->format('Y-m-d');
        $todayCigs = $allCigarettes[$dateStr] ?? [];
        $todayWakeUp = $allWakeups[$dateStr] ?? null;

        if (empty($todayCigs)) {
            return 0;
        }

        // Calculer l'intervalle moyen des 7 jours précédents
        $intervals = [];
        for ($i = 1; $i <= 7; $i++) {
            $prevDateStr = (clone $date)->modify("-{$i} day")->format('Y-m-d');
            $prevCigs = $allCigarettes[$prevDateStr] ?? [];

            $dayInterval = $this->getDayAverageInterval($prevCigs);
            if ($dayInterval > 0) {
                $intervals[] = $dayInterval;
            }
        }

        // Pas de données historiques = premier jour
        if (empty($intervals)) {
            return 0;
        }

        $avgInterval = array_sum($intervals) / count($intervals);

        // Calculer le temps moyen de la 1ère clope
        $firstCigTimes = [];
        for ($i = 1; $i <= 7; $i++) {
            $prevDateStr = (clone $date)->modify("-{$i} day")->format('Y-m-d');
            $prevCigs = $allCigarettes[$prevDateStr] ?? [];
            $prevWakeUp = $allWakeups[$prevDateStr] ?? null;

            if (!empty($prevCigs) && $prevWakeUp) {
                $firstCigTimes[] = self::minutesSinceWakeUp($prevCigs[0]->getSmokedAt(), $prevWakeUp->getWakeTime());
            }
        }
        $avgFirstCigTime = !empty($firstCigTimes) ? array_sum($firstCigTimes) / count($firstCigTimes) : 30;

        $totalScore = 0;

        foreach ($todayCigs as $index => $todayCig) {
            // Calculer la cible
            if ($index === 0) {
                $targetMinutes = $avgFirstCigTime;
            } else {
                $prevCig = $todayCigs[$index - 1];
                if ($todayWakeUp) {
                    $prevMinutes = self::minutesSinceWakeUp($prevCig->getSmokedAt(), $todayWakeUp->getWakeTime());
                } else {
                    $prevMinutes = self::timeToMinutes($prevCig->getSmokedAt());
                }
                $targetMinutes = $prevMinutes + $avgInterval;
            }

            if ($todayWakeUp) {
                $actualMinutes = self::minutesSinceWakeUp($todayCig->getSmokedAt(), $todayWakeUp->getWakeTime());
            } else {
                $actualMinutes = self::timeToMinutes($todayCig->getSmokedAt());
            }

            $diff = $actualMinutes - $targetMinutes;
            $points = self::getPointsForDiff($diff, $avgInterval);
            $totalScore += $points;
        }

        return $totalScore;
    }

    /**
     * Calcule le streak actuel (jours consécutifs avec score positif)
     * @return array ['current' => int, 'best' => int, 'today_positive' => bool]
     */
    public function getStreak(): array
    {
        $firstDate = $this->cigaretteRepository->getFirstCigaretteDate();
        if (!$firstDate) {
            return ['current' => 0, 'best' => 0, 'today_positive' => false];
        }

        $today = new \DateTime();
        $today->setTime(23, 59, 59);

        // Charger toutes les données
        $allCigarettes = $this->cigaretteRepository->findByDateRange($firstDate, $today);
        $allWakeups = $this->wakeUpRepository->findByDateRange($firstDate, $today);

        $currentStreak = 0;
        $bestStreak = 0;
        $tempStreak = 0;
        $todayPositive = false;

        $currentDate = clone $firstDate;
        $todayStr = (new \DateTime())->format('Y-m-d');

        while ($currentDate <= $today) {
            $dateStr = $currentDate->format('Y-m-d');
            $dailyScore = $this->calculateDailyScoreFromData($currentDate, $allCigarettes, $allWakeups);

            if ($dailyScore > 0) {
                $tempStreak++;
                if ($dateStr === $todayStr) {
                    $todayPositive = true;
                }
            } else {
                // Score nul ou négatif : reset du streak temporaire
                if ($tempStreak > $bestStreak) {
                    $bestStreak = $tempStreak;
                }
                $tempStreak = 0;
            }

            $currentDate->modify('+1 day');
        }

        // Le streak actuel est le streak qui inclut aujourd'hui (ou hier si aujourd'hui pas encore positif)
        $currentStreak = $tempStreak;
        if ($tempStreak > $bestStreak) {
            $bestStreak = $tempStreak;
        }

        return [
            'current' => $currentStreak,
            'best' => $bestStreak,
            'today_positive' => $todayPositive,
        ];
    }

    /**
     * Retourne le rang actuel
     */
    public function getCurrentRank(): array
    {
        $ranks = [
            0 => 'Débutant',
            101 => 'Apprenti',
            301 => 'Résistant',
            601 => 'Guerrier',
            1001 => 'Champion',
            1501 => 'Héros',
            2501 => 'Légende',
            4001 => 'Maître du souffle',
        ];

        $totalScore = $this->getTotalScore();
        $currentRank = 'Débutant';
        $nextRankThreshold = 101;
        $currentThreshold = 0;

        foreach ($ranks as $threshold => $rank) {
            if ($totalScore >= $threshold) {
                $currentRank = $rank;
                $currentThreshold = $threshold;
            } else {
                $nextRankThreshold = $threshold;
                break;
            }
        }

        $progress = 0;
        if ($nextRankThreshold > $currentThreshold) {
            $progress = (($totalScore - $currentThreshold) / ($nextRankThreshold - $currentThreshold)) * 100;
            $progress = min(100, max(0, $progress));
        }

        return [
            'rank' => $currentRank,
            'total_score' => $totalScore,
            'next_rank_threshold' => $nextRankThreshold,
            'progress' => round($progress),
        ];
    }
}
