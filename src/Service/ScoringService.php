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
     * Calcule l'intervalle moyen entre les clopes d'hier (en minutes)
     */
    public function getYesterdayAverageInterval(array $yesterdayCigs, $yesterdayWakeUp): float
    {
        if (count($yesterdayCigs) < 2) {
            // Si une seule clope hier, retourner le temps depuis réveil de cette clope
            if (count($yesterdayCigs) === 1 && $yesterdayWakeUp) {
                return self::minutesSinceWakeUp($yesterdayCigs[0]->getSmokedAt(), $yesterdayWakeUp->getWakeTime());
            }
            return 60; // Défaut : 1 heure
        }

        $firstCig = $yesterdayCigs[0];
        $lastCig = $yesterdayCigs[count($yesterdayCigs) - 1];

        $firstMinutes = self::timeToMinutes($firstCig->getSmokedAt());
        $lastMinutes = self::timeToMinutes($lastCig->getSmokedAt());

        return ($lastMinutes - $firstMinutes) / (count($yesterdayCigs) - 1);
    }

    /**
     * Calcule la cible (en minutes depuis réveil) pour la prochaine clope
     * Basé uniquement sur la dernière clope d'aujourd'hui + intervalle d'hier
     */
    public function calculateTargetMinutes(int $index, array $todayCigs, array $yesterdayCigs, $todayWakeUp, $yesterdayWakeUp): float
    {
        if (!$todayWakeUp) {
            return 0;
        }

        $avgInterval = $this->getYesterdayAverageInterval($yesterdayCigs, $yesterdayWakeUp);

        // Première clope : temps depuis réveil de la 1ère clope d'hier
        if ($index === 0) {
            if (isset($yesterdayCigs[0]) && $yesterdayWakeUp) {
                return self::minutesSinceWakeUp($yesterdayCigs[0]->getSmokedAt(), $yesterdayWakeUp->getWakeTime());
            }
            return $avgInterval;
        }

        // Clopes suivantes : dernière clope d'aujourd'hui + intervalle d'hier
        $todayPrevCig = $todayCigs[$index - 1];
        $todayPrevMinutes = self::minutesSinceWakeUp($todayPrevCig->getSmokedAt(), $todayWakeUp->getWakeTime());

        // Si on a une référence hier pour cet intervalle
        if (isset($yesterdayCigs[$index]) && isset($yesterdayCigs[$index - 1])) {
            $yesterdayInterval = self::timeToMinutes($yesterdayCigs[$index]->getSmokedAt())
                               - self::timeToMinutes($yesterdayCigs[$index - 1]->getSmokedAt());
            return $todayPrevMinutes + $yesterdayInterval;
        }

        // Sinon (on dépasse hier) : utiliser l'intervalle moyen
        return $todayPrevMinutes + $avgInterval;
    }

    /**
     * Calcule les infos pour la prochaine clope (utilisé par le timer)
     */
    public function getNextCigaretteInfo(\DateTimeInterface $date): array
    {
        $todayCigs = $this->cigaretteRepository->findByDate($date);
        $yesterday = (clone $date)->modify('-1 day');
        $yesterdayCigs = $this->cigaretteRepository->findByDate($yesterday);
        $todayWakeUp = $this->wakeUpRepository->findByDate($date);
        $yesterdayWakeUp = $this->wakeUpRepository->findByDate($yesterday);

        // Premier jour : pas de comparaison
        if (empty($yesterdayCigs)) {
            return ['status' => 'first_day', 'message' => 'Premier jour - pas de comparaison'];
        }

        // Pas de réveil aujourd'hui
        if (!$todayWakeUp) {
            return ['status' => 'no_wakeup', 'message' => 'Enregistre ton heure de réveil'];
        }

        $nextIndex = count($todayCigs);
        $yesterdayTotal = count($yesterdayCigs);
        $wakeUpMinutes = self::timeToMinutes($todayWakeUp->getWakeTime());

        // Calculer la cible (fonctionne aussi si on dépasse hier)
        $targetMinutes = $this->calculateTargetMinutes($nextIndex, $todayCigs, $yesterdayCigs, $todayWakeUp, $yesterdayWakeUp);

        // Déterminer le statut
        $status = 'active';
        $exceeded = false;
        if ($nextIndex >= $yesterdayTotal) {
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
        $yesterday = (clone $date)->modify('-1 day');
        $yesterdayCigs = $this->cigaretteRepository->findByDate($yesterday);
        $todayWakeUp = $this->wakeUpRepository->findByDate($date);
        $yesterdayWakeUp = $this->wakeUpRepository->findByDate($yesterday);

        // Premier jour : pas de comparaison
        if (empty($yesterdayCigs)) {
            return [
                'date' => $date->format('Y-m-d'),
                'total_score' => 0,
                'cigarette_count' => count($todayCigs),
                'details' => ['message' => 'Premier jour - pas de comparaison'],
            ];
        }

        $totalScore = 0;
        $comparisons = [];
        $yesterdayCount = count($yesterdayCigs);

        foreach ($todayCigs as $index => $todayCig) {
            // Calculer la cible (fonctionne aussi si on dépasse hier)
            $targetMinutes = $this->calculateTargetMinutes($index, $todayCigs, $yesterdayCigs, $todayWakeUp, $yesterdayWakeUp);

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
                'exceeded' => $index >= $yesterdayCount,
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
