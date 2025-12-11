<?php

namespace App\Service;

use App\Repository\CigaretteRepository;
use App\Repository\SettingsRepository;

class GoalService
{
    public function __construct(
        private CigaretteRepository $cigaretteRepository,
        private SettingsRepository $settingsRepository
    ) {}

    /**
     * Calcule l'objectif suggéré basé sur la consommation actuelle
     * Prend la moyenne des 7 derniers jours - 2
     */
    public function getSuggestedGoal(): ?int
    {
        $stats = $this->cigaretteRepository->getDailyStats(7);
        if (empty($stats)) {
            return null;
        }

        $avg = array_sum($stats) / count($stats);
        return max(1, (int) round($avg) - 2);
    }

    /**
     * Récupère l'objectif actuel de l'utilisateur
     */
    public function getCurrentGoal(): ?int
    {
        $goal = $this->settingsRepository->get('daily_goal');
        return $goal !== null ? (int) $goal : null;
    }

    /**
     * Calcule le palier actuel et le prochain palier
     * Basé sur la consommation initiale et la progression par semaine
     *
     * @return array{current_tier: int, next_tier: int|null, weeks_active: int, initial: int, reduction_per_week: int}
     */
    public function getTierInfo(): array
    {
        $initialDailyCigs = (int) $this->settingsRepository->get('initial_daily_cigs', '20');
        $firstDate = $this->cigaretteRepository->getFirstCigaretteDate();

        if (!$firstDate) {
            return [
                'current_tier' => $initialDailyCigs,
                'next_tier' => max(0, $initialDailyCigs - 1),
                'weeks_active' => 0,
                'initial' => $initialDailyCigs,
                'reduction_per_week' => 1,
            ];
        }

        $daysSinceStart = max(1, (new \DateTime())->diff($firstDate)->days);
        $weeksActive = (int) floor($daysSinceStart / 7);

        // Réduction de 1 clope par semaine
        $reductionPerWeek = 1;
        $currentTier = max(0, $initialDailyCigs - ($weeksActive * $reductionPerWeek));
        $nextTier = $currentTier > 0 ? $currentTier - 1 : null;

        return [
            'current_tier' => $currentTier,
            'next_tier' => $nextTier,
            'weeks_active' => $weeksActive,
            'initial' => $initialDailyCigs,
            'reduction_per_week' => $reductionPerWeek,
            'days_until_next_tier' => 7 - ($daysSinceStart % 7),
        ];
    }

    /**
     * Vérifie si l'utilisateur a atteint un nouveau palier aujourd'hui
     * @return array{achieved: bool, new_tier: int|null, message: string|null}
     */
    public function checkTierAchievement(): array
    {
        $tierInfo = $this->getTierInfo();
        $currentGoal = $this->getCurrentGoal();

        // Si l'utilisateur n'a pas de goal personnalisé, utiliser le palier automatique
        $targetTier = $currentGoal ?? $tierInfo['current_tier'];

        // Vérifier la moyenne des 7 derniers jours
        $stats = $this->cigaretteRepository->getDailyStats(7);
        if (empty($stats)) {
            return ['achieved' => false, 'new_tier' => null, 'message' => null];
        }

        $weeklyAvg = array_sum($stats) / count($stats);

        // Si la moyenne est en dessous du palier actuel
        if ($weeklyAvg <= $targetTier) {
            // Vérifier si on peut passer au palier suivant
            $nextTier = $targetTier - 1;
            if ($nextTier >= 0 && $weeklyAvg <= $nextTier) {
                return [
                    'achieved' => true,
                    'new_tier' => $nextTier,
                    'message' => $this->getTierAchievementMessage($nextTier),
                ];
            }
        }

        return ['achieved' => false, 'new_tier' => null, 'message' => null];
    }

    /**
     * Génère un message de célébration pour un palier atteint
     */
    private function getTierAchievementMessage(int $tier): string
    {
        if ($tier === 0) {
            return "Incroyable ! Tu as atteint l'objectif zéro clope !";
        }

        $messages = [
            "Nouveau palier atteint : %d clopes/jour max !",
            "Bravo ! Tu passes à %d clopes/jour !",
            "Objectif %d clopes/jour débloqué !",
            "Super progression ! Nouvel objectif : %d/jour",
        ];

        $seed = (int) (new \DateTime())->format('Ymd');
        return sprintf($messages[$seed % count($messages)], $tier);
    }

    /**
     * Calcule la progression vers l'objectif du jour
     * @return array{goal: int, current: int, remaining: int, exceeded: bool, exceeded_by: int, progress_percent: int}|null
     */
    public function getDailyProgress(): ?array
    {
        $goal = $this->getCurrentGoal();
        if ($goal === null) {
            // Utiliser le palier automatique si pas d'objectif personnalisé
            $tierInfo = $this->getTierInfo();
            $goal = $tierInfo['current_tier'];
        }

        $todayCount = $this->cigaretteRepository->countByDate(new \DateTime());
        $remaining = $goal - $todayCount;

        return [
            'goal' => $goal,
            'current' => $todayCount,
            'remaining' => max(0, $remaining),
            'exceeded' => $remaining < 0,
            'exceeded_by' => $remaining < 0 ? abs($remaining) : 0,
            'progress_percent' => $goal > 0 ? min(100, (int) round(($todayCount / $goal) * 100)) : 100,
        ];
    }

    /**
     * Récupère les infos de progression pour l'affichage
     */
    public function getProgressInfo(): array
    {
        $tierInfo = $this->getTierInfo();
        $dailyProgress = $this->getDailyProgress();
        $currentGoal = $this->getCurrentGoal();

        // Calcul de la moyenne actuelle
        $stats = $this->cigaretteRepository->getDailyStats(7);
        $currentAvg = !empty($stats) ? round(array_sum($stats) / count($stats), 1) : null;

        return [
            'tier' => $tierInfo,
            'daily' => $dailyProgress,
            'custom_goal' => $currentGoal,
            'using_auto_tier' => $currentGoal === null,
            'current_avg' => $currentAvg,
        ];
    }
}
