<?php

namespace App\Service;

use App\Repository\CigaretteRepository;
use App\Repository\DailyScoreRepository;
use App\Repository\WakeUpRepository;

/**
 * Service dédié à la gestion des streaks (jours consécutifs positifs)
 * Extrait de ScoringService pour une meilleure maintenabilité
 *
 * v2.0: Ajoute bonus de séquence et préservation par maintenance/bouclier
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

    /**
     * Bonus de multiplicateur basé sur la séquence v2.0
     * Paliers: 3 jours +5%, 7 jours +10%, 14+ jours +15%
     */
    private const STREAK_BONUS_TIERS = [
        3 => 0.05,   // 3+ jours: +5%
        7 => 0.10,   // 7+ jours: +10%
        14 => 0.15,  // 14+ jours: +15%
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

    /**
     * Calcule le bonus de multiplicateur basé sur la séquence v2.0
     *
     * @param int $streakDays Nombre de jours consécutifs
     * @return float Bonus (0.0, 0.05, 0.10 ou 0.15)
     */
    public function getStreakBonus(int $streakDays): float
    {
        $bonus = 0.0;

        foreach (self::STREAK_BONUS_TIERS as $days => $tierBonus) {
            if ($streakDays >= $days) {
                $bonus = $tierBonus;
            }
        }

        return $bonus;
    }

    /**
     * Récupère les infos complètes de séquence pour l'affichage v2.0
     */
    public function getStreakInfo(): array
    {
        $streak = $this->getStreakOptimized();
        $bonus = $this->getStreakBonus($streak['current']);
        $nextMilestone = $this->getNextMilestone($streak['current']);

        return [
            'current' => $streak['current'],
            'best' => $streak['best'],
            'today_positive' => $streak['today_positive'],
            'bonus_multiplier' => $bonus,
            'bonus_percentage' => (int) ($bonus * 100),
            'next_milestone' => $nextMilestone,
            'next_bonus_tier' => $this->getNextBonusTier($streak['current']),
        ];
    }

    /**
     * Retourne le prochain palier de bonus de séquence
     */
    public function getNextBonusTier(int $currentStreak): ?array
    {
        foreach (self::STREAK_BONUS_TIERS as $days => $bonus) {
            if ($days > $currentStreak) {
                return [
                    'days_needed' => $days,
                    'days_remaining' => $days - $currentStreak,
                    'bonus' => $bonus,
                    'bonus_percentage' => (int) ($bonus * 100),
                ];
            }
        }
        return null; // Max tier atteint
    }

    /**
     * Détermine si un jour devrait préserver la séquence malgré un score négatif
     *
     * @param \DateTimeInterface $date La date à vérifier
     * @return bool True si la séquence est protégée
     */
    public function isStreakProtected(\DateTimeInterface $date): bool
    {
        // Récupérer le DailyScore pour vérifier maintenance et bouclier
        $dailyScore = $this->dailyScoreRepository->findByDate($date);

        if (!$dailyScore) {
            return false;
        }

        // Jour de maintenance = séquence préservée
        if ($dailyScore->isMaintenanceDay()) {
            return true;
        }

        // Si un bouclier a été utilisé (multiplierApplied différent = protection)
        // Note: La logique bouclier sera implémentée dans Epic 4
        return false;
    }
}
