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

    // Paliers de points (utilisés partout)
    private const TIMING_TIERS = [
        ['min' => 30, 'points' => 10],
        ['min' => 15, 'points' => 5],
        ['min' => 5, 'points' => 2],
        ['min' => 0, 'points' => 1],  // > 0 donne 1pt
    ];

    public function __construct(
        private CigaretteRepository $cigaretteRepository,
        private WakeUpRepository $wakeUpRepository
    ) {}

    /**
     * Calcule les points pour une différence donnée (en minutes)
     * Positif = après la cible (bonus), Négatif = avant la cible (malus)
     */
    public static function getPointsForDiff(float $diff): int
    {
        return match (true) {
            $diff >= 30 => 10,
            $diff >= 15 => 5,
            $diff >= 5 => 2,
            $diff > 0 => 1,
            $diff == 0 => 0,
            $diff >= -5 => -2,
            $diff >= -15 => -5,
            $diff >= -30 => -8,
            default => -10,
        };
    }

    /**
     * Calcule les infos pour la prochaine clope (utilisé par le timer)
     * Retourne : status, wakeup_timestamp, target_minutes, current_minutes, current_points, next_tier
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

        $todayCount = count($todayCigs);
        $nextIndex = $todayCount;

        // Vérifier si on a une clope de référence hier
        if (!isset($yesterdayCigs[$nextIndex])) {
            $yesterdayTotal = count($yesterdayCigs);
            if ($todayCount < $yesterdayTotal) {
                return ['status' => 'ahead', 'message' => 'Tu as moins de clopes qu\'hier !'];
            } elseif ($todayCount == $yesterdayTotal) {
                return ['status' => 'equal', 'message' => 'Tu as égalé hier (' . $yesterdayTotal . ' clopes)'];
            } else {
                return ['status' => 'exceeded', 'message' => 'Tu as dépassé hier (' . $yesterdayTotal . ' clopes)'];
            }
        }

        // Pas de réveil aujourd'hui
        if (!$todayWakeUp) {
            return ['status' => 'no_wakeup', 'message' => 'Enregistre ton heure de réveil'];
        }

        // Calculer la cible
        $targetMinutes = $this->calculateTargetMinutes($nextIndex, $todayCigs, $yesterdayCigs, $todayWakeUp, $yesterdayWakeUp);
        $wakeupTimestamp = $todayWakeUp->getWakeDateTime()->getTimestamp();

        // Calculer ce que serait le diff maintenant (pour debug)
        $nowMinutesSinceWake = (time() - $wakeupTimestamp) / 60;
        $currentDiff = $nowMinutesSinceWake - $targetMinutes;

        return [
            'status' => 'active',
            'wakeup_timestamp' => $wakeupTimestamp,
            'target_minutes' => $targetMinutes,
            'tiers' => self::TIMING_TIERS,
            // Debug
            'debug' => [
                'wakeup_datetime' => $todayWakeUp->getWakeDateTime()->format('Y-m-d H:i:s'),
                'wakeup_time_raw' => $todayWakeUp->getWakeTime()->format('H:i'),
                'now_timestamp' => time(),
                'now_minutes_since_wake' => round($nowMinutesSinceWake, 1),
                'target_minutes' => round($targetMinutes, 1),
                'current_diff' => round($currentDiff, 1),
                'current_points' => self::getPointsForDiff($currentDiff),
            ],
        ];
    }

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

        // 5. Streaks
        $streakScore = $this->calculateStreakBonus($date);
        $details['streak'] = $streakScore;
        $totalScore += $streakScore['score'];


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

            // Calculer le temps cible pour cette clope
            $targetMinutes = $this->calculateTargetMinutes($index, $todayCigs, $yesterdayCigs, $todayWakeUp, $yesterdayWakeUp);
            $todayMinutesSinceWake = $this->getMinutesSinceWakeUp($todayCig->getSmokedAt(), $todayWakeUp);

            // Différence : positif = plus tard que la cible (mieux)
            $diff = $todayMinutesSinceWake - $targetMinutes;
            $cigScore = self::getPointsForDiff($diff);

            $score += $cigScore;
            $comparisons[] = [
                'position' => $index + 1,
                'diff_minutes' => round($diff),
                'score' => $cigScore,
            ];
        }

        $label = match (true) {
            $score > 0 => "Clopes retardées vs cible",
            $score < 0 => "Clopes avancées vs cible",
            default => "Timing conforme",
        };

        return ['score' => $score, 'label' => $label, 'comparisons' => $comparisons];
    }

    /**
     * Calcule le temps cible (en minutes depuis réveil) pour une clope donnée
     * - Première clope : temps depuis réveil d'hier
     * - Suivantes : moyenne entre temps absolu et intervalle
     */
    private function calculateTargetMinutes(int $index, array $todayCigs, array $yesterdayCigs, $todayWakeUp, $yesterdayWakeUp): float
    {
        $yesterdayCig = $yesterdayCigs[$index];

        // Si on a les deux réveils, on peut calculer en temps relatif
        // Sinon, on utilise les temps absolus (heures de la journée)
        $useRelativeTime = ($todayWakeUp && $yesterdayWakeUp);

        $yesterdayMinutesSinceWake = $this->getMinutesSinceWakeUp($yesterdayCig->getSmokedAt(), $yesterdayWakeUp);

        // Première clope : uniquement temps absolu
        if ($index === 0) {
            // Si pas de réveil hier, retourner le temps depuis réveil d'aujourd'hui équivalent
            if (!$useRelativeTime && $todayWakeUp) {
                // Temps absolu d'hier = même heure aujourd'hui
                $yesterdayHour = (int) $yesterdayCig->getSmokedAt()->format('H');
                $yesterdayMin = (int) $yesterdayCig->getSmokedAt()->format('i');
                $todayWakeMinutes = (int) $todayWakeUp->getWakeTime()->format('H') * 60 + (int) $todayWakeUp->getWakeTime()->format('i');
                return ($yesterdayHour * 60 + $yesterdayMin) - $todayWakeMinutes;
            }
            return $yesterdayMinutesSinceWake;
        }

        // Clopes suivantes : moyenne pondérée
        $yesterdayPrevCig = $yesterdayCigs[$index - 1];
        $yesterdayPrevMinutes = $this->getMinutesSinceWakeUp($yesterdayPrevCig->getSmokedAt(), $yesterdayWakeUp);
        $yesterdayInterval = $yesterdayMinutesSinceWake - $yesterdayPrevMinutes;

        $todayPrevCig = $todayCigs[$index - 1];
        $todayPrevMinutes = $this->getMinutesSinceWakeUp($todayPrevCig->getSmokedAt(), $todayWakeUp);

        // Méthode intervalle : clope précédente d'aujourd'hui + intervalle d'hier
        $targetInterval = $todayPrevMinutes + $yesterdayInterval;

        // Si pas de réveil hier, la cible absolue doit aussi être calculée différemment
        $targetAbsolute = $yesterdayMinutesSinceWake;
        if (!$useRelativeTime && $todayWakeUp) {
            $yesterdayHour = (int) $yesterdayCig->getSmokedAt()->format('H');
            $yesterdayMin = (int) $yesterdayCig->getSmokedAt()->format('i');
            $todayWakeMinutes = (int) $todayWakeUp->getWakeTime()->format('H') * 60 + (int) $todayWakeUp->getWakeTime()->format('i');
            $targetAbsolute = ($yesterdayHour * 60 + $yesterdayMin) - $todayWakeMinutes;
        }

        // Moyenne des deux méthodes
        return ($targetAbsolute + $targetInterval) / 2;
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
            && ($details['streak']['score'] ?? 0) > 0;
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
