<?php

namespace App\Service;

use App\Entity\User;
use App\Repository\SettingsRepository;
use App\Repository\UserStateRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Service de gestion des boucliers v2.0
 *
 * Un bouclier permet d'annuler une zone négative:
 * - Points négatifs deviennent 0
 * - La séquence (streak) est préservée
 * - Le bouclier est consommé
 *
 * Obtention de boucliers:
 * - Certains badges donnent des boucliers
 * - Rang Sage (200k pts) donne un bouclier mensuel
 */
class ShieldService
{
    public function __construct(
        private UserStateRepository $userStateRepository,
        private SettingsRepository $settingsRepository,
        private EntityManagerInterface $entityManager
    ) {}

    /**
     * Vérifie si l'utilisateur peut utiliser un bouclier
     */
    public function canUseShield(User $user): bool
    {
        $userState = $this->userStateRepository->findByUser($user);
        return $userState !== null && $userState->getShieldsCount() > 0;
    }

    /**
     * Récupère le nombre de boucliers disponibles
     */
    public function getAvailableShields(User $user): int
    {
        $userState = $this->userStateRepository->findByUser($user);
        return $userState?->getShieldsCount() ?? 0;
    }

    /**
     * Utilise un bouclier pour annuler les points négatifs
     *
     * @param User $user L'utilisateur
     * @return array Résultat de l'utilisation
     */
    public function useShield(User $user): array
    {
        $userState = $this->userStateRepository->findOrCreateByUser($user);

        if ($userState->getShieldsCount() <= 0) {
            return [
                'success' => false,
                'error' => 'Aucun bouclier disponible',
                'shields_remaining' => 0,
            ];
        }

        // Utiliser le bouclier
        $userState->useShield();
        $this->entityManager->flush();

        // Incrémenter le compteur de boucliers utilisés (pour badges)
        $this->incrementShieldsUsed();

        return [
            'success' => true,
            'shields_remaining' => $userState->getShieldsCount(),
            'message' => 'Bouclier utilisé ! Points négatifs annulés.',
        ];
    }

    /**
     * Ajoute des boucliers à l'utilisateur
     */
    public function addShields(User $user, int $count): void
    {
        $userState = $this->userStateRepository->findOrCreateByUser($user);

        for ($i = 0; $i < $count; $i++) {
            $userState->addShield();
        }

        $this->entityManager->flush();
    }

    /**
     * Incrémente le compteur de boucliers utilisés (pour badges)
     */
    private function incrementShieldsUsed(): void
    {
        $current = (int) $this->settingsRepository->get('shields_used', '0');
        $this->settingsRepository->set('shields_used', (string) ($current + 1));
    }

    /**
     * Récupère le nombre total de boucliers utilisés
     */
    public function getTotalShieldsUsed(): int
    {
        return (int) $this->settingsRepository->get('shields_used', '0');
    }

    /**
     * Vérifie si l'utilisateur a droit au bouclier mensuel (rang Sage)
     */
    public function hasMonthlyShieldAvailable(User $user): bool
    {
        $userState = $this->userStateRepository->findByUser($user);
        if (!$userState) {
            return false;
        }

        // Seul le rang Sage (200k pts) et au-dessus donne le bouclier mensuel
        if ($userState->getTotalScore() < 200000) {
            return false;
        }

        // Vérifier si le bouclier mensuel a déjà été réclamé ce mois
        $lastClaimed = $this->settingsRepository->get('monthly_shield_claimed');
        if (!$lastClaimed) {
            return true;
        }

        $lastClaimedDate = new \DateTime($lastClaimed);
        $now = new \DateTime();

        // Le bouclier est disponible si on est dans un nouveau mois
        return $lastClaimedDate->format('Y-m') !== $now->format('Y-m');
    }

    /**
     * Réclame le bouclier mensuel
     */
    public function claimMonthlyShield(User $user): array
    {
        if (!$this->hasMonthlyShieldAvailable($user)) {
            return [
                'success' => false,
                'error' => 'Bouclier mensuel non disponible',
            ];
        }

        $this->addShields($user, 1);
        $this->settingsRepository->set('monthly_shield_claimed', (new \DateTime())->format('Y-m-d'));

        $userState = $this->userStateRepository->findByUser($user);

        return [
            'success' => true,
            'shields_total' => $userState?->getShieldsCount() ?? 1,
            'message' => 'Bouclier mensuel réclamé !',
        ];
    }

    /**
     * Récupère les infos complètes sur les boucliers
     */
    public function getShieldInfo(User $user): array
    {
        $userState = $this->userStateRepository->findByUser($user);

        return [
            'available' => $userState?->getShieldsCount() ?? 0,
            'can_use' => $this->canUseShield($user),
            'monthly_available' => $this->hasMonthlyShieldAvailable($user),
            'total_used' => $this->getTotalShieldsUsed(),
        ];
    }
}
