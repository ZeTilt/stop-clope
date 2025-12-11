<?php

namespace App\Service;

use App\Entity\User;
use App\Entity\UserBadge;
use App\Repository\CigaretteRepository;
use App\Repository\SettingsRepository;
use App\Repository\UserBadgeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

class BadgeService
{
    // Définition des badges disponibles
    public const BADGES = [
        'first_step' => [
            'name' => 'Premier pas',
            'description' => 'Premier jour complété',
            'icon' => '👣',
            'category' => 'milestone',
        ],
        'week_streak' => [
            'name' => 'Une semaine',
            'description' => '7 jours de streak',
            'icon' => '🔥',
            'category' => 'streak',
        ],
        'saver_10' => [
            'name' => 'Économe',
            'description' => '10€ économisés',
            'icon' => '💰',
            'category' => 'savings',
        ],
        'saver_50' => [
            'name' => 'Épargnant',
            'description' => '50€ économisés',
            'icon' => '💎',
            'category' => 'savings',
        ],
        'saver_100' => [
            'name' => 'Riche',
            'description' => '100€ économisés',
            'icon' => '🏦',
            'category' => 'savings',
        ],
        'month_streak' => [
            'name' => 'Marathonien',
            'description' => '30 jours de streak',
            'icon' => '🏃',
            'category' => 'streak',
        ],
        'reducer_25' => [
            'name' => 'En progrès',
            'description' => '-25% vs consommation initiale',
            'icon' => '📉',
            'category' => 'reduction',
        ],
        'reducer_50' => [
            'name' => 'Réducteur',
            'description' => '-50% vs consommation initiale',
            'icon' => '🎯',
            'category' => 'reduction',
        ],
        'reducer_75' => [
            'name' => 'Presque libre',
            'description' => '-75% vs consommation initiale',
            'icon' => '🦅',
            'category' => 'reduction',
        ],
        'zero_day' => [
            'name' => 'Jour parfait',
            'description' => 'Une journée sans fumer',
            'icon' => '⭐',
            'category' => 'milestone',
        ],
        'champion' => [
            'name' => 'Champion',
            'description' => 'Objectif 0 atteint pendant 7 jours',
            'icon' => '🏆',
            'category' => 'milestone',
        ],
        'two_weeks' => [
            'name' => 'Deux semaines',
            'description' => '14 jours de streak',
            'icon' => '🌟',
            'category' => 'streak',
        ],
    ];

    public function __construct(
        private CigaretteRepository $cigaretteRepository,
        private SettingsRepository $settingsRepository,
        private UserBadgeRepository $userBadgeRepository,
        private EntityManagerInterface $entityManager,
        private ScoringService $scoringService,
        private Security $security
    ) {}

    private function getCurrentUser(): ?User
    {
        $user = $this->security->getUser();
        return $user instanceof User ? $user : null;
    }

    /**
     * Vérifie et attribue les nouveaux badges
     * @return string[] Codes des nouveaux badges obtenus
     */
    public function checkAndAwardBadges(): array
    {
        $user = $this->getCurrentUser();
        if (!$user) {
            return [];
        }

        $existingBadges = $this->userBadgeRepository->findUserBadgeCodes($user);
        $newBadges = [];

        // Vérifier chaque badge non encore obtenu
        foreach (self::BADGES as $code => $badge) {
            if (in_array($code, $existingBadges, true)) {
                continue;
            }

            if ($this->checkBadgeCondition($code)) {
                $this->awardBadge($user, $code);
                $newBadges[] = $code;
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
    private function checkBadgeCondition(string $code): bool
    {
        return match ($code) {
            'first_step' => $this->checkFirstStep(),
            'week_streak' => $this->checkStreakDays(7),
            'two_weeks' => $this->checkStreakDays(14),
            'month_streak' => $this->checkStreakDays(30),
            'saver_10' => $this->checkSavings(10),
            'saver_50' => $this->checkSavings(50),
            'saver_100' => $this->checkSavings(100),
            'reducer_25' => $this->checkReduction(25),
            'reducer_50' => $this->checkReduction(50),
            'reducer_75' => $this->checkReduction(75),
            'zero_day' => $this->checkZeroDay(),
            'champion' => $this->checkChampion(),
            default => false,
        };
    }

    /**
     * Premier jour complété (au moins 1 cigarette loggée)
     */
    private function checkFirstStep(): bool
    {
        $firstDate = $this->cigaretteRepository->getFirstCigaretteDate();
        return $firstDate !== null;
    }

    /**
     * Vérifie un streak de X jours
     */
    private function checkStreakDays(int $days): bool
    {
        $streak = $this->scoringService->getStreak();
        return $streak['current'] >= $days;
    }

    /**
     * Vérifie les économies réalisées
     */
    private function checkSavings(float $amount): bool
    {
        $savings = $this->calculateSavings();
        return $savings >= $amount;
    }

    private function calculateSavings(): float
    {
        $packPrice = (float) $this->settingsRepository->get('pack_price', '12.00');
        $cigsPerPack = (int) $this->settingsRepository->get('cigs_per_pack', '20');
        $initialDailyCigs = (int) $this->settingsRepository->get('initial_daily_cigs', '20');

        if ($cigsPerPack <= 0) {
            $cigsPerPack = 20;
        }

        $pricePerCig = $packPrice / $cigsPerPack;

        $firstDate = $this->cigaretteRepository->getFirstCigaretteDate();
        if (!$firstDate) {
            return 0;
        }

        $totalCigs = $this->cigaretteRepository->getTotalCount();
        $daysSinceStart = max(1, (new \DateTime())->diff($firstDate)->days + 1);

        $expectedCigs = $initialDailyCigs * $daysSinceStart;
        $cigsAvoided = max(0, $expectedCigs - $totalCigs);

        return $cigsAvoided * $pricePerCig;
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

        // Calculer la moyenne des 7 derniers jours
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
        $firstDate = $this->cigaretteRepository->getFirstCigaretteDate();
        if (!$firstDate) {
            return false;
        }

        // Vérifier chaque jour depuis le début (excluant aujourd'hui)
        $today = (new \DateTime())->setTime(0, 0, 0);
        $date = clone $firstDate;
        $date->setTime(0, 0, 0);

        while ($date < $today) {
            $count = $this->cigaretteRepository->countByDate($date);
            if ($count === 0) {
                return true;
            }
            $date->modify('+1 day');
        }

        return false;
    }

    /**
     * Vérifie si objectif 0 atteint pendant 7 jours consécutifs
     */
    private function checkChampion(): bool
    {
        $firstDate = $this->cigaretteRepository->getFirstCigaretteDate();
        if (!$firstDate) {
            return false;
        }

        // Compter les jours consécutifs à 0
        $today = (new \DateTime())->setTime(0, 0, 0);
        $date = (clone $today)->modify('-1 day'); // Commencer par hier
        $consecutiveZeroDays = 0;

        for ($i = 0; $i < 30; $i++) { // Max 30 jours en arrière
            if ($date < $firstDate) {
                break;
            }
            $count = $this->cigaretteRepository->countByDate($date);
            if ($count === 0) {
                $consecutiveZeroDays++;
            } else {
                break;
            }
            $date->modify('-1 day');
        }

        return $consecutiveZeroDays >= 7;
    }

    /**
     * Attribue un badge à un utilisateur
     */
    private function awardBadge(User $user, string $code): void
    {
        $badge = new UserBadge();
        $badge->setUser($user);
        $badge->setBadgeCode($code);
        $this->entityManager->persist($badge);
    }

    /**
     * Récupère tous les badges avec leur statut (obtenu ou non)
     * @return array<string, array{name: string, description: string, icon: string, category: string, unlocked: bool, unlocked_at: ?\DateTimeInterface}>
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
        foreach (self::BADGES as $code => $badge) {
            $result[$code] = [
                ...$badge,
                'unlocked' => isset($unlockedBadges[$code]),
                'unlocked_at' => $unlockedBadges[$code] ?? null,
            ];
        }

        return $result;
    }

    /**
     * Récupère uniquement les badges débloqués
     * @return array<string, array>
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
        return self::BADGES[$code] ?? null;
    }
}
