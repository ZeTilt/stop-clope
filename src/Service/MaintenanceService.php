<?php

namespace App\Service;

use App\Entity\DailyScore;
use App\Entity\User;
use App\Repository\DailyScoreRepository;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Service gérant les jours de maintenance v2.0
 *
 * Règles:
 * - 1 jour de maintenance par semaine calendaire (lundi-dimanche)
 * - Le jour maintenance ne compte pas d'augmentation d'intervalle (+0 au lieu de +1)
 * - Les jours non utilisés ne se cumulent pas
 */
class MaintenanceService
{
    public function __construct(
        private DailyScoreRepository $dailyScoreRepository,
        private Security $security
    ) {}

    private function getCurrentUser(): ?User
    {
        $user = $this->security->getUser();
        return $user instanceof User ? $user : null;
    }

    /**
     * Vérifie si l'utilisateur peut utiliser un jour maintenance aujourd'hui
     *
     * @param \DateTimeInterface|null $date Date à vérifier (défaut: aujourd'hui)
     * @return bool True si disponible
     */
    public function canUseMaintenanceDay(?\DateTimeInterface $date = null): bool
    {
        $user = $this->getCurrentUser();
        if (!$user) {
            return false;
        }

        $date = $date ?? new \DateTime();

        // Vérifier si déjà utilisé cette semaine
        return !$this->hasUsedMaintenanceDayThisWeek($user, $date);
    }

    /**
     * Vérifie si un jour maintenance a été utilisé cette semaine calendaire
     *
     * @param User $user L'utilisateur
     * @param \DateTimeInterface $date Date de référence
     * @return bool True si déjà utilisé
     */
    public function hasUsedMaintenanceDayThisWeek(User $user, \DateTimeInterface $date): bool
    {
        $weekStart = $this->getWeekStart($date);
        $weekEnd = $this->getWeekEnd($date);

        return $this->dailyScoreRepository->hasMaintenanceDayInRange($user, $weekStart, $weekEnd);
    }

    /**
     * Active le jour maintenance pour aujourd'hui
     *
     * @param \DateTimeInterface|null $date Date à activer (défaut: aujourd'hui)
     * @return array Résultat de l'opération
     */
    public function activateMaintenanceDay(?\DateTimeInterface $date = null): array
    {
        $user = $this->getCurrentUser();
        if (!$user) {
            return [
                'success' => false,
                'error' => 'Utilisateur non connecté',
            ];
        }

        $date = $date ?? new \DateTime();

        // Vérifier disponibilité
        if (!$this->canUseMaintenanceDay($date)) {
            return [
                'success' => false,
                'error' => 'Jour de maintenance déjà utilisé cette semaine',
            ];
        }

        // Marquer le jour comme maintenance
        $dailyScore = $this->dailyScoreRepository->findByUserAndDate($user, $date);

        if (!$dailyScore) {
            // Créer un DailyScore vide pour marquer le jour maintenance
            $dailyScore = new DailyScore();
            $dailyScore->setUser($user);
            $dailyScore->setDate((clone $date)->setTime(0, 0, 0));
            $dailyScore->setScore(0);
            $dailyScore->setCigaretteCount(0);
            $dailyScore->setStreak(0);
        }

        $dailyScore->setIsMaintenanceDay(true);
        $this->dailyScoreRepository->upsert($dailyScore);

        return [
            'success' => true,
            'date' => $date->format('Y-m-d'),
            'message' => 'Jour de maintenance activé',
        ];
    }

    /**
     * Désactive le jour maintenance (annulation)
     *
     * @param \DateTimeInterface|null $date Date à désactiver
     * @return array Résultat de l'opération
     */
    public function deactivateMaintenanceDay(?\DateTimeInterface $date = null): array
    {
        $user = $this->getCurrentUser();
        if (!$user) {
            return [
                'success' => false,
                'error' => 'Utilisateur non connecté',
            ];
        }

        $date = $date ?? new \DateTime();
        $dailyScore = $this->dailyScoreRepository->findByUserAndDate($user, $date);

        if (!$dailyScore || !$dailyScore->isMaintenanceDay()) {
            return [
                'success' => false,
                'error' => 'Ce jour n\'est pas un jour de maintenance',
            ];
        }

        $dailyScore->setIsMaintenanceDay(false);
        $this->dailyScoreRepository->upsert($dailyScore);

        return [
            'success' => true,
            'date' => $date->format('Y-m-d'),
            'message' => 'Jour de maintenance désactivé',
        ];
    }

    /**
     * Récupère les infos de maintenance pour la semaine en cours
     *
     * @param \DateTimeInterface|null $date Date de référence
     * @return array Infos de la semaine
     */
    public function getWeeklyMaintenanceInfo(?\DateTimeInterface $date = null): array
    {
        $user = $this->getCurrentUser();
        $date = $date ?? new \DateTime();

        $weekStart = $this->getWeekStart($date);
        $weekEnd = $this->getWeekEnd($date);

        $hasUsed = false;
        $usedDate = null;

        if ($user) {
            $hasUsed = $this->hasUsedMaintenanceDayThisWeek($user, $date);
            if ($hasUsed) {
                $usedDate = $this->dailyScoreRepository->getMaintenanceDayInRange($user, $weekStart, $weekEnd);
            }
        }

        return [
            'week_start' => $weekStart->format('Y-m-d'),
            'week_end' => $weekEnd->format('Y-m-d'),
            'available' => !$hasUsed,
            'used' => $hasUsed,
            'used_date' => $usedDate?->format('Y-m-d'),
            'today_is_maintenance' => $this->isTodayMaintenanceDay($date),
        ];
    }

    /**
     * Vérifie si aujourd'hui est un jour de maintenance
     */
    public function isTodayMaintenanceDay(?\DateTimeInterface $date = null): bool
    {
        $user = $this->getCurrentUser();
        if (!$user) {
            return false;
        }

        $date = $date ?? new \DateTime();
        $dailyScore = $this->dailyScoreRepository->findByUserAndDate($user, $date);

        return $dailyScore?->isMaintenanceDay() ?? false;
    }

    /**
     * Retourne le début de la semaine calendaire (lundi)
     */
    private function getWeekStart(\DateTimeInterface $date): \DateTimeInterface
    {
        $weekStart = clone $date;
        $dayOfWeek = (int) $weekStart->format('N'); // 1 = lundi, 7 = dimanche

        if ($dayOfWeek > 1) {
            $weekStart = $weekStart->modify('-' . ($dayOfWeek - 1) . ' days');
        }

        return $weekStart->setTime(0, 0, 0);
    }

    /**
     * Retourne la fin de la semaine calendaire (dimanche)
     */
    private function getWeekEnd(\DateTimeInterface $date): \DateTimeInterface
    {
        $weekEnd = clone $date;
        $dayOfWeek = (int) $weekEnd->format('N');

        if ($dayOfWeek < 7) {
            $weekEnd = $weekEnd->modify('+' . (7 - $dayOfWeek) . ' days');
        }

        return $weekEnd->setTime(23, 59, 59);
    }
}
