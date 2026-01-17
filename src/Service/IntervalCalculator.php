<?php

namespace App\Service;

use App\Constants\ScoringConstants;
use App\Repository\CigaretteRepository;
use App\Repository\WakeUpRepository;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

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
        private WakeUpRepository $wakeUpRepository,
        private CacheInterface $scoringCache
    ) {}

    /**
     * Convertit une heure (HH:MM) en minutes depuis minuit
     */
    public function timeToMinutes(\DateTimeInterface $time): int
    {
        return (int) $time->format('H') * 60 + (int) $time->format('i');
    }

    /**
     * Calcule les minutes depuis le réveil pour une heure donnée
     */
    public function minutesSinceWakeUp(\DateTimeInterface $time, \DateTimeInterface $wakeTime): int
    {
        return $this->timeToMinutes($time) - $this->timeToMinutes($wakeTime);
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
        // Invalider aussi le cache Symfony
        $this->scoringCache->delete('smoothed_interval_' . date('Y-m-d'));
        $this->scoringCache->delete('smoothed_first_cig_' . date('Y-m-d'));
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

        $firstMinutes = $this->timeToMinutes($firstCig->getSmokedAt());
        $lastMinutes = $this->timeToMinutes($lastCig->getSmokedAt());

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
            return ScoringConstants::DEFAULT_INTERVAL_MINUTES;
        }

        return array_sum($intervals) / count($intervals);
    }

    /**
     * Calcule l'intervalle cible pour un jour donné (v2.0)
     *
     * Règles:
     * - Moyenne des intervalles RÉELS des X derniers jours (X = min(jours_utilisation, 10))
     * - Si calculé <= cible_veille, alors cible = cible_veille + 1 (progression obligatoire)
     *
     * @param \DateTimeInterface $date La date pour laquelle calculer
     * @return array{target: float, actual: float, days_used: int}
     */
    public function getTargetIntervalInfo(\DateTimeInterface $date): array
    {
        $firstDate = $this->cigaretteRepository->getFirstCigaretteDate();

        if (!$firstDate) {
            return [
                'target' => ScoringConstants::DEFAULT_INTERVAL_MINUTES,
                'actual' => 0,
                'days_used' => 0,
            ];
        }

        // Nombre de jours d'utilisation de l'app
        $daysUsed = (int) $firstDate->diff($date)->days;
        $lookbackDays = min($daysUsed, 10);

        if ($lookbackDays < 1) {
            // Premier jour, pas de cible
            $cigs = $this->cigaretteRepository->findByDate($date);
            $actualInterval = $this->getDayAverageInterval($cigs);
            return [
                'target' => ScoringConstants::DEFAULT_INTERVAL_MINUTES,
                'actual' => $actualInterval,
                'days_used' => 0,
            ];
        }

        // Calculer la moyenne des intervalles RÉELS des X derniers jours
        $intervals = [];
        for ($i = 1; $i <= $lookbackDays; $i++) {
            $prevDate = (clone $date)->modify("-{$i} day");
            $cigs = $this->cigaretteRepository->findByDate($prevDate);
            $dayInterval = $this->getDayAverageInterval($cigs);
            if ($dayInterval > 0) {
                $intervals[] = $dayInterval;
            }
        }

        $calculatedTarget = !empty($intervals)
            ? array_sum($intervals) / count($intervals)
            : ScoringConstants::DEFAULT_INTERVAL_MINUTES;

        // Récupérer la cible de la veille (récursif mais avec limite)
        $yesterday = (clone $date)->modify('-1 day');
        $yesterdayTarget = $this->getYesterdayTarget($yesterday, $firstDate);

        // Règle de progression : si calculé <= veille, alors veille + 1
        if ($calculatedTarget <= $yesterdayTarget) {
            $target = $yesterdayTarget + 1;
        } else {
            $target = $calculatedTarget;
        }

        // Intervalle réel du jour
        $cigs = $this->cigaretteRepository->findByDate($date);
        $actualInterval = $this->getDayAverageInterval($cigs);

        return [
            'target' => round($target, 1),
            'actual' => round($actualInterval, 1),
            'days_used' => $lookbackDays,
        ];
    }

    /**
     * Récupère la cible de la veille (pour la règle de progression)
     */
    private function getYesterdayTarget(\DateTimeInterface $date, \DateTimeInterface $firstDate): float
    {
        // Si on est au premier jour ou avant, retourner la valeur par défaut
        if ($date <= $firstDate) {
            return ScoringConstants::DEFAULT_INTERVAL_MINUTES;
        }

        $daysUsed = (int) $firstDate->diff($date)->days;
        $lookbackDays = min($daysUsed, 10);

        if ($lookbackDays < 1) {
            return ScoringConstants::DEFAULT_INTERVAL_MINUTES;
        }

        // Calculer la moyenne des intervalles RÉELS des X derniers jours pour cette date
        $intervals = [];
        for ($i = 1; $i <= $lookbackDays; $i++) {
            $prevDate = (clone $date)->modify("-{$i} day");
            $cigs = $this->cigaretteRepository->findByDate($prevDate);
            $dayInterval = $this->getDayAverageInterval($cigs);
            if ($dayInterval > 0) {
                $intervals[] = $dayInterval;
            }
        }

        $calculatedTarget = !empty($intervals)
            ? array_sum($intervals) / count($intervals)
            : ScoringConstants::DEFAULT_INTERVAL_MINUTES;

        // Pour la veille de la veille (éviter récursion infinie, on s'arrête à J-2)
        $twoDaysAgo = (clone $date)->modify('-1 day');
        if ($twoDaysAgo <= $firstDate) {
            return max($calculatedTarget, ScoringConstants::DEFAULT_INTERVAL_MINUTES);
        }

        // Comparer avec J-2 (simplification pour éviter récursion profonde)
        $twoDaysAgoInfo = $this->getSimpleTargetForDate($twoDaysAgo, $firstDate);

        if ($calculatedTarget <= $twoDaysAgoInfo) {
            return $twoDaysAgoInfo + 1;
        }

        return $calculatedTarget;
    }

    /**
     * Calcul simplifié de la cible (sans récursion)
     */
    private function getSimpleTargetForDate(\DateTimeInterface $date, \DateTimeInterface $firstDate): float
    {
        $daysUsed = (int) $firstDate->diff($date)->days;
        $lookbackDays = min($daysUsed, 10);

        if ($lookbackDays < 1) {
            return ScoringConstants::DEFAULT_INTERVAL_MINUTES;
        }

        $intervals = [];
        for ($i = 1; $i <= $lookbackDays; $i++) {
            $prevDate = (clone $date)->modify("-{$i} day");
            $cigs = $this->cigaretteRepository->findByDate($prevDate);
            $dayInterval = $this->getDayAverageInterval($cigs);
            if ($dayInterval > 0) {
                $intervals[] = $dayInterval;
            }
        }

        return !empty($intervals)
            ? array_sum($intervals) / count($intervals)
            : ScoringConstants::DEFAULT_INTERVAL_MINUTES;
    }

    /**
     * Calcule le temps cible pour la 1ère clope (v2.0)
     *
     * Règles:
     * - Moyenne des temps réels (réveil → 1ère clope) des X derniers jours (X = min(jours, 10))
     * - Si calculé <= cible_veille, alors cible = cible_veille + 1 (progression obligatoire)
     *
     * @param \DateTimeInterface $date La date pour laquelle calculer
     * @return array{target: float, actual: float|null, days_used: int}
     */
    public function getFirstCigTargetInfo(\DateTimeInterface $date): array
    {
        $firstDate = $this->cigaretteRepository->getFirstCigaretteDate();

        if (!$firstDate) {
            return [
                'target' => ScoringConstants::DEFAULT_FIRST_CIG_MINUTES,
                'actual' => null,
                'days_used' => 0,
            ];
        }

        // Temps réel du jour
        $cigs = $this->cigaretteRepository->findByDate($date);
        $wakeUp = $this->wakeUpRepository->findByDate($date);
        $actualTime = null;
        if (!empty($cigs) && $wakeUp) {
            $actualTime = $this->minutesSinceWakeUp($cigs[0]->getSmokedAt(), $wakeUp->getWakeTime());
        }

        // Nombre de jours d'utilisation de l'app
        $daysUsed = (int) $firstDate->diff($date)->days;
        $lookbackDays = min($daysUsed, 10);

        if ($lookbackDays < 1) {
            return [
                'target' => ScoringConstants::DEFAULT_FIRST_CIG_MINUTES,
                'actual' => $actualTime,
                'days_used' => 0,
            ];
        }

        // Calculer la moyenne des temps RÉELS des X derniers jours
        $times = [];
        for ($i = 1; $i <= $lookbackDays; $i++) {
            $prevDate = (clone $date)->modify("-{$i} day");
            $prevCigs = $this->cigaretteRepository->findByDate($prevDate);
            $prevWakeUp = $this->wakeUpRepository->findByDate($prevDate);

            if (!empty($prevCigs) && $prevWakeUp) {
                $time = $this->minutesSinceWakeUp($prevCigs[0]->getSmokedAt(), $prevWakeUp->getWakeTime());
                if ($time > 0) {
                    $times[] = $time;
                }
            }
        }

        $calculatedTarget = !empty($times)
            ? array_sum($times) / count($times)
            : ScoringConstants::DEFAULT_FIRST_CIG_MINUTES;

        // Récupérer la cible de la veille
        $yesterday = (clone $date)->modify('-1 day');
        $yesterdayTarget = $this->getYesterdayFirstCigTarget($yesterday, $firstDate);

        // Règle de progression : si calculé <= veille, alors veille + 1
        if ($calculatedTarget <= $yesterdayTarget) {
            $target = $yesterdayTarget + 1;
        } else {
            $target = $calculatedTarget;
        }

        return [
            'target' => round($target, 1),
            'actual' => $actualTime !== null ? round($actualTime, 1) : null,
            'days_used' => $lookbackDays,
        ];
    }

    /**
     * Récupère la cible 1ère clope de la veille (pour la règle de progression)
     */
    private function getYesterdayFirstCigTarget(\DateTimeInterface $date, \DateTimeInterface $firstDate): float
    {
        if ($date <= $firstDate) {
            return ScoringConstants::DEFAULT_FIRST_CIG_MINUTES;
        }

        $daysUsed = (int) $firstDate->diff($date)->days;
        $lookbackDays = min($daysUsed, 10);

        if ($lookbackDays < 1) {
            return ScoringConstants::DEFAULT_FIRST_CIG_MINUTES;
        }

        $times = [];
        for ($i = 1; $i <= $lookbackDays; $i++) {
            $prevDate = (clone $date)->modify("-{$i} day");
            $prevCigs = $this->cigaretteRepository->findByDate($prevDate);
            $prevWakeUp = $this->wakeUpRepository->findByDate($prevDate);

            if (!empty($prevCigs) && $prevWakeUp) {
                $time = $this->minutesSinceWakeUp($prevCigs[0]->getSmokedAt(), $prevWakeUp->getWakeTime());
                if ($time > 0) {
                    $times[] = $time;
                }
            }
        }

        $calculatedTarget = !empty($times)
            ? array_sum($times) / count($times)
            : ScoringConstants::DEFAULT_FIRST_CIG_MINUTES;

        // Simplification : comparer avec J-2 sans récursion profonde
        $twoDaysAgo = (clone $date)->modify('-1 day');
        if ($twoDaysAgo <= $firstDate) {
            return max($calculatedTarget, ScoringConstants::DEFAULT_FIRST_CIG_MINUTES);
        }

        $twoDaysAgoTarget = $this->getSimpleFirstCigTargetForDate($twoDaysAgo, $firstDate);

        if ($calculatedTarget <= $twoDaysAgoTarget) {
            return $twoDaysAgoTarget + 1;
        }

        return $calculatedTarget;
    }

    /**
     * Calcul simplifié de la cible 1ère clope (sans récursion)
     */
    private function getSimpleFirstCigTargetForDate(\DateTimeInterface $date, \DateTimeInterface $firstDate): float
    {
        $daysUsed = (int) $firstDate->diff($date)->days;
        $lookbackDays = min($daysUsed, 10);

        if ($lookbackDays < 1) {
            return ScoringConstants::DEFAULT_FIRST_CIG_MINUTES;
        }

        $times = [];
        for ($i = 1; $i <= $lookbackDays; $i++) {
            $prevDate = (clone $date)->modify("-{$i} day");
            $prevCigs = $this->cigaretteRepository->findByDate($prevDate);
            $prevWakeUp = $this->wakeUpRepository->findByDate($prevDate);

            if (!empty($prevCigs) && $prevWakeUp) {
                $time = $this->minutesSinceWakeUp($prevCigs[0]->getSmokedAt(), $prevWakeUp->getWakeTime());
                if ($time > 0) {
                    $times[] = $time;
                }
            }
        }

        return !empty($times)
            ? array_sum($times) / count($times)
            : ScoringConstants::DEFAULT_FIRST_CIG_MINUTES;
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
                $times[] = $this->minutesSinceWakeUp($cigs[0]->getSmokedAt(), $wakeUp->getWakeTime());
            }
        }

        if (empty($times)) {
            return ScoringConstants::DEFAULT_FIRST_CIG_MINUTES;
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
        $todayPrevMinutes = $this->minutesSinceWakeUp($todayPrevCig->getSmokedAt(), $todayWakeUp->getWakeTime());

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
    public function getPointsForDiff(float $diff, float $interval): int
    {
        if ($interval <= 0) {
            $interval = ScoringConstants::DEFAULT_INTERVAL_MINUTES;
        }

        // Ratio : combien de fois l'intervalle on a attendu en plus/moins
        $ratio = $diff / $interval;

        if ($diff > 0.001) {
            // Positif : points par intervalle attendu en plus, minimum 1 pt
            $points = (int) round($ratio * ScoringConstants::POINTS_PER_INTERVAL);
            return max(ScoringConstants::MIN_POSITIVE_POINTS, $points);
        } elseif (abs($diff) < 0.001) {
            // Fix: comparaison float avec tolérance (pile à l'heure = léger malus)
            return ScoringConstants::POINTS_NEUTRAL;
        } else {
            // Négatif : malus proportionnel, plafonné
            $points = (int) round($ratio * ScoringConstants::POINTS_PER_INTERVAL);
            return max(ScoringConstants::MAX_MALUS_POINTS, $points);
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
            return ScoringConstants::DEFAULT_INTERVAL_MINUTES;
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
                $times[] = $this->minutesSinceWakeUp($prevCigs[0]->getSmokedAt(), $prevWakeUp->getWakeTime());
            }
        }

        if (empty($times)) {
            return ScoringConstants::DEFAULT_FIRST_CIG_MINUTES;
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
                    $prevMinutes = $this->minutesSinceWakeUp(
                        $prevCig->getSmokedAt(),
                        $todayWakeUp->getWakeTime()
                    );
                } else {
                    $prevMinutes = $this->timeToMinutes($prevCig->getSmokedAt());
                }
                $targetMinutes = $prevMinutes + $avgInterval;
            }

            if ($todayWakeUp) {
                $actualMinutes = $this->minutesSinceWakeUp(
                    $todayCig->getSmokedAt(),
                    $todayWakeUp->getWakeTime()
                );
            } else {
                $actualMinutes = $this->timeToMinutes($todayCig->getSmokedAt());
            }

            $diff = $actualMinutes - $targetMinutes;
            $points = $this->getPointsForDiff($diff, $avgInterval);
            $totalScore += $points;
        }

        return $totalScore;
    }
}
