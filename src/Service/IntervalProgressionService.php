<?php

namespace App\Service;

use App\Entity\User;
use App\Repository\DailyScoreRepository;
use App\Repository\UserStateRepository;

/**
 * Service gérant la progression obligatoire de l'intervalle cible v2.0
 *
 * Règles:
 * - L'intervalle cible augmente d'au moins 1 minute chaque jour
 * - Si l'utilisateur a dépassé la cible hier, l'augmentation peut être supérieure
 * - L'intervalle ne peut jamais diminuer (sauf jour maintenance)
 */
class IntervalProgressionService
{
    /** Augmentation minimum par jour (en minutes) */
    private const MIN_DAILY_INCREASE = 1.0;

    /** Bonus d'augmentation si performance > 10% au-dessus de la cible */
    private const BONUS_INCREASE_THRESHOLD = 0.10;
    private const BONUS_INCREASE_AMOUNT = 0.5;

    /** Intervalle initial par défaut (en minutes) */
    private const DEFAULT_INITIAL_INTERVAL = 60.0;

    public function __construct(
        private UserStateRepository $userStateRepository,
        private DailyScoreRepository $dailyScoreRepository,
        private IntervalCalculator $intervalCalculator
    ) {}

    /**
     * Calcule l'intervalle cible pour aujourd'hui
     * Basé sur l'intervalle d'hier + augmentation obligatoire
     *
     * @param User $user L'utilisateur
     * @param \DateTimeInterface $today La date d'aujourd'hui
     * @return float L'intervalle cible en minutes
     */
    public function getTodayTargetInterval(User $user, \DateTimeInterface $today): float
    {
        $userState = $this->userStateRepository->findByUser($user);

        // Si l'utilisateur a déjà un intervalle cible défini
        if ($userState?->getCurrentTargetInterval() !== null) {
            return $userState->getCurrentTargetInterval();
        }

        // Sinon, calculer à partir de l'historique
        return $this->calculateNewTargetInterval($user, $today);
    }

    /**
     * Calcule et met à jour l'intervalle cible pour une nouvelle journée
     *
     * @param User $user L'utilisateur
     * @param \DateTimeInterface $today La date d'aujourd'hui
     * @param bool $isMaintenanceDay Si c'est un jour de maintenance
     * @return float Le nouvel intervalle cible
     */
    public function updateDailyTargetInterval(
        User $user,
        \DateTimeInterface $today,
        bool $isMaintenanceDay = false
    ): float {
        $userState = $this->userStateRepository->findByUser($user);

        if (!$userState) {
            // Pas d'état utilisateur, utiliser l'intervalle par défaut
            return self::DEFAULT_INITIAL_INTERVAL;
        }

        $currentTarget = $userState->getCurrentTargetInterval();

        // Si jour maintenance, garder le même intervalle
        if ($isMaintenanceDay && $currentTarget !== null) {
            return $currentTarget;
        }

        // Calculer le nouvel intervalle
        $newTarget = $this->calculateNewTargetInterval($user, $today);

        // Mettre à jour le state
        $userState->setCurrentTargetInterval($newTarget);
        $this->userStateRepository->save($userState);

        return $newTarget;
    }

    /**
     * Calcule le nouvel intervalle cible
     *
     * @param User $user L'utilisateur
     * @param \DateTimeInterface $today La date d'aujourd'hui
     * @return float Le nouvel intervalle cible
     */
    private function calculateNewTargetInterval(User $user, \DateTimeInterface $today): float
    {
        $userState = $this->userStateRepository->findByUser($user);
        $currentTarget = $userState?->getCurrentTargetInterval();

        // Premier jour ou pas d'intervalle défini
        if ($currentTarget === null) {
            // Utiliser la moyenne lissée des 7 derniers jours comme base
            $smoothedInterval = $this->intervalCalculator->getSmoothedAverageInterval($today);
            return max(self::DEFAULT_INITIAL_INTERVAL, $smoothedInterval);
        }

        // Calculer l'augmentation basée sur la performance d'hier
        $yesterday = (clone $today)->modify('-1 day');
        $yesterdayScore = $this->dailyScoreRepository->findByUserAndDate($user, $yesterday);

        $increase = self::MIN_DAILY_INCREASE;

        // Bonus si l'utilisateur a dépassé la cible hier
        if ($yesterdayScore !== null) {
            $yesterdayAvg = $yesterdayScore->getAverageInterval();
            $yesterdayTarget = $yesterdayScore->getTargetInterval() ?? $currentTarget;

            if ($yesterdayAvg !== null && $yesterdayTarget > 0) {
                $performance = $yesterdayAvg / $yesterdayTarget;

                // Si performance > 110%, bonus d'augmentation
                if ($performance > (1 + self::BONUS_INCREASE_THRESHOLD)) {
                    $increase += self::BONUS_INCREASE_AMOUNT;
                }
            }
        }

        return $currentTarget + $increase;
    }

    /**
     * Initialise l'intervalle cible pour un nouvel utilisateur
     *
     * @param User $user L'utilisateur
     * @param float|null $initialInterval Intervalle initial (optionnel)
     * @return float L'intervalle initialisé
     */
    public function initializeTargetInterval(User $user, ?float $initialInterval = null): float
    {
        $userState = $this->userStateRepository->findByUser($user);

        if (!$userState) {
            return self::DEFAULT_INITIAL_INTERVAL;
        }

        $target = $initialInterval ?? self::DEFAULT_INITIAL_INTERVAL;
        $userState->setCurrentTargetInterval($target);
        $this->userStateRepository->save($userState);

        return $target;
    }

    /**
     * Vérifie si l'intervalle peut être réduit (jour maintenance uniquement)
     */
    public function canReduceInterval(User $user, \DateTimeInterface $date): bool
    {
        // L'intervalle ne peut jamais être réduit explicitement
        // Seul le jour maintenance permet de ne pas augmenter
        return false;
    }
}
