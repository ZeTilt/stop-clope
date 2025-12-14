<?php

namespace App\Service;

use App\Repository\CigaretteRepository;
use App\Repository\DailyScoreRepository;
use App\Repository\WakeUpRepository;

/**
 * Service dédié à la gestion des streaks (jours consécutifs positifs)
 * Extrait de ScoringService pour une meilleure maintenabilité
 */
class StreakService
{
    /**
     * Milestones de streak à célébrer
     */
    private const MILESTONES = [
        3 => ['emoji' => '🌟', 'message' => '3 jours de suite !'],
        7 => ['emoji' => '🔥', 'message' => 'Une semaine complète !'],
        14 => ['emoji' => '💪', 'message' => '2 semaines de suite !'],
        21 => ['emoji' => '🏅', 'message' => '3 semaines !'],
        30 => ['emoji' => '🏆', 'message' => 'Un mois entier !'],
        60 => ['emoji' => '⭐', 'message' => '2 mois de streak !'],
        90 => ['emoji' => '👑', 'message' => '3 mois légendaires !'],
        180 => ['emoji' => '🎖️', 'message' => '6 mois incroyables !'],
        365 => ['emoji' => '🏅', 'message' => 'Une année complète !'],
    ];

    public function __construct(
        private CigaretteRepository $cigaretteRepository,
        private WakeUpRepository $wakeUpRepository,
        private DailyScoreRepository $dailyScoreRepository,
        private IntervalCalculator $intervalCalculator
    ) {}

    /**
     * Récupère le streak depuis les DailyScore pré-calculés (O(1))
     * Calcule today_positive en temps réel pour l'UI
     */
    public function getStreakOptimized(): array
    {
        // Récupérer le score du jour d'aujourd'hui s'il existe
        $todayScore = $this->dailyScoreRepository->findByDate(new \DateTime());
        $todayPositive = $todayScore && $todayScore->getScore() > 0;

        return [
            'current' => $this->dailyScoreRepository->getCurrentStreak(),
            'best' => $this->dailyScoreRepository->getBestStreak(),
            'today_positive' => $todayPositive,
        ];
    }

    /**
     * Calcule le streak actuel (jours consécutifs avec score positif)
     * Version complète qui recalcule tout
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
            $dailyScore = $this->intervalCalculator->calculateDailyScoreFromData($currentDate, $allCigarettes, $allWakeups);

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
     * Vérifie si un milestone de streak vient d'être atteint
     * @return array|null Infos sur le milestone ou null si aucun
     */
    public function checkMilestone(int $currentStreak, int $previousStreak): ?array
    {
        foreach (self::MILESTONES as $days => $info) {
            // Le milestone est atteint si on vient de passer ce nombre de jours
            if ($currentStreak >= $days && $previousStreak < $days) {
                return [
                    'days' => $days,
                    'emoji' => $info['emoji'],
                    'message' => $info['message'],
                ];
            }
        }
        return null;
    }

    /**
     * Retourne le prochain milestone à atteindre
     */
    public function getNextMilestone(int $currentStreak): ?array
    {
        foreach (self::MILESTONES as $days => $info) {
            if ($days > $currentStreak) {
                return [
                    'days' => $days,
                    'days_remaining' => $days - $currentStreak,
                    'emoji' => $info['emoji'],
                    'message' => $info['message'],
                ];
            }
        }
        return null; // Tous les milestones atteints !
    }

    /**
     * Retourne tous les milestones avec leur statut
     */
    public function getAllMilestones(int $currentStreak): array
    {
        $result = [];
        foreach (self::MILESTONES as $days => $info) {
            $result[] = [
                'days' => $days,
                'emoji' => $info['emoji'],
                'message' => $info['message'],
                'achieved' => $currentStreak >= $days,
            ];
        }
        return $result;
    }

}
