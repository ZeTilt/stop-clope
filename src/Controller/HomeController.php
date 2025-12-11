<?php

namespace App\Controller;

use App\Entity\Cigarette;
use App\Entity\User;
use App\Entity\WakeUp;
use App\Repository\CigaretteRepository;
use App\Repository\SettingsRepository;
use App\Repository\WakeUpRepository;
use App\Service\BadgeService;
use App\Service\GoalService;
use App\Service\MessageService;
use App\Service\ScoringService;
use App\Service\StatsService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
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
        private ScoringService $scoringService,
        private BadgeService $badgeService,
        private MessageService $messageService,
        private GoalService $goalService,
        private StatsService $statsService,
        private CsrfTokenManagerInterface $csrfTokenManager
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
        $todayCigs = $this->cigaretteRepository->findTodayCigarettes();
        $yesterdayCigs = $this->cigaretteRepository->findYesterdayCigarettes();
        $todayWakeUp = $this->wakeUpRepository->findTodayWakeUp();
        $yesterdayWakeUp = $this->wakeUpRepository->findYesterdayWakeUp();
        $dailyScore = $this->scoringService->calculateDailyScore($today);
        $rank = $this->scoringService->getCurrentRank();
        $stats = $this->cigaretteRepository->getDailyStats(7);
        $streak = $this->scoringService->getStreak();

        // Calculer le compte à rebours pour la prochaine clope (via ScoringService)
        $nextCigTarget = $this->scoringService->getNextCigaretteInfo($today);

        // Message d'encouragement contextuel
        $encouragement = $this->messageService->getEncouragementMessage($todayCigs, $yesterdayCigs, $dailyScore);

        // Objectif quotidien (personnalisé ou palier automatique)
        $goalProgress = $this->goalService->getDailyProgress();

        // Vérifier si un nouveau palier est atteint (pour célébration)
        $tierAchievement = $this->goalService->checkTierAchievement();

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
        ]);
    }

    #[Route('/log', name: 'app_log_cigarette', methods: ['POST'])]
    public function logCigarette(Request $request): JsonResponse
    {
        // Validate CSRF token
        if (!$this->validateCsrfToken($request)) {
            return new JsonResponse(['success' => false, 'error' => 'Invalid CSRF token'], 403);
        }

        $cigarette = new Cigarette();

        // Le client envoie l'heure locale + son décalage timezone
        $localTime = $request->request->get('local_time');
        $tzOffset = $request->request->getInt('tz_offset', 0); // En minutes, positif pour Est
        $isRetroactive = $request->request->getBoolean('is_retroactive', false);

        // Validate local_time format
        if (!$localTime || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $localTime)) {
            return new JsonResponse(['success' => false, 'error' => 'Invalid time format'], 400);
        }

        // Validate timezone offset (reasonable range: -720 to +840 minutes)
        if ($tzOffset < -720 || $tzOffset > 840) {
            return new JsonResponse(['success' => false, 'error' => 'Invalid timezone offset'], 400);
        }

        // Créer le timezone à partir de l'offset
        $tzString = sprintf('%+03d:%02d', intdiv($tzOffset, 60), abs($tzOffset) % 60);
        try {
            $tz = new \DateTimeZone($tzString);
        } catch (\Exception $e) {
            return new JsonResponse(['success' => false, 'error' => 'Invalid timezone'], 400);
        }

        $smokedAt = \DateTime::createFromFormat('Y-m-d H:i', $localTime, $tz);
        if (!$smokedAt) {
            return new JsonResponse(['success' => false, 'error' => 'Invalid datetime'], 400);
        }

        // Prevent future dates (with 5 min tolerance)
        $now = new \DateTime();
        $now->modify('+5 minutes');
        if ($smokedAt > $now) {
            return new JsonResponse(['success' => false, 'error' => 'Cannot log future cigarettes'], 400);
        }

        $cigarette->setSmokedAt($smokedAt);
        $cigarette->setIsRetroactive($isRetroactive);

        /** @var User $user */
        $user = $this->getUser();
        $cigarette->setUser($user);

        try {
            $this->entityManager->persist($cigarette);
            $this->entityManager->flush();
        } catch (\Exception $e) {
            return new JsonResponse(['success' => false, 'error' => 'Database error'], 500);
        }

        // Invalider le cache du scoring après mutation
        $this->scoringService->invalidateCache();

        // Persister le score du jour (optimisation performance)
        $today = new \DateTime();
        $this->scoringService->persistDailyScore($today);

        $dailyScore = $this->scoringService->calculateDailyScore($today);
        $todayCount = $this->cigaretteRepository->countByDate($today);
        $streak = $this->scoringService->getStreak();

        // Vérifier les nouveaux badges
        $newBadges = $this->badgeService->checkAndAwardBadges();
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

        return new JsonResponse([
            'success' => true,
            'cigarette_id' => $cigarette->getId(),
            'smoked_at' => $cigarette->getSmokedAt()->format('H:i'),
            'is_retroactive' => $cigarette->isRetroactive(),
            'today_count' => $todayCount,
            'daily_score' => $dailyScore['total_score'],
            'streak' => $streak,
            'new_badges' => $newBadgesInfo,
        ]);
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
        } catch (\Exception $e) {
            return new JsonResponse(['success' => false, 'error' => 'Invalid timezone'], 400);
        }

        $today = new \DateTime('now', $tz);
        $today->setTime(0, 0, 0);

        // Chercher si un réveil existe déjà pour aujourd'hui
        $wakeUp = $this->wakeUpRepository->findByDate($today);

        if (!$wakeUp) {
            $wakeUp = new WakeUp();
            $wakeUp->setDate($today);
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
        } catch (\Exception $e) {
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
        } catch (\Exception $e) {
            return new JsonResponse(['success' => false, 'error' => 'Database error'], 500);
        }

        // Invalider le cache du scoring après mutation
        $this->scoringService->invalidateCache();

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
        // Utiliser le StatsService pour récupérer toutes les stats
        $fullStats = $this->statsService->getFullStats();
        $rank = $this->scoringService->getCurrentRank();

        // Vérifier et attribuer les nouveaux badges
        $this->badgeService->checkAndAwardBadges();
        $badges = $this->badgeService->getAllBadgesWithStatus();

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
        ]);
    }

    #[Route('/settings', name: 'app_settings')]
    public function settings(): Response
    {
        $currentGoal = $this->goalService->getCurrentGoal();
        $suggestedGoal = $this->goalService->getSuggestedGoal();
        $goalInfo = $this->goalService->getProgressInfo();

        return $this->render('home/settings.html.twig', [
            'pack_price' => $this->settingsRepository->get('pack_price', '12.00'),
            'cigs_per_pack' => $this->settingsRepository->get('cigs_per_pack', '20'),
            'initial_daily_cigs' => $this->settingsRepository->get('initial_daily_cigs', '20'),
            'daily_goal' => $currentGoal,
            'suggested_goal' => $suggestedGoal,
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

        // Validate daily_goal (positive integer, can be 0 for "no goal")
        $dailyGoal = $request->request->get('daily_goal');
        if ($dailyGoal !== null && $dailyGoal !== '') {
            if (!is_numeric($dailyGoal) || (int) $dailyGoal < 0 || (int) $dailyGoal > 100) {
                return new JsonResponse(['success' => false, 'error' => 'Invalid daily goal'], 400);
            }
            $this->settingsRepository->set('daily_goal', (string) (int) $dailyGoal);
        }

        return new JsonResponse(['success' => true]);
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
        $dailyScore = $this->scoringService->calculateDailyScore($selectedDate);

        return $this->render('home/history.html.twig', [
            'selected_date' => $selectedDate,
            'cigarettes' => $cigarettes,
            'wakeup' => $wakeUp,
            'daily_score' => $dailyScore,
            'first_date' => $firstDate,
        ]);
    }
}
