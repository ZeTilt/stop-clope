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
     * Calcule le palier actuel dynamique
     * = floor(moyenne 14 derniers jours) - 1
     *
     * Règles :
     * - Basé sur la moyenne des 14 derniers jours complets
     * - -1 pour pousser à la progression
     * - Ne peut JAMAIS monter (plafond = palier précédent stocké)
     * - Minimum = 0
     *
     * @return array{current_tier: int, next_tier: int|null, initial: int, avg_14d: float|null}
     */
    public function getTierInfo(): array
    {
        $initialDailyCigs = (int) $this->settingsRepository->get('initial_daily_cigs', '20');

        // Récupérer la moyenne des 14 derniers jours
        $avgDaily = $this->cigaretteRepository->getAverageDailyCount(14);

        if ($avgDaily === null) {
            // Pas assez de données, utiliser la valeur initiale
            return [
                'current_tier' => $initialDailyCigs,
                'next_tier' => max(0, $initialDailyCigs - 1),
                'initial' => $initialDailyCigs,
                'avg_14d' => null,
            ];
        }

        // Palier dynamique = floor(moyenne) - 1 (toujours pousser vers le bas)
        $dynamicTier = max(0, (int) floor($avgDaily) - 1);

        // Récupérer le palier précédent (plafond - ne peut pas monter)
        $previousTier = $this->settingsRepository->get('current_auto_tier');

        if ($previousTier === null) {
            // Premier calcul : initialiser avec le palier dynamique ou initial
            $currentTier = min($initialDailyCigs, $dynamicTier);
        } else {
            // Le palier ne peut que descendre ou rester stable
            $currentTier = min((int) $previousTier, $dynamicTier);
        }

        // Sauvegarder le nouveau palier si différent
        if ($previousTier === null || (int) $previousTier !== $currentTier) {
            $this->settingsRepository->set('current_auto_tier', (string) $currentTier);
        }

        $nextTier = $currentTier > 0 ? $currentTier - 1 : null;

        return [
            'current_tier' => $currentTier,
            'next_tier' => $nextTier,
            'initial' => $initialDailyCigs,
            'avg_14d' => round($avgDaily, 1),
        ];
    }

    /**
     * Vérifie si l'utilisateur a atteint un nouveau palier
     * Le palier est dynamique et descend automatiquement quand la moyenne baisse
     * @return array{achieved: bool, new_tier: int|null, message: string|null}
     */
    public function checkTierAchievement(): array
    {
        $previousTier = $this->settingsRepository->get('previous_displayed_tier');
        $tierInfo = $this->getTierInfo();
        $currentTier = $tierInfo['current_tier'];

        // Si pas de palier précédent affiché, initialiser
        if ($previousTier === null) {
            $this->settingsRepository->set('previous_displayed_tier', (string) $currentTier);
            return ['achieved' => false, 'new_tier' => null, 'message' => null];
        }

        // Si le palier a baissé, c'est une achievement !
        if ($currentTier < (int) $previousTier) {
            $this->settingsRepository->set('previous_displayed_tier', (string) $currentTier);
            return [
                'achieved' => true,
                'new_tier' => $currentTier,
                'message' => $this->getTierAchievementMessage($currentTier),
            ];
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
     * Calcule la progression vers l'objectif du jour (palier automatique)
     * @return array{goal: int, current: int, remaining: int, exceeded: bool, exceeded_by: int, progress_percent: int}
     */
    public function getDailyProgress(): array
    {
        $tierInfo = $this->getTierInfo();
        $goal = $tierInfo['current_tier'];

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

        return [
            'tier' => $tierInfo,
            'daily' => $dailyProgress,
        ];
    }
}
