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

class HomeController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private CigaretteRepository $cigaretteRepository,
        private WakeUpRepository $wakeUpRepository,
        private SettingsRepository $settingsRepository,
        private ScoringService $scoringService
    ) {}

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

        // Calculer le compte à rebours pour la prochaine clope sans pénalité
        $nextCigTarget = $this->calculateNextCigTarget($todayCigs, $yesterdayCigs, $todayWakeUp, $yesterdayWakeUp);

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
        ]);
    }

    private function getEncouragementMessage(array $todayCigs, array $yesterdayCigs, array $dailyScore): ?array
    {
        $todayCount = count($todayCigs);
        $yesterdayCount = count($yesterdayCigs);
        $minRecord = $this->cigaretteRepository->getMinDailyCount();

        // Aucune clope aujourd'hui
        if ($todayCount === 0) {
            return [
                'type' => 'success',
                'message' => 'Zéro clope ! Continue comme ça !',
                'icon' => '🏆',
            ];
        }

        // Record en vue
        if ($minRecord !== null && $todayCount <= $minRecord) {
            return [
                'type' => 'success',
                'message' => 'Record en vue ! Tu es à ' . $todayCount . ' clopes (record: ' . $minRecord . ')',
                'icon' => '🎯',
            ];
        }

        // Moins de clopes qu'hier à cette heure
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
            return [
                'type' => 'success',
                'message' => 'Tu tiens bon ! ' . $diff . ' clope(s) de moins qu\'hier à cette heure',
                'icon' => '💪',
            ];
        }

        // Plus de clopes qu'hier à cette heure
        if ($todayCount > $yesterdayAtSameTime && $yesterdayAtSameTime > 0) {
            $diff = $todayCount - $yesterdayAtSameTime;
            return [
                'type' => 'warning',
                'message' => 'Attention ! ' . $diff . ' clope(s) de plus qu\'hier à cette heure',
                'icon' => '⚠️',
            ];
        }

        // Score positif du jour
        if ($dailyScore['total_score'] > 20) {
            return [
                'type' => 'success',
                'message' => 'Belle journée ! Continue sur cette lancée',
                'icon' => '👍',
            ];
        }

        return null;
    }

    private function calculateNextCigTarget(array $todayCigs, array $yesterdayCigs, $todayWakeUp, $yesterdayWakeUp): ?array
    {
        $todayCount = count($todayCigs);

        // Quelle est la prochaine clope à comparer ?
        $nextIndex = $todayCount; // La prochaine sera à cet index

        if (!isset($yesterdayCigs[$nextIndex])) {
            // Pas de clope correspondante hier = tu as déjà fait mieux !
            return [
                'status' => 'ahead',
                'message' => 'Tu as moins de clopes qu\'hier à cette heure !',
            ];
        }

        // Calculer le temps depuis le réveil de la clope d'hier
        $yesterdayCig = $yesterdayCigs[$nextIndex];
        $yesterdayMinutesSinceWake = $this->getMinutesSinceWakeUp($yesterdayCig->getSmokedAt(), $yesterdayWakeUp);

        // Calculer l'heure cible aujourd'hui (réveil + même délai)
        if ($todayWakeUp) {
            $targetTime = clone $todayWakeUp->getWakeDateTime();
            $targetTime->modify("+{$yesterdayMinutesSinceWake} minutes");
        } else {
            // Pas de réveil aujourd'hui, utiliser l'heure absolue d'hier
            $targetTime = clone $yesterdayCig->getSmokedAt();
            $targetTime->modify('+1 day');
        }

        $now = new \DateTime();
        $diff = $targetTime->getTimestamp() - $now->getTimestamp();

        if ($diff <= 0) {
            return [
                'status' => 'ok',
                'message' => 'Tu peux fumer sans pénalité',
                'target_time' => $targetTime->format('H:i'),
            ];
        }

        $hours = floor($diff / 3600);
        $minutes = floor(($diff % 3600) / 60);

        return [
            'status' => 'waiting',
            'message' => sprintf('Sans pénalité dans %dh%02d', $hours, $minutes),
            'target_time' => $targetTime->format('H:i'),
            'seconds_remaining' => $diff,
        ];
    }

    private function getMinutesSinceWakeUp(\DateTimeInterface $cigTime, $wakeUp): int
    {
        if ($wakeUp === null) {
            return (int) $cigTime->format('H') * 60 + (int) $cigTime->format('i');
        }

        $wakeDateTime = $wakeUp->getWakeDateTime();
        $diff = $cigTime->getTimestamp() - $wakeDateTime->getTimestamp();

        return max(0, (int) ($diff / 60));
    }

    #[Route('/log', name: 'app_log_cigarette', methods: ['POST'])]
    public function logCigarette(Request $request): JsonResponse
    {
        $cigarette = new Cigarette();

        $customTime = $request->request->get('custom_time');
        if ($customTime) {
            $smokedAt = \DateTime::createFromFormat('Y-m-d\TH:i', $customTime);
            if ($smokedAt) {
                $cigarette->setSmokedAt($smokedAt);
                $cigarette->setIsRetroactive(true);
            }
        }

        $this->entityManager->persist($cigarette);
        $this->entityManager->flush();

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
        $today = new \DateTime();
        $today->setTime(0, 0, 0);

        // Chercher si un réveil existe déjà pour aujourd'hui
        $wakeUp = $this->wakeUpRepository->findTodayWakeUp();

        if (!$wakeUp) {
            $wakeUp = new WakeUp();
            $wakeUp->setDate($today);
        }

        $customTime = $request->request->get('wake_time');
        if ($customTime) {
            $wakeTime = \DateTime::createFromFormat('H:i', $customTime);
        } else {
            $wakeTime = new \DateTime();
        }

        $wakeUp->setWakeTime($wakeTime);

        $this->entityManager->persist($wakeUp);
        $this->entityManager->flush();

        return new JsonResponse([
            'success' => true,
            'wake_time' => $wakeUp->getWakeTime()->format('H:i'),
        ]);
    }

    #[Route('/delete/{id}', name: 'app_delete_cigarette', methods: ['POST'])]
    public function deleteCigarette(Cigarette $cigarette): JsonResponse
    {
        $this->entityManager->remove($cigarette);
        $this->entityManager->flush();

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

        // Ne calculer les scores que depuis le premier jour
        $weeklyScores = [];
        $date = new \DateTime('-6 days');
        $firstDateNormalized = $firstDate ? (clone $firstDate)->setTime(0, 0, 0) : null;

        for ($i = 0; $i < 7; $i++) {
            $currentDateNormalized = (clone $date)->setTime(0, 0, 0);
            // N'inclure que les jours à partir du premier jour
            if ($firstDateNormalized && $currentDateNormalized >= $firstDateNormalized) {
                $dailyScore = $this->scoringService->calculateDailyScore($date);
                $weeklyScores[$date->format('Y-m-d')] = $dailyScore;
            }
            $date->modify('+1 day');
        }

        // Statistiques avancées
        $weekdayStats = $this->cigaretteRepository->getWeekdayStats();
        $hourlyStats = $this->cigaretteRepository->getHourlyStats();

        // Économies réalisées
        $savings = $this->calculateSavings();

        return $this->render('home/stats.html.twig', [
            'monthly_stats' => $stats,
            'weekly_scores' => $weeklyScores,
            'rank' => $rank,
            'weekday_stats' => $weekdayStats,
            'hourly_stats' => $hourlyStats,
            'savings' => $savings,
            'first_date' => $firstDate,
        ]);
    }

    private function calculateSavings(): array
    {
        $packPrice = (float) $this->settingsRepository->get('pack_price', '12.00');
        $cigsPerPack = (int) $this->settingsRepository->get('cigs_per_pack', '20');
        $initialDailyCigs = (int) $this->settingsRepository->get('initial_daily_cigs', '20');

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
        $packPrice = $request->request->get('pack_price');
        $cigsPerPack = $request->request->get('cigs_per_pack');
        $initialDailyCigs = $request->request->get('initial_daily_cigs');

        if ($packPrice) {
            $this->settingsRepository->set('pack_price', $packPrice);
        }
        if ($cigsPerPack) {
            $this->settingsRepository->set('cigs_per_pack', $cigsPerPack);
        }
        if ($initialDailyCigs) {
            $this->settingsRepository->set('initial_daily_cigs', $initialDailyCigs);
        }

        return new JsonResponse(['success' => true]);
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
