<?php

namespace App\Service;

use App\Repository\DailyScoreRepository;

/**
 * Service dédié au calcul des rangs utilisateur v2.0
 * 12 rangs avec multiplicateurs et avantages progressifs
 */
class RankService
{
    /**
     * Définition des rangs v2.0 avec seuils, multiplicateurs et avantages
     * Format: score => ['name' => ..., 'multiplier' => ..., 'advantages' => [...]]
     */
    private const RANKS = [
        0 => ['name' => 'Fumeur', 'multiplier' => 0.0, 'advantages' => []],
        100 => ['name' => 'Curieux', 'multiplier' => 0.0, 'advantages' => ['history_access']],
        500 => ['name' => 'Débutant', 'multiplier' => 0.0, 'advantages' => ['basic_stats']],
        1500 => ['name' => 'Apprenti', 'multiplier' => 0.02, 'advantages' => []],
        3500 => ['name' => 'Initié', 'multiplier' => 0.0, 'advantages' => ['week_stats']],
        7500 => ['name' => 'Confirmé', 'multiplier' => 0.05, 'advantages' => []],
        15000 => ['name' => 'Avancé', 'multiplier' => 0.0, 'advantages' => ['extra_maintenance']],
        30000 => ['name' => 'Expert', 'multiplier' => 0.08, 'advantages' => []],
        60000 => ['name' => 'Maître', 'multiplier' => 0.0, 'advantages' => ['advanced_stats']],
        120000 => ['name' => 'Grand Maître', 'multiplier' => 0.12, 'advantages' => []],
        200000 => ['name' => 'Sage', 'multiplier' => 0.0, 'advantages' => ['monthly_shield']],
        350000 => ['name' => 'Légende', 'multiplier' => 0.0, 'advantages' => ['exclusive_theme']],
    ];

    /**
     * Emojis associés aux rangs
     */
    private const RANK_EMOJIS = [
        'Fumeur' => '🚬',
        'Curieux' => '🔍',
        'Débutant' => '🌱',
        'Apprenti' => '📚',
        'Initié' => '🎯',
        'Confirmé' => '💪',
        'Avancé' => '⚔️',
        'Expert' => '🏆',
        'Maître' => '🦸',
        'Grand Maître' => '👑',
        'Sage' => '🧘',
        'Légende' => '⭐',
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

        $currentRankData = self::RANKS[0];
        $currentThreshold = 0;
        $nextRankThreshold = null;

        $thresholds = array_keys(self::RANKS);
        foreach ($thresholds as $i => $threshold) {
            if ($totalScore >= $threshold) {
                $currentRankData = self::RANKS[$threshold];
                $currentThreshold = $threshold;
                // Prochain seuil
                $nextRankThreshold = $thresholds[$i + 1] ?? null;
            } else {
                $nextRankThreshold = $threshold;
                break;
            }
        }

        $currentRank = $currentRankData['name'];

        $progress = 0;
        if ($nextRankThreshold !== null && $nextRankThreshold > $currentThreshold) {
            $progress = (($totalScore - $currentThreshold) / ($nextRankThreshold - $currentThreshold)) * 100;
            $progress = min(100, max(0, $progress));
        } elseif ($nextRankThreshold === null) {
            $progress = 100; // Rang max atteint
        }

        return [
            'rank' => $currentRank,
            'emoji' => self::RANK_EMOJIS[$currentRank] ?? '🚬',
            'total_score' => $totalScore,
            'current_threshold' => $currentThreshold,
            'next_rank_threshold' => $nextRankThreshold,
            'next_rank' => $this->getNextRank($currentRank),
            'progress' => round($progress),
            'points_to_next' => $nextRankThreshold !== null ? $nextRankThreshold - $totalScore : 0,
            'multiplier_bonus' => $currentRankData['multiplier'],
            'advantages' => $currentRankData['advantages'],
        ];
    }

    /**
     * Retourne le prochain rang
     */
    public function getNextRank(string $currentRank): ?string
    {
        $rankNames = array_values(array_map(fn($r) => $r['name'], self::RANKS));
        $currentIndex = array_search($currentRank, $rankNames);

        if ($currentIndex === false || $currentIndex >= count($rankNames) - 1) {
            return null; // Déjà au rang maximum
        }

        return $rankNames[$currentIndex + 1];
    }

    /**
     * Retourne tous les rangs avec leurs seuils et propriétés
     */
    public function getAllRanks(): array
    {
        $result = [];
        foreach (self::RANKS as $threshold => $rankData) {
            $result[] = [
                'rank' => $rankData['name'],
                'emoji' => self::RANK_EMOJIS[$rankData['name']] ?? '🚬',
                'threshold' => $threshold,
                'multiplier' => $rankData['multiplier'],
                'advantages' => $rankData['advantages'],
            ];
        }
        return $result;
    }

    /**
     * Vérifie si l'utilisateur vient de changer de rang
     */
    public function checkRankUp(int $previousScore, int $newScore): ?array
    {
        $previousRankInfo = $this->getCurrentRank($previousScore);
        $newRankInfo = $this->getCurrentRank($newScore);

        if ($previousRankInfo['rank'] !== $newRankInfo['rank']) {
            $rankNames = array_values(array_map(fn($r) => $r['name'], self::RANKS));
            return [
                'previous_rank' => $previousRankInfo['rank'],
                'new_rank' => $newRankInfo['rank'],
                'new_emoji' => self::RANK_EMOJIS[$newRankInfo['rank']] ?? '🚬',
                'is_rank_up' => array_search($newRankInfo['rank'], $rankNames) >
                               array_search($previousRankInfo['rank'], $rankNames),
                'new_multiplier' => $newRankInfo['multiplier_bonus'],
                'new_advantages' => $newRankInfo['advantages'],
            ];
        }

        return null;
    }

    /**
     * Retourne le score nécessaire pour atteindre un rang spécifique
     */
    public function getScoreForRank(string $rank): ?int
    {
        foreach (self::RANKS as $threshold => $rankData) {
            if ($rankData['name'] === $rank) {
                return $threshold;
            }
        }
        return null;
    }

    /**
     * Vérifie si l'utilisateur a un avantage spécifique
     */
    public function hasAdvantage(int $totalScore, string $advantage): bool
    {
        $rankInfo = $this->getCurrentRank($totalScore);
        return in_array($advantage, $rankInfo['advantages'], true);
    }

    /**
     * Retourne le multiplicateur cumulé de tous les rangs jusqu'au rang actuel
     */
    public function getCumulativeMultiplier(int $totalScore): float
    {
        $cumulative = 0.0;
        foreach (self::RANKS as $threshold => $rankData) {
            if ($totalScore >= $threshold) {
                $cumulative += $rankData['multiplier'];
            } else {
                break;
            }
        }
        return $cumulative;
    }
}
