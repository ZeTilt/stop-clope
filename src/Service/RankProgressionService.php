<?php

namespace App\Service;

use App\Entity\User;
use App\Repository\UserStateRepository;

/**
 * Service gérant la progression des rangs et les notifications de changement
 */
class RankProgressionService
{
    public function __construct(
        private RankService $rankService,
        private UserStateRepository $userStateRepository
    ) {}

    /**
     * Met à jour le rang de l'utilisateur et détecte les changements
     *
     * @param User $user L'utilisateur
     * @param int $newTotalScore Le nouveau score total
     * @return array|null Info de changement de rang si applicable
     */
    public function updateUserRank(User $user, int $newTotalScore): ?array
    {
        $userState = $this->userStateRepository->findOrCreateByUser($user);
        $previousScore = $userState->getTotalScore();
        $previousRank = $userState->getCurrentRank();

        // Calculer le nouveau rang
        $newRankInfo = $this->rankService->getCurrentRank($newTotalScore);
        $newRank = strtolower($newRankInfo['rank']);

        // Mettre à jour le state
        $userState->setTotalScore($newTotalScore);
        $userState->setCurrentRank($newRank);
        $this->userStateRepository->save($userState);

        // Vérifier si le rang a changé
        if ($previousRank !== $newRank) {
            return $this->rankService->checkRankUp($previousScore, $newTotalScore);
        }

        return null;
    }

    /**
     * Ajoute des points au score et met à jour le rang
     *
     * @param User $user L'utilisateur
     * @param int $points Points à ajouter (positif ou négatif)
     * @return array Résultat avec score, rang et éventuel changement
     */
    public function addPoints(User $user, int $points): array
    {
        $userState = $this->userStateRepository->findOrCreateByUser($user);
        $previousScore = $userState->getTotalScore();
        $newTotalScore = max(0, $previousScore + $points); // Jamais négatif

        $rankChange = $this->updateUserRank($user, $newTotalScore);
        $currentRankInfo = $this->rankService->getCurrentRank($newTotalScore);

        return [
            'previous_score' => $previousScore,
            'points_added' => $points,
            'new_total_score' => $newTotalScore,
            'current_rank' => $currentRankInfo,
            'rank_changed' => $rankChange !== null,
            'rank_change_info' => $rankChange,
        ];
    }

    /**
     * Récupère les infos de progression complètes pour l'utilisateur
     *
     * @param User $user L'utilisateur
     * @return array Infos de progression
     */
    public function getProgressionInfo(User $user): array
    {
        $userState = $this->userStateRepository->findByUser($user);
        $totalScore = $userState?->getTotalScore() ?? 0;

        $rankInfo = $this->rankService->getCurrentRank($totalScore);
        $allRanks = $this->rankService->getAllRanks();

        // Trouver l'index du rang actuel
        $currentRankIndex = 0;
        foreach ($allRanks as $index => $rank) {
            if ($rank['rank'] === $rankInfo['rank']) {
                $currentRankIndex = $index;
                break;
            }
        }

        // Calculer les rangs débloqués
        $unlockedRanks = array_slice($allRanks, 0, $currentRankIndex + 1);

        // Calculer le multiplicateur cumulé
        $cumulativeMultiplier = $this->rankService->getCumulativeMultiplier($totalScore);

        return [
            'total_score' => $totalScore,
            'current_rank' => $rankInfo,
            'all_ranks' => $allRanks,
            'unlocked_ranks' => $unlockedRanks,
            'ranks_remaining' => count($allRanks) - $currentRankIndex - 1,
            'cumulative_multiplier' => $cumulativeMultiplier,
            'current_advantages' => $this->getUnlockedAdvantages($totalScore),
        ];
    }

    /**
     * Récupère tous les avantages débloqués jusqu'au rang actuel
     *
     * @param int $totalScore Le score total
     * @return array Liste des avantages débloqués
     */
    public function getUnlockedAdvantages(int $totalScore): array
    {
        $allRanks = $this->rankService->getAllRanks();
        $advantages = [];

        foreach ($allRanks as $rank) {
            if ($totalScore >= $rank['threshold']) {
                foreach ($rank['advantages'] as $advantage) {
                    $advantages[] = [
                        'name' => $advantage,
                        'unlocked_at_rank' => $rank['rank'],
                        'unlocked_at_score' => $rank['threshold'],
                    ];
                }
            }
        }

        return $advantages;
    }

    /**
     * Vérifie si l'utilisateur a un avantage spécifique
     *
     * @param User $user L'utilisateur
     * @param string $advantage L'avantage à vérifier
     * @return bool True si l'avantage est débloqué
     */
    public function hasAdvantage(User $user, string $advantage): bool
    {
        $userState = $this->userStateRepository->findByUser($user);
        $totalScore = $userState?->getTotalScore() ?? 0;

        return $this->rankService->hasAdvantage($totalScore, $advantage);
    }
}
