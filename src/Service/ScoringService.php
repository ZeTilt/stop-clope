<?php

namespace App\Service;

use App\Entity\Cigarette;
use App\Repository\CigaretteRepository;
use App\Repository\WakeUpRepository;

class ScoringService
{
    private const RANKS = [
        0 => 'Débutant',
        101 => 'Apprenti',
        301 => 'Résistant',
        601 => 'Guerrier',
        1001 => 'Champion',
        1501 => 'Héros',
        2501 => 'Légende',
        4001 => 'Maître du souffle',
    ];

    public function __construct(
        private CigaretteRepository $cigaretteRepository,
        private WakeUpRepository $wakeUpRepository
    ) {}

    public function calculateDailyScore(\DateTimeInterface $date): array
    {
        $todayCigs = $this->cigaretteRepository->findByDate($date);
        $yesterday = (clone $date)->modify('-1 day');
        $yesterdayCigs = $this->cigaretteRepository->findByDate($yesterday);

        $todayWakeUp = $this->wakeUpRepository->findByDate($date);
        $yesterdayWakeUp = $this->wakeUpRepository->findByDate($yesterday);

        $details = [];
        $totalScore = 0;

        // 1. Comparaison temporelle (basée sur temps depuis réveil)
        $timeComparisonScore = $this->calculateTimeComparison($todayCigs, $yesterdayCigs, $todayWakeUp, $yesterdayWakeUp);
        $details['time_comparison'] = $timeComparisonScore;
        $totalScore += $timeComparisonScore['score'];

        // 2. Réduction quotidienne
        $reductionScore = $this->calculateDailyReduction(count($todayCigs), count($yesterdayCigs));
        $details['daily_reduction'] = $reductionScore;
        $totalScore += $reductionScore['score'];

        // 3. Première clope (temps depuis réveil)
        $firstCigScore = $this->calculateFirstCigaretteScore($todayCigs, $todayWakeUp);
        $details['first_cigarette'] = $firstCigScore;
        $totalScore += $firstCigScore['score'];

        // 4. Intervalle moyen
        $intervalScore = $this->calculateIntervalScore($todayCigs);
        $details['average_interval'] = $intervalScore;
        $totalScore += $intervalScore['score'];

        // 5. Streaks
        $streakScore = $this->calculateStreakBonus($date);
        $details['streak'] = $streakScore;
        $totalScore += $streakScore['score'];

        // 6. Records
        $recordsScore = $this->calculateRecordsBonus($date, $todayCigs);
        $details['records'] = $recordsScore;
        $totalScore += $recordsScore['score'];

        // 7. Journée parfaite
        $perfectDay = $this->isPerfectDay($details);
        if ($perfectDay) {
            $details['perfect_day'] = ['score' => 50, 'label' => 'Journée parfaite !'];
            $totalScore += 50;
        }

        return [
            'date' => $date->format('Y-m-d'),
            'total_score' => $totalScore,
            'cigarette_count' => count($todayCigs),
            'wake_up' => $todayWakeUp?->getWakeTime()?->format('H:i'),
            'details' => $details,
        ];
    }

    private function calculateTimeComparison(array $todayCigs, array $yesterdayCigs, $todayWakeUp, $yesterdayWakeUp): array
    {
        if (empty($yesterdayCigs)) {
            return ['score' => 0, 'label' => 'Premier jour - pas de comparaison', 'comparisons' => []];
        }

        $score = 0;
        $comparisons = [];

        foreach ($todayCigs as $index => $todayCig) {
            if (!isset($yesterdayCigs[$index])) {
                continue;
            }

            // Calculer le temps depuis le réveil pour chaque cigarette
            $todayMinutesSinceWake = $this->getMinutesSinceWakeUp($todayCig->getSmokedAt(), $todayWakeUp);
            $yesterdayMinutesSinceWake = $this->getMinutesSinceWakeUp($yesterdayCigs[$index]->getSmokedAt(), $yesterdayWakeUp);

            // Différence : positif = plus tard aujourd'hui (mieux)
            $diff = $todayMinutesSinceWake - $yesterdayMinutesSinceWake;

            // Scoring : seul "plus tard" est positif, même moment = -1
            $cigScore = match (true) {
                $diff >= 30 => 10,
                $diff >= 15 => 5,
                $diff >= 5 => 2,
                $diff > 0 => 1,       // Même un peu plus tard = petit bonus
                $diff === 0 => -1,     // Pile au même moment = -1
                $diff >= -5 => -2,
                $diff >= -15 => -5,
                $diff >= -30 => -8,
                default => -10,
            };

            $score += $cigScore;
            $comparisons[] = [
                'position' => $index + 1,
                'diff_minutes' => $diff,
                'score' => $cigScore,
            ];
        }

        $label = match (true) {
            $score > 0 => "Clopes retardées vs hier",
            $score < 0 => "Clopes avancées vs hier",
            default => "Timing identique à hier",
        };

        return ['score' => $score, 'label' => $label, 'comparisons' => $comparisons];
    }

    private function getMinutesSinceWakeUp(\DateTimeInterface $cigTime, $wakeUp): int
    {
        if ($wakeUp === null) {
            // Fallback : utiliser l'heure absolue si pas de réveil enregistré
            return (int) $cigTime->format('H') * 60 + (int) $cigTime->format('i');
        }

        $wakeDateTime = $wakeUp->getWakeDateTime();
        $diff = $cigTime->getTimestamp() - $wakeDateTime->getTimestamp();

        return max(0, (int) ($diff / 60));
    }

    private function calculateDailyReduction(int $todayCount, int $yesterdayCount): array
    {
        if ($yesterdayCount === 0) {
            return ['score' => 0, 'label' => 'Premier jour', 'diff' => 0];
        }

        $diff = $yesterdayCount - $todayCount;

        $score = match (true) {
            $diff >= 3 => 50,
            $diff === 2 => 30,
            $diff === 1 => 15,
            $diff === 0 => -5,      // Même nombre = léger malus (objectif = réduire)
            $diff === -1 => -15,
            $diff === -2 => -25,
            default => -35,
        };

        $label = match (true) {
            $diff > 0 => "{$diff} clope(s) en moins !",
            $diff === 0 => 'Pas de réduction...',
            default => abs($diff) . ' clope(s) en plus...',
        };

        return ['score' => $score, 'label' => $label, 'diff' => $diff];
    }

    private function calculateFirstCigaretteScore(array $todayCigs, $wakeUp): array
    {
        if (empty($todayCigs)) {
            return ['score' => 100, 'label' => 'Aucune clope !', 'time' => null, 'minutes_since_wake' => null];
        }

        $firstCig = $todayCigs[0];
        $minutesSinceWake = $this->getMinutesSinceWakeUp($firstCig->getSmokedAt(), $wakeUp);

        // Scoring basé sur le temps depuis le réveil
        $score = match (true) {
            $minutesSinceWake < 15 => -15,      // Moins de 15 min après réveil
            $minutesSinceWake < 30 => -10,
            $minutesSinceWake < 45 => -5,
            $minutesSinceWake < 60 => 0,        // 1h après réveil = neutre
            $minutesSinceWake < 90 => 10,
            $minutesSinceWake < 120 => 20,      // 2h
            $minutesSinceWake < 180 => 35,      // 3h
            $minutesSinceWake < 240 => 50,      // 4h
            default => 75,                       // 4h+
        };

        $hours = floor($minutesSinceWake / 60);
        $mins = $minutesSinceWake % 60;
        $timeDisplay = $hours > 0 ? "{$hours}h{$mins}min" : "{$mins}min";

        $label = match (true) {
            $minutesSinceWake >= 240 => "1ère clope après {$timeDisplay} - Excellent !",
            $minutesSinceWake >= 120 => "1ère clope après {$timeDisplay} - Bien !",
            $minutesSinceWake >= 60 => "1ère clope après {$timeDisplay}",
            default => "1ère clope après {$timeDisplay} du réveil",
        };

        return [
            'score' => $score,
            'label' => $label,
            'time' => $firstCig->getSmokedAt()->format('H:i'),
            'minutes_since_wake' => $minutesSinceWake,
        ];
    }

    private function calculateIntervalScore(array $todayCigs): array
    {
        if (count($todayCigs) < 2) {
            return ['score' => 0, 'label' => 'Pas assez de données', 'average_minutes' => null];
        }

        $totalMinutes = 0;
        $intervals = 0;

        for ($i = 1; $i < count($todayCigs); $i++) {
            $diff = $todayCigs[$i]->getSmokedAt()->getTimestamp() - $todayCigs[$i - 1]->getSmokedAt()->getTimestamp();
            $totalMinutes += $diff / 60;
            $intervals++;
        }

        $avgMinutes = $totalMinutes / $intervals;

        $score = match (true) {
            $avgMinutes < 30 => -20,
            $avgMinutes < 45 => -10,
            $avgMinutes < 60 => -5,
            $avgMinutes < 90 => 5,
            $avgMinutes < 120 => 15,
            default => 30,
        };

        $hours = floor($avgMinutes / 60);
        $mins = round($avgMinutes % 60);
        $avgDisplay = $hours > 0 ? "{$hours}h{$mins}min" : "{$mins}min";

        return [
            'score' => $score,
            'label' => "Intervalle moyen : {$avgDisplay}",
            'average_minutes' => round($avgMinutes),
        ];
    }

    private function calculateStreakBonus(\DateTimeInterface $date): array
    {
        $streak = 0;
        $currentDate = clone $date;

        for ($i = 0; $i < 31; $i++) {
            $currentDate->modify('-1 day');
            $previousDate = (clone $currentDate)->modify('-1 day');

            $currentCount = $this->cigaretteRepository->countByDate($currentDate);
            $previousCount = $this->cigaretteRepository->countByDate($previousDate);

            // On veut une réduction stricte
            if ($previousCount === 0 || $currentCount >= $previousCount) {
                break;
            }

            $streak++;
        }

        $score = match (true) {
            $streak >= 30 => 200,
            $streak >= 14 => 100,
            $streak >= 7 => 50,
            $streak >= 3 => 20,
            default => 0,
        };

        $label = $streak > 0 ? "Série de {$streak} jour(s) de réduction" : 'Pas de série en cours';

        return ['score' => $score, 'label' => $label, 'days' => $streak];
    }

    private function calculateRecordsBonus(\DateTimeInterface $date, array $todayCigs): array
    {
        $score = 0;
        $records = [];

        $todayCount = count($todayCigs);
        $firstDate = $this->cigaretteRepository->getFirstCigaretteDate();

        // Premier jour : pas de record possible (logique que ce soit le "minimum")
        if (!$firstDate) {
            return ['score' => 0, 'label' => 'Premier jour', 'new_records' => []];
        }

        $today = (clone $date)->setTime(0, 0, 0);
        $firstDateNormalized = (clone $firstDate)->setTime(0, 0, 0);

        // Si c'est le premier jour, pas de comparaison possible
        if ($today->format('Y-m-d') === $firstDateNormalized->format('Y-m-d')) {
            return ['score' => 0, 'label' => 'Premier jour', 'new_records' => []];
        }

        if ($todayCount > 0) {
            $isMinRecord = true;
            $checkDate = clone $firstDate;

            while ($checkDate < $today) {
                $count = $this->cigaretteRepository->countByDate($checkDate);
                if ($count > 0 && $count <= $todayCount) {
                    $isMinRecord = false;
                    break;
                }
                $checkDate->modify('+1 day');
            }

            if ($isMinRecord) {
                $score += 100;
                $records[] = 'Nouveau record : minimum de clopes !';
            }
        }

        $label = !empty($records) ? implode(', ', $records) : 'Pas de nouveau record';

        return ['score' => $score, 'label' => $label, 'new_records' => $records];
    }

    private function isPerfectDay(array $details): bool
    {
        return ($details['time_comparison']['score'] ?? 0) > 0
            && ($details['daily_reduction']['score'] ?? 0) > 0
            && ($details['first_cigarette']['score'] ?? 0) > 0
            && ($details['average_interval']['score'] ?? 0) > 0;
    }

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

    public function getCurrentRank(): array
    {
        $totalScore = $this->getTotalScore();
        $currentRank = 'Débutant';
        $nextRankThreshold = 101;
        $currentThreshold = 0;

        foreach (self::RANKS as $threshold => $rank) {
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
