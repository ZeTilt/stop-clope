<?php

namespace App\Controller;

use App\Entity\Cigarette;
use App\Entity\User;
use App\Entity\WakeUp;
use App\Repository\CigaretteRepository;
use App\Repository\DailyScoreRepository;
use App\Repository\SettingsRepository;
use App\Repository\WakeUpRepository;
use App\Service\BadgeService;
use App\Service\CigaretteService;
use App\Service\GoalService;
use App\Service\IntervalCalculator;
use App\Service\MaintenanceService;
use App\Service\MessageService;
use App\Service\RankProgressionService;
use App\Service\ResetService;
use App\Service\ScoringService;
use App\Service\ShieldService;
use App\Service\StatsService;
use App\Service\StreakService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Doctrine\DBAL\Exception as DBALException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

class HomeController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private CigaretteRepository $cigaretteRepository,
        private WakeUpRepository $wakeUpRepository,
        private SettingsRepository $settingsRepository,
        private DailyScoreRepository $dailyScoreRepository,
        private ScoringService $scoringService,
        private BadgeService $badgeService,
        private MessageService $messageService,
        private GoalService $goalService,
        private StatsService $statsService,
        private StreakService $streakService,
        private IntervalCalculator $intervalCalculator,
        private CigaretteService $cigaretteService,
        private CsrfTokenManagerInterface $csrfTokenManager,
        private LoggerInterface $logger,
        private RankProgressionService $rankProgressionService,
        private MaintenanceService $maintenanceService,
        private ShieldService $shieldService,
        private ResetService $resetService
    ) {}

    private function validateCsrfToken(Request $request): bool
    {
        $token = $request->headers->get('X-CSRF-Token');
        if (!$token) {
            return false;
        }
        return $this->csrfTokenManager->isTokenValid(new CsrfToken('ajax', $token));
    }

    #[Route('/', name: 'app_home')]
    public function index(): Response
    {
        $today = new \DateTime();

        // Finaliser le score d'hier si besoin (pour attribuer les bonus potentiels)
        $yesterday = (clone $today)->modify('-1 day');
        $this->scoringService->persistDailyScore($yesterday);

        $todayCigs = $this->cigaretteRepository->findTodayCigarettes();
        $yesterdayCigs = $this->cigaretteRepository->findYesterdayCigarettes();
        $todayWakeUp = $this->wakeUpRepository->findTodayWakeUp();
        $yesterdayWakeUp = $this->wakeUpRepository->findYesterdayWakeUp();
        $dailyScore = $this->scoringService->calculateDailyScore($today);
        $rank = $this->scoringService->getCurrentRank();
        $stats = $this->cigaretteRepository->getDailyStats(7);
        $streak = $this->streakService->getStreakInfo(); // v2.0: infos complètes

        /** @var \App\Entity\User|null $user */
        $user = $this->getUser();

        // v2.0: Info de progression de rang
        $progressionInfo = $user ? $this->rankProgressionService->getProgressionInfo($user) : null;

        // v2.0: Info jour maintenance
        $maintenanceInfo = $this->maintenanceService->getWeeklyMaintenanceInfo($today);

        // v2.0: Info boucliers
        $shieldInfo = $user ? $this->shieldService->getShieldInfo($user) : null;

        // Calculer le compte à rebours pour la prochaine clope (via ScoringService)
        $nextCigTarget = $this->scoringService->getNextCigaretteInfo($today);

        // Message d'encouragement contextuel (avec heures de réveil pour comparaison relative)
        $encouragement = $this->messageService->getEncouragementMessage(
            $todayCigs,
            $yesterdayCigs,
            $dailyScore,
            $todayWakeUp,
            $yesterdayWakeUp
        );

        // Objectif quotidien (personnalisé ou palier automatique)
        $goalProgress = $this->goalService->getDailyProgress();

        // Vérifier si un nouveau palier est atteint (pour célébration)
        $tierAchievement = $this->goalService->checkTierAchievement();

        // Vérifier si c'est la première journée réussie
        $firstDaySuccess = $this->goalService->checkFirstSuccessfulDay();

        return $this->render('home/index.html.twig', [
            'today_cigarettes' => $todayCigs,
            'yesterday_count' => count($yesterdayCigs),
            'today_wakeup' => $todayWakeUp,
            'daily_score' => $dailyScore,
            'rank' => $rank,
            'weekly_stats' => $stats,
            'next_cig_target' => $nextCigTarget,
            'encouragement' => $encouragement,
            'streak' => $streak,
            'show_wakeup_modal' => $todayWakeUp === null,
            'goal_progress' => $goalProgress,
            'tier_achievement' => $tierAchievement,
            'first_day_success' => $firstDaySuccess,
            'progression_info' => $progressionInfo,
            'maintenance_info' => $maintenanceInfo,
            'shield_info' => $shieldInfo,
        ]);
    }

    #[Route('/log', name: 'app_log_cigarette', methods: ['POST'])]
    public function logCigarette(Request $request): JsonResponse
    {
        // Validate CSRF token
        if (!$this->validateCsrfToken($request)) {
            return new JsonResponse(['success' => false, 'error' => 'Invalid CSRF token'], 403);
        }

        // Le client envoie l'heure locale + son décalage timezone
        $localTime = $request->request->get('local_time');
        $tzOffset = $request->request->getInt('tz_offset', 0);
        $isRetroactive = $request->request->getBoolean('is_retroactive', false);

        // Utiliser CigaretteService pour parser et valider les données
        $parseResult = $this->cigaretteService->parseTimeData($localTime, $tzOffset, $isRetroactive);
        if (!$parseResult['success']) {
            return new JsonResponse(['success' => false, 'error' => $parseResult['error']], 400);
        }

        /** @var User $user */
        $user = $this->getUser();

        // Utiliser CigaretteService pour enregistrer la cigarette
        $logResult = $this->cigaretteService->logCigarette(
            $user,
            $parseResult['data']['smoked_at'],
            $parseResult['data']['is_retroactive']
        );

        if (!$logResult['success']) {
            $this->logger->error('Failed to persist cigarette', ['error' => $logResult['error']]);
            return new JsonResponse(['success' => false, 'error' => $logResult['error']], 500);
        }

        // Récupérer les infos post-log via le service
        $postLogInfo = $this->cigaretteService->getPostLogInfo(
            $logResult['cigarette'],
            $logResult['new_badges'],
            $this->streakService
        );

        return new JsonResponse(array_merge(['success' => true], $postLogInfo));
    }

    #[Route('/wakeup', name: 'app_log_wakeup', methods: ['POST'])]
    public function logWakeUp(Request $request): JsonResponse
    {
        // Validate CSRF token
        if (!$this->validateCsrfToken($request)) {
            return new JsonResponse(['success' => false, 'error' => 'Invalid CSRF token'], 403);
        }

        // Le client envoie l'heure locale + son décalage timezone
        $wakeTimeStr = $request->request->get('wake_time');
        $tzOffset = $request->request->getInt('tz_offset', 0);

        // Validate wake_time format
        if ($wakeTimeStr && !preg_match('/^\d{2}:\d{2}$/', $wakeTimeStr)) {
            return new JsonResponse(['success' => false, 'error' => 'Invalid time format'], 400);
        }

        // Validate timezone offset
        if ($tzOffset < -720 || $tzOffset > 840) {
            return new JsonResponse(['success' => false, 'error' => 'Invalid timezone offset'], 400);
        }

        // Créer le timezone à partir de l'offset
        $tzString = sprintf('%+03d:%02d', intdiv($tzOffset, 60), abs($tzOffset) % 60);
        try {
            $tz = new \DateTimeZone($tzString);
        } catch (\DateInvalidTimeZoneException|\ValueError $e) {
            $this->logger->warning('Invalid timezone string', ['tz' => $tzString, 'error' => $e->getMessage()]);
            return new JsonResponse(['success' => false, 'error' => 'Invalid timezone'], 400);
        }

        $today = new \DateTime('now', $tz);
        $today->setTime(0, 0, 0);

        // Chercher si un réveil existe déjà pour aujourd'hui
        $wakeUp = $this->wakeUpRepository->findByDate($today);

        if (!$wakeUp) {
            $wakeUp = new WakeUp();
            $wakeUp->setDate($today);
            /** @var User $user */
            $user = $this->getUser();
            $wakeUp->setUser($user);
        }

        if ($wakeTimeStr) {
            // Créer un DateTime avec l'heure dans le bon timezone
            $wakeTime = \DateTime::createFromFormat('H:i', $wakeTimeStr, $tz);
            if (!$wakeTime) {
                return new JsonResponse(['success' => false, 'error' => 'Invalid time'], 400);
            }
        } else {
            $wakeTime = new \DateTime('now', $tz);
        }

        $wakeUp->setWakeTime($wakeTime);

        try {
            $this->entityManager->persist($wakeUp);
            $this->entityManager->flush();
        } catch (DBALException $e) {
            $this->logger->error('Database error persisting wakeup', ['exception' => $e->getMessage()]);
            return new JsonResponse(['success' => false, 'error' => 'Database error'], 500);
        }

        return new JsonResponse([
            'success' => true,
            'wake_time' => $wakeUp->getWakeTime()->format('H:i'),
        ]);
    }

    #[Route('/delete/{id}', name: 'app_delete_cigarette', methods: ['POST'])]
    public function deleteCigarette(Cigarette $cigarette, Request $request): JsonResponse
    {
        // Validate CSRF token
        if (!$this->validateCsrfToken($request)) {
            return new JsonResponse(['success' => false, 'error' => 'Invalid CSRF token'], 403);
        }

        // Fix IDOR: vérifier que la cigarette appartient à l'utilisateur connecté
        /** @var User $user */
        $user = $this->getUser();
        if ($cigarette->getUser() !== $user) {
            return new JsonResponse(['success' => false, 'error' => 'Access denied'], 403);
        }

        try {
            $this->entityManager->remove($cigarette);
            $this->entityManager->flush();
        } catch (DBALException $e) {
            $this->logger->error('Database error deleting cigarette', ['exception' => $e->getMessage(), 'id' => $cigarette->getId()]);
            return new JsonResponse(['success' => false, 'error' => 'Database error'], 500);
        }

        // Invalider le cache après mutation
        $this->scoringService->invalidateCache();
        $this->statsService->invalidateCache();

        // Persister le score du jour (optimisation performance)
        $today = new \DateTime();
        $this->scoringService->persistDailyScore($today);

        $todayCount = $this->cigaretteRepository->countByDate($today);

        return new JsonResponse([
            'success' => true,
            'today_count' => $todayCount,
        ]);
    }

    #[Route('/stats', name: 'app_stats')]
    public function stats(): Response
    {
        // v2.0: Incrémenter le compteur de vues (pour badge Analyste)
        $this->badgeService->incrementStatsViews();

        // Utiliser le StatsService pour récupérer toutes les stats
        $fullStats = $this->statsService->getFullStats();
        $rank = $this->scoringService->getCurrentRank();

        // Vérifier et attribuer les nouveaux badges
        $this->badgeService->checkAndAwardBadges();
        $badges = $this->badgeService->getAllBadgesWithStatus();

        // v2.0: Récupérer infos bonus et boucliers
        /** @var \App\Entity\User|null $user */
        $user = $this->getUser();

        $shieldInfo = $user ? $this->shieldService->getShieldInfo($user) : null;
        $activeBonuses = $this->badgeService->getActiveBonuses();
        $permanentMultiplier = $this->badgeService->getPermanentMultiplier();

        return $this->render('home/stats.html.twig', [
            'monthly_stats' => $fullStats['monthly_stats'],
            'weekly_scores' => $fullStats['weekly_scores'],
            'rank' => $rank,
            'weekday_stats' => $fullStats['weekday_stats'],
            'hourly_stats' => $fullStats['hourly_stats'],
            'daily_intervals' => $fullStats['daily_intervals'],
            'weekly_comparison' => $fullStats['weekly_comparison'],
            'savings' => $fullStats['savings'],
            'first_date' => $fullStats['first_date'],
            'badges' => $badges,
            'badges_by_category' => $this->badgeService->getBadgesByCategory(),
            'shield_info' => $shieldInfo,
            'active_bonuses' => $activeBonuses,
            'permanent_multiplier' => $permanentMultiplier,
        ]);
    }

    #[Route('/settings', name: 'app_settings')]
    public function settings(): Response
    {
        $goalInfo = $this->goalService->getProgressInfo();

        return $this->render('home/settings.html.twig', [
            'pack_price' => $this->settingsRepository->get('pack_price', '12.00'),
            'cigs_per_pack' => $this->settingsRepository->get('cigs_per_pack', '20'),
            'initial_daily_cigs' => $this->settingsRepository->get('initial_daily_cigs', '20'),
            'goal_info' => $goalInfo,
        ]);
    }

    #[Route('/settings/save', name: 'app_settings_save', methods: ['POST'])]
    public function saveSettings(Request $request): JsonResponse
    {
        // Validate CSRF token
        if (!$this->validateCsrfToken($request)) {
            return new JsonResponse(['success' => false, 'error' => 'Invalid CSRF token'], 403);
        }

        $packPrice = $request->request->get('pack_price');
        $cigsPerPack = $request->request->get('cigs_per_pack');
        $initialDailyCigs = $request->request->get('initial_daily_cigs');

        // Validate pack_price (positive number)
        if ($packPrice !== null && $packPrice !== '') {
            if (!is_numeric($packPrice) || (float) $packPrice <= 0 || (float) $packPrice > 100) {
                return new JsonResponse(['success' => false, 'error' => 'Invalid pack price'], 400);
            }
            $this->settingsRepository->set('pack_price', (string) round((float) $packPrice, 2));
        }

        // Validate cigs_per_pack (positive integer)
        if ($cigsPerPack !== null && $cigsPerPack !== '') {
            if (!is_numeric($cigsPerPack) || (int) $cigsPerPack <= 0 || (int) $cigsPerPack > 100) {
                return new JsonResponse(['success' => false, 'error' => 'Invalid cigarettes per pack'], 400);
            }
            $this->settingsRepository->set('cigs_per_pack', (string) (int) $cigsPerPack);
        }

        // Validate initial_daily_cigs (positive integer)
        if ($initialDailyCigs !== null && $initialDailyCigs !== '') {
            if (!is_numeric($initialDailyCigs) || (int) $initialDailyCigs <= 0 || (int) $initialDailyCigs > 100) {
                return new JsonResponse(['success' => false, 'error' => 'Invalid initial daily cigarettes'], 400);
            }
            $this->settingsRepository->set('initial_daily_cigs', (string) (int) $initialDailyCigs);
        }

        return new JsonResponse(['success' => true]);
    }

    #[Route('/settings/reset', name: 'app_settings_reset', methods: ['POST'])]
    public function resetAccount(Request $request): JsonResponse
    {
        // Validate CSRF token
        if (!$this->validateCsrfToken($request)) {
            return new JsonResponse(['success' => false, 'error' => 'Invalid CSRF token'], 403);
        }

        // Validate confirmation code
        $confirmationCode = $request->request->get('confirmation_code');
        if ($confirmationCode !== 'RESET') {
            return new JsonResponse([
                'success' => false,
                'error' => 'Code de confirmation incorrect. Tapez RESET pour confirmer.',
            ], 400);
        }

        /** @var User $user */
        $user = $this->getUser();

        try {
            $result = $this->resetService->executeReset($user);

            // Invalider les caches
            $this->scoringService->invalidateCache();
            $this->statsService->invalidateCache();

            return new JsonResponse($result);
        } catch (\Exception $e) {
            $this->logger->error('Reset failed', ['error' => $e->getMessage()]);
            return new JsonResponse([
                'success' => false,
                'error' => 'Erreur lors de la réinitialisation',
            ], 500);
        }
    }

    #[Route('/settings/reset-info', name: 'app_settings_reset_info', methods: ['GET'])]
    public function resetInfo(): JsonResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();

        if (!$user) {
            return new JsonResponse(['success' => false], 401);
        }

        return new JsonResponse([
            'success' => true,
            'reset_count' => $this->resetService->getResetCount(),
            'has_history' => $this->resetService->hasResetHistory(),
            'last_reset' => $this->resetService->getLastReset(),
            'pre_reset_stats' => $this->resetService->getPreResetStats($user),
        ]);
    }

    #[Route('/settings/recalculate-goal', name: 'app_settings_recalculate_goal', methods: ['POST'])]
    public function recalculateGoal(Request $request): JsonResponse
    {
        if (!$this->validateCsrfToken($request)) {
            return new JsonResponse(['success' => false, 'error' => 'Invalid CSRF token'], 403);
        }

        // Supprimer les settings de palier pour forcer le recalcul
        $this->settingsRepository->delete('current_auto_tier');
        $this->settingsRepository->delete('previous_displayed_tier');
        $this->settingsRepository->delete('first_day_celebrated');

        // Récupérer le nouveau palier calculé
        $goalInfo = $this->goalService->getProgressInfo();

        return new JsonResponse([
            'success' => true,
            'message' => 'Objectif recalculé',
            'new_tier' => $goalInfo['tier']['current_tier'],
        ]);
    }

    #[Route('/onboarding', name: 'app_onboarding')]
    public function onboarding(): Response
    {
        return $this->render('home/onboarding.html.twig', [
            'initial_daily_cigs' => $this->settingsRepository->get('initial_daily_cigs', '15'),
            'pack_price' => $this->settingsRepository->get('pack_price', '12.00'),
        ]);
    }

    #[Route('/history', name: 'app_history')]
    public function history(Request $request): Response
    {
        $firstDate = $this->cigaretteRepository->getFirstCigaretteDate();

        // Pas de données = redirection vers l'accueil
        if (!$firstDate) {
            return $this->redirectToRoute('app_home');
        }

        $date = $request->query->get('date');
        if ($date) {
            $selectedDate = \DateTime::createFromFormat('Y-m-d', $date);
            // Fix: valider le retour de createFromFormat
            if (!$selectedDate) {
                return $this->redirectToRoute('app_history');
            }
        } else {
            $selectedDate = new \DateTime();
        }

        // Empêcher d'aller avant le premier jour
        $firstDateNormalized = (clone $firstDate)->setTime(0, 0, 0);
        $selectedDateNormalized = (clone $selectedDate)->setTime(0, 0, 0);
        if ($selectedDateNormalized < $firstDateNormalized) {
            return $this->redirectToRoute('app_history', ['date' => $firstDate->format('Y-m-d')]);
        }

        $cigarettes = $this->cigaretteRepository->findByDate($selectedDate);
        $wakeUp = $this->wakeUpRepository->findByDate($selectedDate);

        // Calculer les détails du score (comparisons pour l'affichage)
        $dailyScoreData = $this->scoringService->calculateDailyScore($selectedDate);

        // Pour les jours passés, utiliser le score persisté (qui inclut les bonus)
        $today = new \DateTime();
        $today->setTime(0, 0, 0);
        $selectedDateNormalized->setTime(0, 0, 0);

        if ($selectedDateNormalized < $today) {
            // Jour passé : récupérer le score persisté
            $persistedScore = $this->dailyScoreRepository->findByDate($selectedDate);
            if ($persistedScore) {
                $dailyScoreData['total_score'] = $persistedScore->getScore();
            }
        }

        // Calcul des intervalles (cible et réel)
        $intervalInfo = $this->intervalCalculator->getTargetIntervalInfo($selectedDate);
        $firstCigInfo = $this->intervalCalculator->getFirstCigTargetInfo($selectedDate);

        return $this->render('home/history.html.twig', [
            'selected_date' => $selectedDate,
            'cigarettes' => $cigarettes,
            'wakeup' => $wakeUp,
            'daily_score' => $dailyScoreData,
            'first_date' => $firstDate,
            'average_interval' => $intervalInfo['actual'],
            'target_interval' => $intervalInfo['target'],
            'interval_info' => $intervalInfo,
            'first_cig_info' => $firstCigInfo,
        ]);
    }
}
