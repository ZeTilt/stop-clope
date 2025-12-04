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
     * Calcule la cible (en minutes depuis réveil) pour la clope d'index donné
     */
    public function calculateTargetMinutes(int $index, array $todayCigs, array $yesterdayCigs, $todayWakeUp, $yesterdayWakeUp): float
    {
        if (!isset($yesterdayCigs[$index]) || !$todayWakeUp) {
            return 0;
        }

        $yesterdayCig = $yesterdayCigs[$index];
        $avgInterval = $this->getYesterdayAverageInterval($yesterdayCigs, $yesterdayWakeUp);

        // Méthode absolue : même temps depuis réveil qu'hier
        if ($yesterdayWakeUp) {
            $targetAbsolute = self::minutesSinceWakeUp($yesterdayCig->getSmokedAt(), $yesterdayWakeUp->getWakeTime());
        } else {
            $targetAbsolute = self::timeToMinutes($yesterdayCig->getSmokedAt());
        }

        // Première clope : moyenne entre temps absolu et intervalle moyen
        if ($index === 0) {
            return ($targetAbsolute + $avgInterval) / 2;
        }

        // Clopes suivantes : moyenne entre temps absolu et méthode intervalle
        $yesterdayPrevCig = $yesterdayCigs[$index - 1];
        $yesterdayInterval = self::timeToMinutes($yesterdayCig->getSmokedAt()) - self::timeToMinutes($yesterdayPrevCig->getSmokedAt());

        $todayPrevCig = $todayCigs[$index - 1];
        $todayPrevMinutes = self::minutesSinceWakeUp($todayPrevCig->getSmokedAt(), $todayWakeUp->getWakeTime());

        $targetInterval = $todayPrevMinutes + $yesterdayInterval;

        return ($targetAbsolute + $targetInterval) / 2;
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

        // Vérifier si on a une clope de référence hier
        if (!isset($yesterdayCigs[$nextIndex])) {
            $yesterdayTotal = count($yesterdayCigs);
            if (count($todayCigs) < $yesterdayTotal) {
                return ['status' => 'ahead', 'message' => 'Tu as moins de clopes qu\'hier !'];
            } elseif (count($todayCigs) == $yesterdayTotal) {
                return ['status' => 'equal', 'message' => 'Tu as égalé hier (' . $yesterdayTotal . ' clopes)'];
            } else {
                return ['status' => 'exceeded', 'message' => 'Tu as dépassé hier (' . $yesterdayTotal . ' clopes)'];
            }
        }

        // Calculer la cible en minutes depuis réveil
        $targetMinutes = $this->calculateTargetMinutes($nextIndex, $todayCigs, $yesterdayCigs, $todayWakeUp, $yesterdayWakeUp);
        $wakeUpMinutes = self::timeToMinutes($todayWakeUp->getWakeTime());

        return [
            'status' => 'active',
            'wake_time' => $todayWakeUp->getWakeTime()->format('H:i'),
            'wake_minutes' => $wakeUpMinutes,
            'target_minutes' => round($targetMinutes, 1),
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

        foreach ($todayCigs as $index => $todayCig) {
            if (!isset($yesterdayCigs[$index])) {
                continue;
            }

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
            ];
        }

        return [
            'date' => $date->format('Y-m-d'),
            'total_score' => $totalScore,
            'cigarette_count' => count($todayCigs),
            'yesterday_count' => count($yesterdayCigs),
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
