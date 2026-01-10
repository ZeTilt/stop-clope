<?php

namespace App\Service;

use App\Entity\User;
use App\Repository\CigaretteRepository;
use App\Repository\DailyScoreRepository;
use App\Repository\SettingsRepository;
use App\Repository\UserBadgeRepository;
use App\Repository\UserStateRepository;
use App\Repository\WakeUpRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Service de gestion du reset de compte v2.0
 *
 * Permet à l'utilisateur de remettre son compte à zéro.
 * L'historique des resets est conservé pour le badge Phoenix.
 *
 * Données supprimées:
 * - Cigarettes
 * - WakeUp
 * - DailyScore
 * - UserBadge
 * - ActiveBonus
 * - UserState (recréé vide)
 *
 * Données conservées:
 * - Settings (prix paquet, etc.)
 * - Reset history
 */
class ResetService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private CigaretteRepository $cigaretteRepository,
        private WakeUpRepository $wakeUpRepository,
        private DailyScoreRepository $dailyScoreRepository,
        private UserBadgeRepository $userBadgeRepository,
        private UserStateRepository $userStateRepository,
        private SettingsRepository $settingsRepository,
        private Security $security
    ) {}

    /**
     * Récupère les stats avant reset pour l'historique
     */
    public function getPreResetStats(User $user): array
    {
        $userState = $this->userStateRepository->findByUser($user);
        $firstCig = $this->cigaretteRepository->getFirstCigaretteDate();

        $daysCount = 0;
        if ($firstCig) {
            $daysCount = (int) $firstCig->diff(new \DateTime())->days;
        }

        return [
            'date' => (new \DateTime())->format('Y-m-d H:i:s'),
            'total_score' => $userState?->getTotalScore() ?? 0,
            'days_count' => $daysCount,
            'rank' => $userState?->getCurrentRank() ?? 'fumeur',
            'badges_count' => count($this->userBadgeRepository->findUserBadgeCodes($user)),
            'shields_count' => $userState?->getShieldsCount() ?? 0,
            'permanent_multiplier' => $userState?->getPermanentMultiplier() ?? 0,
        ];
    }

    /**
     * Enregistre le reset dans l'historique
     */
    public function recordResetHistory(array $preResetStats): void
    {
        $history = $this->getResetHistory();
        $history[] = $preResetStats;
        $this->settingsRepository->set('reset_history', json_encode($history));
    }

    /**
     * Récupère l'historique des resets
     */
    public function getResetHistory(): array
    {
        $json = $this->settingsRepository->get('reset_history', '[]');
        return json_decode($json, true) ?? [];
    }

    /**
     * Exécute le reset complet du compte
     *
     * @param User $user L'utilisateur à réinitialiser
     * @return array Résultat du reset
     */
    public function executeReset(User $user): array
    {
        // Sauvegarder les stats pour l'historique
        $preResetStats = $this->getPreResetStats($user);
        $this->recordResetHistory($preResetStats);

        // Supprimer les données dans le bon ordre (contraintes FK)
        $this->deleteUserData($user);

        // Recréer un UserState vide
        $this->createFreshUserState($user);

        // Réinitialiser les compteurs
        $this->resetCounters();

        return [
            'success' => true,
            'message' => 'Compte réinitialisé avec succès',
            'previous_stats' => $preResetStats,
        ];
    }

    /**
     * Supprime toutes les données utilisateur
     */
    private function deleteUserData(User $user): void
    {
        // Supprimer les ActiveBonus
        $this->entityManager->createQuery(
            'DELETE FROM App\Entity\ActiveBonus ab WHERE ab.user = :user'
        )->execute(['user' => $user]);

        // Supprimer les UserBadge
        $this->entityManager->createQuery(
            'DELETE FROM App\Entity\UserBadge ub WHERE ub.user = :user'
        )->execute(['user' => $user]);

        // Supprimer les DailyScore
        $this->entityManager->createQuery(
            'DELETE FROM App\Entity\DailyScore ds WHERE ds.user = :user'
        )->execute(['user' => $user]);

        // Supprimer les Cigarettes
        $this->entityManager->createQuery(
            'DELETE FROM App\Entity\Cigarette c WHERE c.user = :user'
        )->execute(['user' => $user]);

        // Supprimer les WakeUp
        $this->entityManager->createQuery(
            'DELETE FROM App\Entity\WakeUp w WHERE w.user = :user'
        )->execute(['user' => $user]);

        // Supprimer le UserState
        $this->entityManager->createQuery(
            'DELETE FROM App\Entity\UserState us WHERE us.user = :user'
        )->execute(['user' => $user]);
    }

    /**
     * Crée un nouveau UserState vide
     */
    private function createFreshUserState(User $user): void
    {
        $userState = $this->userStateRepository->findOrCreateByUser($user);
        $userState->setTotalScore(0);
        $userState->setCurrentRank('fumeur');
        $userState->setShieldsCount(0);
        $userState->setPermanentMultiplier(0.0);
        $userState->setCurrentTargetInterval(null);

        $this->entityManager->flush();
    }

    /**
     * Réinitialise les compteurs (shields_used, stats_views)
     */
    private function resetCounters(): void
    {
        $this->settingsRepository->set('shields_used', '0');
        $this->settingsRepository->set('stats_views', '0');
    }

    /**
     * Vérifie si un reset a déjà été effectué (pour badge Phoenix)
     */
    public function hasResetHistory(): bool
    {
        return !empty($this->getResetHistory());
    }

    /**
     * Récupère le nombre de resets effectués
     */
    public function getResetCount(): int
    {
        return count($this->getResetHistory());
    }

    /**
     * Récupère le dernier reset
     */
    public function getLastReset(): ?array
    {
        $history = $this->getResetHistory();
        return !empty($history) ? end($history) : null;
    }
}
