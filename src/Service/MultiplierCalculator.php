<?php

namespace App\Service;

use App\Entity\User;
use App\Repository\ActiveBonusRepository;
use App\Repository\UserStateRepository;

/**
 * Service dédié au calcul des multiplicateurs de scoring v2.0
 *
 * Zone négative (avant la cible):
 * - 0-10 min: ×1.0
 * - 10-20 min: ×1.5
 * - 20+ min: ×2.0
 *
 * Zone positive (après la cible):
 * - 0-30 min: ×1.0
 * - 30-60 min: ×1.2
 * - 60+ min: ×1.5
 */
class MultiplierCalculator
{
    // Zone négative (fumé AVANT la cible) - seuils en minutes
    public const NEGATIVE_ZONE_TIER1_MAX = 10;  // 0-10 min: ×1.0
    public const NEGATIVE_ZONE_TIER2_MAX = 20;  // 10-20 min: ×1.5
    // 20+ min: ×2.0

    public const NEGATIVE_ZONE_TIER1_MULT = 1.0;
    public const NEGATIVE_ZONE_TIER2_MULT = 1.5;
    public const NEGATIVE_ZONE_TIER3_MULT = 2.0;

    // Zone positive (fumé APRÈS la cible) - seuils en minutes
    public const POSITIVE_ZONE_TIER1_MAX = 30;  // 0-30 min: ×1.0
    public const POSITIVE_ZONE_TIER2_MAX = 60;  // 30-60 min: ×1.2
    // 60+ min: ×1.5

    public const POSITIVE_ZONE_TIER1_MULT = 1.0;
    public const POSITIVE_ZONE_TIER2_MULT = 1.2;
    public const POSITIVE_ZONE_TIER3_MULT = 1.5;

    public function __construct(
        private UserStateRepository $userStateRepository,
        private ActiveBonusRepository $activeBonusRepository,
        private RankService $rankService
    ) {}

    /**
     * Calcule le multiplicateur de zone basé sur la différence de temps
     *
     * @param float $minutesDiff Différence en minutes (négatif = avant cible, positif = après)
     * @return float Le multiplicateur de zone
     */
    public function getZoneMultiplier(float $minutesDiff): float
    {
        $absMinutes = abs($minutesDiff);

        if ($minutesDiff < 0) {
            // Zone négative (fumé AVANT la cible)
            if ($absMinutes <= self::NEGATIVE_ZONE_TIER1_MAX) {
                return self::NEGATIVE_ZONE_TIER1_MULT;
            } elseif ($absMinutes <= self::NEGATIVE_ZONE_TIER2_MAX) {
                return self::NEGATIVE_ZONE_TIER2_MULT;
            } else {
                return self::NEGATIVE_ZONE_TIER3_MULT;
            }
        } else {
            // Zone positive (fumé APRÈS la cible) ou à l'heure exacte
            if ($absMinutes <= self::POSITIVE_ZONE_TIER1_MAX) {
                return self::POSITIVE_ZONE_TIER1_MULT;
            } elseif ($absMinutes <= self::POSITIVE_ZONE_TIER2_MAX) {
                return self::POSITIVE_ZONE_TIER2_MULT;
            } else {
                return self::POSITIVE_ZONE_TIER3_MULT;
            }
        }
    }

    /**
     * Calcule le multiplicateur total pour un utilisateur
     * Total = zone × (1 + bonus_rang + sum(bonus_badges_permanents))
     *
     * @param User $user L'utilisateur
     * @param float $minutesDiff Différence en minutes
     * @return float Le multiplicateur total
     */
    public function getTotalMultiplier(User $user, float $minutesDiff): float
    {
        $zoneMultiplier = $this->getZoneMultiplier($minutesDiff);
        $baseMultiplier = 1.0;

        // Ajouter le bonus de rang
        $rankBonus = $this->getRankMultiplierBonus($user);
        $baseMultiplier += $rankBonus;

        // Ajouter les bonus permanents des badges
        $userState = $this->userStateRepository->findByUser($user);
        if ($userState !== null) {
            $baseMultiplier += $userState->getPermanentMultiplier();
        }

        return $zoneMultiplier * $baseMultiplier;
    }

    /**
     * Récupère le bonus de multiplicateur du rang actuel
     */
    public function getRankMultiplierBonus(User $user): float
    {
        $userState = $this->userStateRepository->findByUser($user);
        $totalScore = $userState?->getTotalScore() ?? 0;

        $rankInfo = $this->rankService->getCurrentRank($totalScore);

        return $rankInfo['multiplier_bonus'] ?? 0.0;
    }

    /**
     * Calcule les points pour une cigarette avec le nouveau système v2.0
     * Points = minutesDiff × zoneMultiplier × totalMultiplier × (1 + bonus_temporaire)
     *
     * @param User $user L'utilisateur
     * @param float $minutesDiff Différence en minutes (négatif = avant cible)
     * @return int Les points (positifs ou négatifs)
     */
    public function calculatePoints(User $user, float $minutesDiff): int
    {
        $totalMultiplier = $this->getTotalMultiplier($user, $minutesDiff);

        // Points = différence × multiplicateur
        // Négatif si avant la cible, positif si après
        $points = $minutesDiff * $totalMultiplier;

        // Appliquer les bonus temporaires (score_percent) uniquement sur les points positifs
        if ($points > 0) {
            $tempBonus = $this->getTemporaryScoreBonus($user);
            $points = $points * (1 + $tempBonus);
        }

        return (int) round($points);
    }

    /**
     * Calcule le bonus temporaire total (score_percent) de l'utilisateur
     *
     * @return float Le bonus en multiplicateur (5% -> 0.05)
     */
    public function getTemporaryScoreBonus(User $user): float
    {
        $bonuses = $this->activeBonusRepository->findActiveByUserAndType(
            $user,
            \App\Entity\ActiveBonus::TYPE_SCORE_PERCENT
        );

        $total = 0.0;
        foreach ($bonuses as $bonus) {
            $total += $bonus->getBonusValue();
        }

        return $total / 100; // Convertir en multiplicateur (5% -> 0.05)
    }

    /**
     * Récupère les bonus actifs de type multiplicateur pour un utilisateur
     *
     * @return array Liste des bonus actifs avec leurs valeurs
     */
    public function getActiveMultiplierBonuses(User $user): array
    {
        $bonuses = $this->activeBonusRepository->findActiveByUserAndType(
            $user,
            \App\Entity\ActiveBonus::TYPE_MULTIPLIER
        );

        return array_map(fn($b) => [
            'source' => $b->getSourceBadge(),
            'value' => $b->getBonusValue(),
            'expires_at' => $b->getExpiresAt()->format('Y-m-d H:i'),
        ], $bonuses);
    }
}
