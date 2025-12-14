<?php

namespace App\Constants;

/**
 * Constantes pour le système de scoring
 * Évite les valeurs magiques dispersées dans le code
 */
final class ScoringConstants
{
    // Intervalles par défaut (en minutes)
    public const DEFAULT_INTERVAL_MINUTES = 60;
    public const DEFAULT_FIRST_CIG_MINUTES = 30;

    // Points
    public const POINTS_PER_INTERVAL = 20;
    public const MAX_MALUS_POINTS = -20;
    public const MIN_POSITIVE_POINTS = 1;
    public const POINTS_NEUTRAL = -1;

    // Bonus
    public const BONUS_PER_REDUCED_CIG = 5;
    public const BONUS_REGULARITY = 10;
    public const BONUS_WEEKLY_SIGNIFICANT = 15;
    public const BONUS_WEEKLY_STABLE = 5;

    // Périodes de calcul (en jours)
    public const SMOOTHING_PERIOD_DAYS = 7;
    public const TIER_CALCULATION_DAYS = 14;

    // Seuils
    public const MIN_CIGS_FOR_REGULARITY_BONUS = 3;
    public const SIGNIFICANT_WEEKLY_REDUCTION = 1; // clopes/jour
}
