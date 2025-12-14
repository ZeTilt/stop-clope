<?php

namespace App\Service;

use App\Repository\CigaretteRepository;
use App\Repository\WakeUpRepository;

/**
 * Service dédié aux calculs d'intervalles entre cigarettes
 * Extrait de ScoringService pour une meilleure maintenabilité
 */
class IntervalCalculator
{
    private ?array $cachedHistoricalData = null;
    private ?string $cacheDate = null;

    public function __construct(
        private CigaretteRepository $cigaretteRepository,
        private WakeUpRepository $wakeUpRepository
    ) {}

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
     * Charge les données historiques des 7 derniers jours en 2 requêtes
     * @return array ['cigarettes' => [...], 'wakeups' => [...]]
     */
    public function loadHistoricalData(\DateTimeInterface $today): array
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
     * Vérifie si on a des données historiques (au moins 1 jour dans les 7 derniers)
     */
    public function hasHistoricalData(\DateTimeInterface $today): bool
    {
        $historical = $this->loadHistoricalData($today);
        return !empty($historical['cigarettes']);
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
     * Calcule l'intervalle moyen d'un jour à partir des données pré-chargées
     */
    public function calculateDayAverageIntervalFromData(string $dateStr, array $allCigarettes): float
    {
        $cigs = $allCigarettes[$dateStr] ?? [];
        return $this->getDayAverageInterval($cigs);
    }

    /**
     * Calcule l'intervalle moyen lissé sur 7 jours à partir des données pré-chargées
     */
    public function calculateSmoothedIntervalFromData(\DateTimeInterface $date, array $allCigarettes): float
    {
        $intervals = [];
        for ($i = 1; $i <= 7; $i++) {
            $prevDateStr = (clone $date)->modify("-{$i} day")->format('Y-m-d');
            $prevCigs = $allCigarettes[$prevDateStr] ?? [];

            $dayInterval = $this->getDayAverageInterval($prevCigs);
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
     * Calcule le temps moyen de la 1ère clope sur 7 jours à partir des données pré-chargées
     */
    public function calculateSmoothedFirstCigTimeFromData(
        \DateTimeInterface $date,
        array $allCigarettes,
        array $allWakeups
    ): float {
        $times = [];
        for ($i = 1; $i <= 7; $i++) {
            $prevDateStr = (clone $date)->modify("-{$i} day")->format('Y-m-d');
            $prevCigs = $allCigarettes[$prevDateStr] ?? [];
            $prevWakeUp = $allWakeups[$prevDateStr] ?? null;

            if (!empty($prevCigs) && $prevWakeUp) {
                $times[] = self::minutesSinceWakeUp($prevCigs[0]->getSmokedAt(), $prevWakeUp->getWakeTime());
            }
        }

        if (empty($times)) {
            return 30; // Défaut : 30 min après réveil
        }

        return array_sum($times) / count($times);
    }

    /**
     * Calcule le score d'un jour à partir des données pré-chargées
     * Version optimisée sans requêtes supplémentaires
     * Méthode centralisée pour éviter la duplication (utilisée par ScoringService et StreakService)
     */
    public function calculateDailyScoreFromData(
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
        $avgInterval = $this->calculateSmoothedIntervalFromData($date, $allCigarettes);

        // Pas de données historiques = premier jour (vérifier si au moins 1 jour avec données)
        $hasHistory = false;
        for ($i = 1; $i <= 7; $i++) {
            $prevDateStr = (clone $date)->modify("-{$i} day")->format('Y-m-d');
            if (!empty($allCigarettes[$prevDateStr] ?? [])) {
                $hasHistory = true;
                break;
            }
        }
        if (!$hasHistory) {
            return 0;
        }

        // Calculer le temps moyen de la 1ère clope
        $avgFirstCigTime = $this->calculateSmoothedFirstCigTimeFromData(
            $date,
            $allCigarettes,
            $allWakeups
        );

        $totalScore = 0;

        foreach ($todayCigs as $index => $todayCig) {
            // Calculer la cible
            if ($index === 0) {
                $targetMinutes = $avgFirstCigTime;
            } else {
                $prevCig = $todayCigs[$index - 1];
                if ($todayWakeUp) {
                    $prevMinutes = self::minutesSinceWakeUp(
                        $prevCig->getSmokedAt(),
                        $todayWakeUp->getWakeTime()
                    );
                } else {
                    $prevMinutes = self::timeToMinutes($prevCig->getSmokedAt());
                }
                $targetMinutes = $prevMinutes + $avgInterval;
            }

            if ($todayWakeUp) {
                $actualMinutes = self::minutesSinceWakeUp(
                    $todayCig->getSmokedAt(),
                    $todayWakeUp->getWakeTime()
                );
            } else {
                $actualMinutes = self::timeToMinutes($todayCig->getSmokedAt());
            }

            $diff = $actualMinutes - $targetMinutes;
            $points = self::getPointsForDiff($diff, $avgInterval);
            $totalScore += $points;
        }

        return $totalScore;
    }
}
