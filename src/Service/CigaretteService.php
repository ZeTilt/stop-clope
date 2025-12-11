<?php

namespace App\Service;

use App\Entity\Cigarette;
use App\Entity\User;
use App\Repository\CigaretteRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Service dédié à la gestion des cigarettes
 * Extrait de HomeController pour une meilleure maintenabilité
 */
class CigaretteService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private CigaretteRepository $cigaretteRepository,
        private ScoringService $scoringService,
        private BadgeService $badgeService
    ) {}

    /**
     * Valide et parse les données de temps envoyées par le client
     * @return array ['success' => bool, 'data' => [...] | 'error' => string]
     */
    public function parseTimeData(
        ?string $localTime,
        int $tzOffset,
        bool $isRetroactive = false
    ): array {
        // Validate local_time format
        if (!$localTime || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $localTime)) {
            return ['success' => false, 'error' => 'Invalid time format'];
        }

        // Validate timezone offset (reasonable range: -720 to +840 minutes)
        if ($tzOffset < -720 || $tzOffset > 840) {
            return ['success' => false, 'error' => 'Invalid timezone offset'];
        }

        // Créer le timezone à partir de l'offset
        $tzString = sprintf('%+03d:%02d', intdiv($tzOffset, 60), abs($tzOffset) % 60);
        try {
            $tz = new \DateTimeZone($tzString);
        } catch (\Exception $e) {
            return ['success' => false, 'error' => 'Invalid timezone'];
        }

        $smokedAt = \DateTime::createFromFormat('Y-m-d H:i', $localTime, $tz);
        if (!$smokedAt) {
            return ['success' => false, 'error' => 'Invalid datetime'];
        }

        // Prevent future dates (with 5 min tolerance)
        $now = new \DateTime();
        $now->modify('+5 minutes');
        if ($smokedAt > $now) {
            return ['success' => false, 'error' => 'Cannot log future cigarettes'];
        }

        return [
            'success' => true,
            'data' => [
                'smoked_at' => $smokedAt,
                'timezone' => $tz,
                'is_retroactive' => $isRetroactive,
            ],
        ];
    }

    /**
     * Enregistre une nouvelle cigarette
     * @return array Résultat avec infos sur la cigarette créée
     */
    public function logCigarette(User $user, \DateTime $smokedAt, bool $isRetroactive = false): array
    {
        $cigarette = new Cigarette();
        $cigarette->setSmokedAt($smokedAt);
        $cigarette->setIsRetroactive($isRetroactive);
        $cigarette->setUser($user);

        try {
            $this->entityManager->persist($cigarette);
            $this->entityManager->flush();
        } catch (\Exception $e) {
            return ['success' => false, 'error' => 'Database error'];
        }

        // Invalider le cache du scoring après mutation
        $this->scoringService->invalidateCache();

        // Persister le score du jour (optimisation performance)
        $today = new \DateTime();
        $this->scoringService->persistDailyScore($today);

        // Vérifier les nouveaux badges
        $newBadges = $this->badgeService->checkAndAwardBadges();

        return [
            'success' => true,
            'cigarette' => $cigarette,
            'new_badges' => $newBadges,
        ];
    }

    /**
     * Supprime une cigarette
     */
    public function deleteCigarette(Cigarette $cigarette, User $user): array
    {
        // Vérifier que la cigarette appartient à l'utilisateur
        if ($cigarette->getUser() !== $user) {
            return ['success' => false, 'error' => 'Access denied'];
        }

        try {
            $this->entityManager->remove($cigarette);
            $this->entityManager->flush();
        } catch (\Exception $e) {
            return ['success' => false, 'error' => 'Database error'];
        }

        // Invalider le cache du scoring après mutation
        $this->scoringService->invalidateCache();

        // Persister le score du jour (optimisation performance)
        $today = new \DateTime();
        $this->scoringService->persistDailyScore($today);

        return [
            'success' => true,
            'today_count' => $this->cigaretteRepository->countByDate($today),
        ];
    }

    /**
     * Récupère les infos complètes après un log pour la réponse JSON
     */
    public function getPostLogInfo(Cigarette $cigarette, array $newBadges, StreakService $streakService): array
    {
        $today = new \DateTime();
        $dailyScore = $this->scoringService->calculateDailyScore($today);
        $todayCount = $this->cigaretteRepository->countByDate($today);
        $streak = $this->scoringService->getStreak();

        // Infos badges
        $newBadgesInfo = [];
        foreach ($newBadges as $code) {
            $info = $this->badgeService->getBadgeInfo($code);
            if ($info) {
                $newBadgesInfo[] = [
                    'code' => $code,
                    'name' => $info['name'],
                    'icon' => $info['icon'],
                    'description' => $info['description'],
                ];
            }
        }

        // Prochain milestone de streak
        $nextMilestone = $streakService->getNextMilestone($streak['current']);

        return [
            'cigarette_id' => $cigarette->getId(),
            'smoked_at' => $cigarette->getSmokedAt()->format('H:i'),
            'is_retroactive' => $cigarette->isRetroactive(),
            'today_count' => $todayCount,
            'daily_score' => $dailyScore['total_score'],
            'streak' => $streak,
            'next_milestone' => $nextMilestone,
            'new_badges' => $newBadgesInfo,
        ];
    }
}
