<?php

namespace App\Service;

use App\Repository\DailyScoreRepository;

/**
 * Service dédié au calcul des rangs utilisateur
 * Extrait de ScoringService pour une meilleure maintenabilité
 */
class RankService
{
    /**
     * Définition des rangs avec leurs seuils de points
     */
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

    /**
     * Emojis associés aux rangs
     */
    private const RANK_EMOJIS = [
        'Débutant' => '🌱',
        'Apprenti' => '📚',
        'Résistant' => '💪',
        'Guerrier' => '⚔️',
        'Champion' => '🏆',
        'Héros' => '🦸',
        'Légende' => '⭐',
        'Maître du souffle' => '🧘',
    ];

    public function __construct(
        private DailyScoreRepository $dailyScoreRepository
    ) {}

    /**
     * Retourne le rang actuel basé sur le score total
     */
    public function getCurrentRank(?int $totalScore = null): array
    {
        if ($totalScore === null) {
            $totalScore = $this->dailyScoreRepository->getTotalScore();
        }

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

        // Si on a atteint le dernier rang
        if ($currentRank === 'Maître du souffle') {
            $nextRankThreshold = null;
        }

        $progress = 0;
        if ($nextRankThreshold !== null && $nextRankThreshold > $currentThreshold) {
            $progress = (($totalScore - $currentThreshold) / ($nextRankThreshold - $currentThreshold)) * 100;
            $progress = min(100, max(0, $progress));
        } elseif ($nextRankThreshold === null) {
            $progress = 100; // Rang max atteint
        }

        return [
            'rank' => $currentRank,
            'emoji' => self::RANK_EMOJIS[$currentRank] ?? '🌱',
            'total_score' => $totalScore,
            'current_threshold' => $currentThreshold,
            'next_rank_threshold' => $nextRankThreshold,
            'next_rank' => $this->getNextRank($currentRank),
            'progress' => round($progress),
            'points_to_next' => $nextRankThreshold !== null ? $nextRankThreshold - $totalScore : 0,
        ];
    }

    /**
     * Retourne le prochain rang
     */
    public function getNextRank(string $currentRank): ?string
    {
        $ranks = array_values(self::RANKS);
        $currentIndex = array_search($currentRank, $ranks);

        if ($currentIndex === false || $currentIndex >= count($ranks) - 1) {
            return null; // Déjà au rang maximum
        }

        return $ranks[$currentIndex + 1];
    }

    /**
     * Retourne tous les rangs avec leurs seuils
     */
    public function getAllRanks(): array
    {
        $result = [];
        foreach (self::RANKS as $threshold => $rank) {
            $result[] = [
                'rank' => $rank,
                'emoji' => self::RANK_EMOJIS[$rank] ?? '🌱',
                'threshold' => $threshold,
            ];
        }
        return $result;
    }

    /**
     * Vérifie si l'utilisateur vient de changer de rang
     */
    public function checkRankUp(int $previousScore, int $newScore): ?array
    {
        $previousRank = $this->getCurrentRank($previousScore)['rank'];
        $newRank = $this->getCurrentRank($newScore)['rank'];

        if ($previousRank !== $newRank) {
            return [
                'previous_rank' => $previousRank,
                'new_rank' => $newRank,
                'new_emoji' => self::RANK_EMOJIS[$newRank] ?? '🌱',
                'is_rank_up' => array_search($newRank, array_values(self::RANKS)) >
                               array_search($previousRank, array_values(self::RANKS)),
            ];
        }

        return null;
    }

    /**
     * Retourne le score nécessaire pour atteindre un rang spécifique
     */
    public function getScoreForRank(string $rank): ?int
    {
        $flippedRanks = array_flip(self::RANKS);
        return $flippedRanks[$rank] ?? null;
    }
}
