<?php

namespace App\Service;

use App\Entity\ActiveBonus;
use App\Entity\User;
use App\Entity\UserBadge;
use App\Repository\ActiveBonusRepository;
use App\Repository\CigaretteRepository;
use App\Repository\DailyScoreRepository;
use App\Repository\SettingsRepository;
use App\Repository\UserBadgeRepository;
use App\Repository\UserStateRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * Service de gestion des badges v2.0
 *
 * Charge les badges depuis config/packages/badges.yaml
 * Gère les bonus temporaires (ActiveBonus) et permanents (UserState)
 */
class BadgeService
{
    private array $badges;

    public function __construct(
        private CigaretteRepository $cigaretteRepository,
        private SettingsRepository $settingsRepository,
        private UserBadgeRepository $userBadgeRepository,
        private DailyScoreRepository $dailyScoreRepository,
        private ActiveBonusRepository $activeBonusRepository,
        private UserStateRepository $userStateRepository,
        private EntityManagerInterface $entityManager,
        private ScoringService $scoringService,
        private StatsService $statsService,
        private StreakService $streakService,
        private Security $security,
        private ParameterBagInterface $params
    ) {
        // Charger les badges depuis le YAML
        $this->badges = $params->has('badges') ? $params->get('badges') : [];
    }

    private function getCurrentUser(): ?User
    {
        $user = $this->security->getUser();
        return $user instanceof User ? $user : null;
    }

    /**
     * Récupère tous les badges définis
     */
    public function getAllBadgeDefinitions(): array
    {
        return $this->badges;
    }

    /**
     * Vérifie et attribue les nouveaux badges
     * @return array<string, array> Infos sur les nouveaux badges obtenus
     */
    public function checkAndAwardBadges(): array
    {
        $user = $this->getCurrentUser();
        if (!$user) {
            return [];
        }

        $existingBadges = $this->userBadgeRepository->findUserBadgeCodes($user);
        $newBadges = [];

        foreach ($this->badges as $code => $badge) {
            if (in_array($code, $existingBadges, true)) {
                continue;
            }

            if ($this->checkBadgeCondition($code, $badge)) {
                $this->awardBadge($user, $code, $badge);
                $newBadges[$code] = $badge;
            }
        }

        if (!empty($newBadges)) {
            $this->entityManager->flush();
        }

        return $newBadges;
    }

    /**
     * Vérifie si les conditions d'un badge sont remplies
     */
    private function checkBadgeCondition(string $code, array $badge): bool
    {
        $condition = $badge['condition'] ?? [];
        $type = $condition['type'] ?? '';
        $value = $condition['value'] ?? 0;

        return match ($type) {
            'days_no_negative' => $this->checkDaysNoNegative($value),
            'streak_days' => $this->checkStreakDays($value),
            'reduction_percent' => $this->checkReduction($value),
            'zero_day' => $this->checkZeroDay(),
            'target_interval' => $this->checkTargetInterval($value),
            'savings' => $this->checkSavings($value),
            'comeback' => $this->checkComeback($value),
            'phoenix' => $this->checkPhoenix($value),
            'shield_used' => $this->checkShieldsUsed($value),
            'founder' => $this->checkFounder(),
            'stats_views' => $this->checkStatsViews($value),
            default => false,
        };
    }

    /**
     * Vérifie les jours consécutifs sans zone négative
     */
    private function checkDaysNoNegative(int $days): bool
    {
        $user = $this->getCurrentUser();
        if (!$user) {
            return false;
        }

        // Récupérer les scores récents et compter les jours sans zone négative
        $recentScores = $this->dailyScoreRepository->getRecentScores(max($days * 2, 30));

        $consecutiveDays = 0;
        $maxConsecutive = 0;

        // Trier par date décroissante pour compter depuis aujourd'hui
        krsort($recentScores);

        foreach ($recentScores as $score) {
            // Un score >= 0 signifie pas de zone négative nette ce jour
            if ($score->getScore() >= 0) {
                $consecutiveDays++;
                $maxConsecutive = max($maxConsecutive, $consecutiveDays);
            } else {
                // Reset si score négatif
                break;
            }
        }

        return $maxConsecutive >= $days;
    }

    /**
     * Vérifie un streak de X jours (best streak)
     */
    private function checkStreakDays(int $days): bool
    {
        $bestStreak = $this->dailyScoreRepository->getBestStreak();
        return $bestStreak >= $days;
    }

    /**
     * Vérifie les économies réalisées
     */
    private function checkSavings(float $amount): bool
    {
        $savings = $this->statsService->calculateSavings();
        return ($savings['total'] ?? 0) >= $amount;
    }

    /**
     * Vérifie une réduction de X% vs consommation initiale
     */
    private function checkReduction(int $percent): bool
    {
        $initialDailyCigs = (int) $this->settingsRepository->get('initial_daily_cigs', '20');
        if ($initialDailyCigs <= 0) {
            return false;
        }

        $stats = $this->cigaretteRepository->getDailyStats(7);
        if (empty($stats)) {
            return false;
        }

        $currentAvg = array_sum($stats) / count($stats);
        $reductionPercent = (($initialDailyCigs - $currentAvg) / $initialDailyCigs) * 100;

        return $reductionPercent >= $percent;
    }

    /**
     * Vérifie si l'utilisateur a eu un jour sans fumer
     */
    private function checkZeroDay(): bool
    {
        $zeroDays = $this->cigaretteRepository->findZeroDays();
        return !empty($zeroDays);
    }

    /**
     * Vérifie si l'intervalle cible a atteint une valeur
     */
    private function checkTargetInterval(int $minutes): bool
    {
        $user = $this->getCurrentUser();
        if (!$user) {
            return false;
        }

        $userState = $this->userStateRepository->findByUser($user);
        if (!$userState) {
            return false;
        }

        $currentInterval = $userState->getCurrentTargetInterval() ?? 60;
        return $currentInterval >= $minutes;
    }

    /**
     * Vérifie un comeback (score positif après N jours négatifs)
     */
    private function checkComeback(int $negDays): bool
    {
        $recentScores = $this->dailyScoreRepository->getRecentScores($negDays + 5);

        if (count($recentScores) < $negDays + 1) {
            return false;
        }

        // Trier par date
        ksort($recentScores);
        $scores = array_values($recentScores);

        // Chercher un pattern: N jours négatifs suivis d'un jour positif
        $negativeCount = 0;
        $foundPattern = false;

        for ($i = 0; $i < count($scores) - 1; $i++) {
            if ($scores[$i]->getScore() < 0) {
                $negativeCount++;
                if ($negativeCount >= $negDays && isset($scores[$i + 1]) && $scores[$i + 1]->getScore() > 0) {
                    $foundPattern = true;
                    break;
                }
            } else {
                $negativeCount = 0;
            }
        }

        return $foundPattern;
    }

    /**
     * Vérifie le badge Phoenix (points après reset)
     */
    private function checkPhoenix(int $pointsRequired): bool
    {
        // Vérifier s'il y a eu un reset
        $resetHistory = $this->settingsRepository->get('reset_history', '[]');
        $resets = json_decode($resetHistory, true);

        if (empty($resets)) {
            return false;
        }

        // Vérifier si le score total actuel dépasse le seuil requis
        $totalScore = $this->dailyScoreRepository->getTotalScore();
        return $totalScore >= $pointsRequired;
    }

    /**
     * Vérifie le nombre de boucliers utilisés
     */
    private function checkShieldsUsed(int $count): bool
    {
        $shieldsUsed = (int) $this->settingsRepository->get('shields_used', '0');
        return $shieldsUsed >= $count;
    }

    /**
     * Vérifie le badge fondateur
     */
    private function checkFounder(): bool
    {
        // Badge fondateur - vérifie si l'utilisateur était là avant une date donnée
        $founderDate = $this->settingsRepository->get('founder_cutoff_date');
        if (!$founderDate) {
            return false;
        }

        $firstCig = $this->cigaretteRepository->getFirstCigaretteDate();
        if (!$firstCig) {
            return false;
        }

        return $firstCig <= new \DateTime($founderDate);
    }

    /**
     * Vérifie le nombre de consultations de stats
     */
    private function checkStatsViews(int $count): bool
    {
        $views = (int) $this->settingsRepository->get('stats_views', '0');
        return $views >= $count;
    }

    /**
     * Attribue un badge à un utilisateur et applique le bonus
     */
    private function awardBadge(User $user, string $code, array $badge): void
    {
        // Créer le UserBadge
        $userBadge = new UserBadge();
        $userBadge->setUser($user);
        $userBadge->setBadgeCode($code);
        $this->entityManager->persist($userBadge);

        // Appliquer le bonus si défini
        $this->applyBadgeBonus($user, $code, $badge);
    }

    /**
     * Applique le bonus d'un badge
     */
    private function applyBadgeBonus(User $user, string $code, array $badge): void
    {
        $bonus = $badge['bonus'] ?? [];
        if (empty($bonus)) {
            return;
        }

        $type = $bonus['type'] ?? '';
        $value = $bonus['value'] ?? 0;
        $duration = $bonus['duration'] ?? null;

        switch ($type) {
            case 'score_percent':
                // Bonus temporaire : créer un ActiveBonus
                $this->createTemporaryBonus($user, $code, ActiveBonus::TYPE_SCORE_PERCENT, $value, $duration);
                break;

            case 'multiplier':
                // Bonus permanent : ajouter au UserState
                $this->addPermanentMultiplier($user, $value);
                break;

            case 'shield':
                // Ajouter des boucliers
                $this->addShields($user, (int) $value);
                break;
        }
    }

    /**
     * Crée un bonus temporaire
     */
    private function createTemporaryBonus(User $user, string $sourceBadge, string $type, float $value, ?int $durationMinutes): void
    {
        if ($durationMinutes === null) {
            $durationMinutes = 1440; // Default 24h
        }

        $activeBonus = new ActiveBonus();
        $activeBonus->setUser($user);
        $activeBonus->setBonusType($type);
        $activeBonus->setBonusValue($value);
        $activeBonus->setSourceBadge($sourceBadge);
        $activeBonus->setExpiresAt((new \DateTime())->modify("+{$durationMinutes} minutes"));

        $this->entityManager->persist($activeBonus);
    }

    /**
     * Ajoute un bonus multiplicateur permanent
     */
    private function addPermanentMultiplier(User $user, float $value): void
    {
        $userState = $this->userStateRepository->findOrCreateByUser($user);
        $userState->addPermanentMultiplier($value);
    }

    /**
     * Ajoute des boucliers
     */
    private function addShields(User $user, int $count): void
    {
        $userState = $this->userStateRepository->findOrCreateByUser($user);
        for ($i = 0; $i < $count; $i++) {
            $userState->addShield();
        }
    }

    /**
     * Récupère tous les badges avec leur statut (obtenu ou non)
     */
    public function getAllBadgesWithStatus(): array
    {
        $user = $this->getCurrentUser();
        $unlockedBadges = [];

        if ($user) {
            $userBadges = $this->userBadgeRepository->findUserBadges($user);
            foreach ($userBadges as $ub) {
                $unlockedBadges[$ub->getBadgeCode()] = $ub->getUnlockedAt();
            }
        }

        $result = [];
        foreach ($this->badges as $code => $badge) {
            $result[$code] = [
                ...$badge,
                'code' => $code,
                'unlocked' => isset($unlockedBadges[$code]),
                'unlocked_at' => $unlockedBadges[$code] ?? null,
            ];
        }

        return $result;
    }

    /**
     * Récupère uniquement les badges débloqués
     */
    public function getUnlockedBadges(): array
    {
        return array_filter(
            $this->getAllBadgesWithStatus(),
            fn($badge) => $badge['unlocked']
        );
    }

    /**
     * Compte les badges débloqués
     */
    public function countUnlockedBadges(): int
    {
        $user = $this->getCurrentUser();
        if (!$user) {
            return 0;
        }
        return count($this->userBadgeRepository->findUserBadgeCodes($user));
    }

    /**
     * Retourne les infos d'un badge par son code
     */
    public function getBadgeInfo(string $code): ?array
    {
        return $this->badges[$code] ?? null;
    }

    /**
     * Récupère les bonus actifs de l'utilisateur
     * @return ActiveBonus[]
     */
    public function getActiveBonuses(): array
    {
        $user = $this->getCurrentUser();
        if (!$user) {
            return [];
        }
        return $this->activeBonusRepository->findActiveByUser($user);
    }

    /**
     * Calcule le bonus temporaire total (score_percent)
     */
    public function getTotalTemporaryBonus(): float
    {
        $bonuses = $this->getActiveBonuses();
        $total = 0.0;

        foreach ($bonuses as $bonus) {
            if ($bonus->getBonusType() === ActiveBonus::TYPE_SCORE_PERCENT) {
                $total += $bonus->getBonusValue();
            }
        }

        return $total / 100; // Convertir en multiplicateur (5% -> 0.05)
    }

    /**
     * Incrémente le compteur de vues stats
     */
    public function incrementStatsViews(): void
    {
        $current = (int) $this->settingsRepository->get('stats_views', '0');
        $this->settingsRepository->set('stats_views', (string) ($current + 1));
    }

    /**
     * Incrémente le compteur de boucliers utilisés
     */
    public function incrementShieldsUsed(): void
    {
        $current = (int) $this->settingsRepository->get('shields_used', '0');
        $this->settingsRepository->set('shields_used', (string) ($current + 1));
    }

    /**
     * Récupère les badges par catégorie
     */
    public function getBadgesByCategory(): array
    {
        $allBadges = $this->getAllBadgesWithStatus();
        $byCategory = [];

        foreach ($allBadges as $code => $badge) {
            $category = $badge['category'] ?? 'other';
            if (!isset($byCategory[$category])) {
                $byCategory[$category] = [];
            }
            $byCategory[$category][$code] = $badge;
        }

        return $byCategory;
    }

    /**
     * Récupère le nombre de boucliers disponibles
     */
    public function getAvailableShields(): int
    {
        $user = $this->getCurrentUser();
        if (!$user) {
            return 0;
        }

        $userState = $this->userStateRepository->findByUser($user);
        return $userState?->getShieldsCount() ?? 0;
    }

    /**
     * Récupère le multiplicateur permanent total
     */
    public function getPermanentMultiplier(): float
    {
        $user = $this->getCurrentUser();
        if (!$user) {
            return 0.0;
        }

        $userState = $this->userStateRepository->findByUser($user);
        return $userState?->getPermanentMultiplier() ?? 0.0;
    }
}
