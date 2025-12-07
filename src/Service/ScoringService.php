<?php

namespace App\Service;

use App\Repository\CigaretteRepository;
use App\Repository\WakeUpRepository;

class ScoringService
{
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
     * Calcule les points pour une différence donnée (en minutes)
     */
    public static function getPointsForDiff(float $diff): int
    {
        return match (true) {
            $diff >= 30 => 10,
            $diff >= 15 => 5,
            $diff >= 5 => 2,
            $diff > 0 => 1,
            $diff == 0 => -1,
            $diff >= -5 => -2,
            $diff >= -15 => -3,
            $diff >= -30 => -5,
            default => -8,
        };
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
     */
    public function getSmoothedAverageInterval(\DateTimeInterface $today): float
    {
        $intervals = [];

        for ($i = 1; $i <= 7; $i++) {
            $date = (clone $today)->modify("-{$i} day");
            $cigs = $this->cigaretteRepository->findByDate($date);

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
     */
    public function getSmoothedFirstCigTime(\DateTimeInterface $today): float
    {
        $times = [];

        for ($i = 1; $i <= 7; $i++) {
            $date = (clone $today)->modify("-{$i} day");
            $cigs = $this->cigaretteRepository->findByDate($date);
            $wakeUp = $this->wakeUpRepository->findByDate($date);

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
     */
    public function hasHistoricalData(\DateTimeInterface $today): bool
    {
        for ($i = 1; $i <= 7; $i++) {
            $date = (clone $today)->modify("-{$i} day");
            $cigs = $this->cigaretteRepository->findByDate($date);
            if (!empty($cigs)) {
                return true;
            }
        }
        return false;
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

        // Calculer la cible avec moyenne lissée sur 7 jours
        $targetMinutes = $this->calculateTargetMinutes($nextIndex, $todayCigs, $todayWakeUp, $date);

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
            $points = self::getPointsForDiff($diff);

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

        return [
            'date' => $date->format('Y-m-d'),
            'total_score' => $totalScore,
            'cigarette_count' => count($todayCigs),
            'yesterday_count' => $yesterdayCount,
            'details' => [
                'comparisons' => $comparisons,
            ],
        ];
    }

    /**
     * Calcule le score total depuis le début
     */
    public function getTotalScore(): int
    {
        $firstDate = $this->cigaretteRepository->getFirstCigaretteDate();
        if (!$firstDate) {
            return 0;
        }

        $total = 0;
        $currentDate = clone $firstDate;
        $today = new \DateTime();
        $today->setTime(23, 59, 59);

        while ($currentDate <= $today) {
            $dailyScore = $this->calculateDailyScore($currentDate);
            $total += $dailyScore['total_score'];
            $currentDate->modify('+1 day');
        }

        return $total;
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
