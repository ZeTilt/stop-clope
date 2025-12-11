<?php

namespace App\Controller;

use App\Entity\Cigarette;
use App\Entity\WakeUp;
use App\Repository\CigaretteRepository;
use App\Repository\SettingsRepository;
use App\Repository\WakeUpRepository;
use App\Service\ScoringService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

class HomeController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private CigaretteRepository $cigaretteRepository,
        private WakeUpRepository $wakeUpRepository,
        private SettingsRepository $settingsRepository,
        private ScoringService $scoringService,
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
        $encouragement = $this->getEncouragementMessage($todayCigs, $yesterdayCigs, $dailyScore);

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
        ]);
    }

    private function getEncouragementMessage(array $todayCigs, array $yesterdayCigs, array $dailyScore): ?array
    {
        $todayCount = count($todayCigs);
        $yesterdayCount = count($yesterdayCigs);
        $minRecord = $this->cigaretteRepository->getMinDailyCount();
        $totalScore = $dailyScore['total_score'];

        // Messages variés pour chaque situation
        $zeroMessages = [
            ['icon' => '🏆', 'message' => 'Zéro clope ! Tu gères !'],
            ['icon' => '🌟', 'message' => 'Journée parfaite jusqu\'ici !'],
            ['icon' => '💪', 'message' => 'Aucune clope, bravo !'],
        ];

        $recordMessages = [
            ['icon' => '🎯', 'message' => 'Record en vue ! ' . $todayCount . ' clopes (record: ' . $minRecord . ')'],
            ['icon' => '🔥', 'message' => 'Tu bats ton record ! Seulement ' . $todayCount . ' clopes'],
            ['icon' => '⭐', 'message' => 'Nouveau record possible ! Continue !'],
        ];

        $lessMessages = [
            ['icon' => '💪', 'message' => '%d clope(s) de moins qu\'hier à cette heure'],
            ['icon' => '📉', 'message' => 'En avance ! %d de moins qu\'hier'],
            ['icon' => '👏', 'message' => 'Super ! Tu as %d clope(s) d\'avance sur hier'],
        ];

        $moreMessages = [
            ['icon' => '⚠️', 'message' => 'Attention : %d de plus qu\'hier à cette heure'],
            ['icon' => '🔔', 'message' => 'Petit dépassement : +%d vs hier'],
        ];

        $goodScoreMessages = [
            ['icon' => '👍', 'message' => 'Belle journée ! +' . $totalScore . ' pts'],
            ['icon' => '🚀', 'message' => 'En forme aujourd\'hui ! Continue'],
            ['icon' => '✨', 'message' => 'Très bon rythme, bravo !'],
        ];

        $morningMessages = [
            ['icon' => '☀️', 'message' => 'Nouvelle journée, nouvelles opportunités !'],
            ['icon' => '🌅', 'message' => 'C\'est parti pour une bonne journée !'],
        ];

        // Sélection basée sur l'heure pour la variété
        $hour = (int) (new \DateTime())->format('H');
        $seed = (int) (new \DateTime())->format('Ymd') + $todayCount;

        // Aucune clope aujourd'hui
        if ($todayCount === 0) {
            // Le matin sans clope, message différent
            if ($hour < 12) {
                $msg = $morningMessages[$seed % count($morningMessages)];
                return ['type' => 'success', 'message' => $msg['message'], 'icon' => $msg['icon']];
            }
            $msg = $zeroMessages[$seed % count($zeroMessages)];
            return ['type' => 'success', 'message' => $msg['message'], 'icon' => $msg['icon']];
        }

        // Record en vue
        if ($minRecord !== null && $todayCount <= $minRecord) {
            $msg = $recordMessages[$seed % count($recordMessages)];
            return ['type' => 'success', 'message' => $msg['message'], 'icon' => $msg['icon']];
        }

        // Comparaison avec hier à la même heure
        $now = new \DateTime();
        $yesterdayAtSameTime = 0;
        foreach ($yesterdayCigs as $cig) {
            $cigTime = $cig->getSmokedAt();
            $cigTimeToday = (clone $cigTime)->modify('+1 day');
            if ($cigTimeToday <= $now) {
                $yesterdayAtSameTime++;
            }
        }

        if ($todayCount < $yesterdayAtSameTime) {
            $diff = $yesterdayAtSameTime - $todayCount;
            $msg = $lessMessages[$seed % count($lessMessages)];
            return [
                'type' => 'success',
                'message' => sprintf($msg['message'], $diff),
                'icon' => $msg['icon'],
            ];
        }

        // Plus de clopes qu'hier à cette heure
        if ($todayCount > $yesterdayAtSameTime && $yesterdayAtSameTime > 0) {
            $diff = $todayCount - $yesterdayAtSameTime;
            $msg = $moreMessages[$seed % count($moreMessages)];
            return [
                'type' => 'warning',
                'message' => sprintf($msg['message'], $diff),
                'icon' => $msg['icon'],
            ];
        }

        // Score positif du jour
        if ($totalScore > 20) {
            $msg = $goodScoreMessages[$seed % count($goodScoreMessages)];
            return ['type' => 'success', 'message' => $msg['message'], 'icon' => $msg['icon']];
        }

        // Score légèrement positif
        if ($totalScore > 0 && $totalScore <= 20) {
            return [
                'type' => 'success',
                'icon' => '👌',
                'message' => 'Tu es dans le vert (+' . $totalScore . ' pts)',
            ];
        }

        // Score négatif mais pas catastrophique
        if ($totalScore < 0 && $totalScore >= -20) {
            return [
                'type' => 'warning',
                'icon' => '💡',
                'message' => 'Essaie d\'espacer un peu plus tes clopes',
            ];
        }

        return null;
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

        try {
            $this->entityManager->persist($cigarette);
            $this->entityManager->flush();
        } catch (\Exception $e) {
            return new JsonResponse(['success' => false, 'error' => 'Database error'], 500);
        }

        // Invalider le cache du scoring après mutation
        $this->scoringService->invalidateCache();

        $today = new \DateTime();
        $dailyScore = $this->scoringService->calculateDailyScore($today);
        $todayCount = $this->cigaretteRepository->countByDate($today);

        return new JsonResponse([
            'success' => true,
            'cigarette_id' => $cigarette->getId(),
            'smoked_at' => $cigarette->getSmokedAt()->format('H:i'),
            'is_retroactive' => $cigarette->isRetroactive(),
            'today_count' => $todayCount,
            'daily_score' => $dailyScore['total_score'],
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

        try {
            $this->entityManager->remove($cigarette);
            $this->entityManager->flush();
        } catch (\Exception $e) {
            return new JsonResponse(['success' => false, 'error' => 'Database error'], 500);
        }

        // Invalider le cache du scoring après mutation
        $this->scoringService->invalidateCache();

        $today = new \DateTime();
        $todayCount = $this->cigaretteRepository->countByDate($today);

        return new JsonResponse([
            'success' => true,
            'today_count' => $todayCount,
        ]);
    }

    #[Route('/stats', name: 'app_stats')]
    public function stats(): Response
    {
        $firstDate = $this->cigaretteRepository->getFirstCigaretteDate();
        $stats = $this->cigaretteRepository->getDailyStats(30);
        $rank = $this->scoringService->getCurrentRank();

        // Ne calculer les scores que depuis le premier jour, en excluant aujourd'hui
        $weeklyScores = [];
        $date = new \DateTime('-7 days'); // Commencer 7 jours en arrière
        $firstDateNormalized = $firstDate ? (clone $firstDate)->setTime(0, 0, 0) : null;
        $todayNormalized = (new \DateTime())->setTime(0, 0, 0);

        for ($i = 0; $i < 7; $i++) {
            $currentDateNormalized = (clone $date)->setTime(0, 0, 0);
            // N'inclure que les jours à partir du premier jour ET avant aujourd'hui
            if ($firstDateNormalized && $currentDateNormalized >= $firstDateNormalized && $currentDateNormalized < $todayNormalized) {
                $dailyScore = $this->scoringService->calculateDailyScore($date);
                $weeklyScores[$date->format('Y-m-d')] = $dailyScore;
            }
            $date->modify('+1 day');
        }

        // Statistiques avancées
        $weekdayStats = $this->cigaretteRepository->getWeekdayStats();
        $hourlyStats = $this->cigaretteRepository->getHourlyStats();

        // Intervalle moyen par jour
        $dailyIntervals = $this->cigaretteRepository->getDailyAverageInterval(7);

        // Comparaison semaine glissante
        $weeklyComparison = $this->cigaretteRepository->getWeeklyComparison();

        // Économies réalisées
        $savings = $this->calculateSavings();

        return $this->render('home/stats.html.twig', [
            'monthly_stats' => $stats,
            'weekly_scores' => $weeklyScores,
            'rank' => $rank,
            'weekday_stats' => $weekdayStats,
            'hourly_stats' => $hourlyStats,
            'daily_intervals' => $dailyIntervals,
            'weekly_comparison' => $weeklyComparison,
            'savings' => $savings,
            'first_date' => $firstDate,
        ]);
    }

    private function calculateSavings(): array
    {
        $packPrice = (float) $this->settingsRepository->get('pack_price', '12.00');
        $cigsPerPack = (int) $this->settingsRepository->get('cigs_per_pack', '20');
        $initialDailyCigs = (int) $this->settingsRepository->get('initial_daily_cigs', '20');

        // Prevent division by zero
        if ($cigsPerPack <= 0) {
            $cigsPerPack = 20;
        }

        $pricePerCig = $packPrice / $cigsPerPack;

        // Calculer depuis le premier jour
        $firstDate = $this->cigaretteRepository->getFirstCigaretteDate();
        if (!$firstDate) {
            return ['total' => 0, 'daily_avg' => 0, 'cigs_avoided' => 0];
        }

        $totalCigs = $this->cigaretteRepository->getTotalCount();
        $daysSinceStart = max(1, (new \DateTime())->diff($firstDate)->days + 1);

        // Clopes qu'on aurait fumées sans changement
        $expectedCigs = $initialDailyCigs * $daysSinceStart;
        $cigsAvoided = max(0, $expectedCigs - $totalCigs);

        $totalSaved = $cigsAvoided * $pricePerCig;
        $dailyAvg = $totalCigs / $daysSinceStart;

        return [
            'total' => round($totalSaved, 2),
            'cigs_avoided' => $cigsAvoided,
            'daily_avg' => round($dailyAvg, 1),
            'days' => $daysSinceStart,
            'initial_daily' => $initialDailyCigs,
        ];
    }

    #[Route('/settings', name: 'app_settings')]
    public function settings(): Response
    {
        return $this->render('home/settings.html.twig', [
            'pack_price' => $this->settingsRepository->get('pack_price', '12.00'),
            'cigs_per_pack' => $this->settingsRepository->get('cigs_per_pack', '20'),
            'initial_daily_cigs' => $this->settingsRepository->get('initial_daily_cigs', '20'),
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

    #[Route('/onboarding', name: 'app_onboarding')]
    public function onboarding(): Response
    {
        return $this->render('home/onboarding.html.twig');
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
