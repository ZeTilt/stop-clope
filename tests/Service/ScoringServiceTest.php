<?php

namespace App\Tests\Service;

use App\Entity\Cigarette;
use App\Entity\User;
use App\Entity\WakeUp;
use App\Repository\CigaretteRepository;
use App\Repository\DailyScoreRepository;
use App\Repository\SettingsRepository;
use App\Repository\WakeUpRepository;
use App\Service\IntervalCalculator;
use App\Service\MultiplierCalculator;
use App\Service\RankService;
use App\Service\ScoringService;
use App\Service\StreakService;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

class ScoringServiceTest extends TestCase
{
    private ScoringService $scoringService;
    private CigaretteRepository $cigaretteRepository;
    private WakeUpRepository $wakeUpRepository;
    private DailyScoreRepository $dailyScoreRepository;
    private SettingsRepository $settingsRepository;
    private Security $security;
    private IntervalCalculator $intervalCalculator;
    private StreakService $streakService;
    private RankService $rankService;
    private MultiplierCalculator $multiplierCalculator;

    protected function setUp(): void
    {
        $this->cigaretteRepository = $this->createMock(CigaretteRepository::class);
        $this->wakeUpRepository = $this->createMock(WakeUpRepository::class);
        $this->dailyScoreRepository = $this->createMock(DailyScoreRepository::class);
        $this->settingsRepository = $this->createMock(SettingsRepository::class);
        $this->security = $this->createMock(Security::class);
        $this->intervalCalculator = $this->createMock(IntervalCalculator::class);
        $this->streakService = $this->createMock(StreakService::class);
        $this->rankService = $this->createMock(RankService::class);
        $this->multiplierCalculator = $this->createMock(MultiplierCalculator::class);

        $this->scoringService = new ScoringService(
            $this->cigaretteRepository,
            $this->wakeUpRepository,
            $this->dailyScoreRepository,
            $this->settingsRepository,
            $this->security,
            $this->intervalCalculator,
            $this->streakService,
            $this->rankService,
            $this->multiplierCalculator
        );
    }

    public function testCalculateDailyScoreFirstDay(): void
    {
        $date = new \DateTime('2024-01-15');

        // No historical data = first day
        $this->intervalCalculator
            ->method('hasHistoricalData')
            ->willReturn(false);

        $this->cigaretteRepository
            ->method('findByDate')
            ->willReturn([]);

        $result = $this->scoringService->calculateDailyScore($date);

        $this->assertEquals('2024-01-15', $result['date']);
        $this->assertEquals(0, $result['total_score']);
        $this->assertEquals(0, $result['cigarette_count']);
        $this->assertArrayHasKey('details', $result);
        $this->assertArrayHasKey('message', $result['details']);
    }

    public function testCalculateDailyScoreWithCigarettes(): void
    {
        $date = new \DateTime('2024-01-16');
        $yesterday = new \DateTime('2024-01-15');

        // Has historical data
        $this->intervalCalculator
            ->method('hasHistoricalData')
            ->willReturn(true);

        // 2 cigs today
        $user = $this->createMock(User::class);
        $cig1 = $this->createCigarette($user, '08:30');
        $cig2 = $this->createCigarette($user, '10:30');

        $this->cigaretteRepository
            ->method('findByDate')
            ->willReturnCallback(function ($d) use ($date, $yesterday, $cig1, $cig2) {
                if ($d->format('Y-m-d') === $date->format('Y-m-d')) {
                    return [$cig1, $cig2];
                }
                // Yesterday: 3 cigs
                return [
                    $this->createCigarette($this->createMock(User::class), '08:00'),
                    $this->createCigarette($this->createMock(User::class), '10:00'),
                    $this->createCigarette($this->createMock(User::class), '12:00'),
                ];
            });

        // No wakeup
        $this->wakeUpRepository
            ->method('findByDate')
            ->willReturn(null);

        // Mock intervalCalculator methods
        $this->intervalCalculator
            ->method('getSmoothedAverageInterval')
            ->willReturn(120.0);

        $this->intervalCalculator
            ->method('calculateTargetMinutes')
            ->willReturn(120.0);

        $this->intervalCalculator
            ->method('timeToMinutes')
            ->willReturnCallback(function ($time) {
                return (int)$time->format('H') * 60 + (int)$time->format('i');
            });

        // Mock MultiplierCalculator v2.0
        $this->multiplierCalculator
            ->method('calculatePoints')
            ->willReturn(10); // Simule des points positifs

        $this->multiplierCalculator
            ->method('getZoneMultiplier')
            ->willReturn(1.0);

        $this->multiplierCalculator
            ->method('getTotalMultiplier')
            ->willReturn(1.0);

        // Mock user for the calculation
        $this->security
            ->method('getUser')
            ->willReturn($user);

        $result = $this->scoringService->calculateDailyScore($date);

        $this->assertEquals('2024-01-16', $result['date']);
        $this->assertEquals(2, $result['cigarette_count']);
        $this->assertEquals(3, $result['yesterday_count']);
        // 2 cigarettes x 10 points = 20
        $this->assertEquals(20, $result['total_score']);
        // Potential reduction bonus: 3 - 2 = 1 cig less x 5 pts = 5
        $this->assertEquals(5, $result['potential_reduction_bonus']);
        // Check v2.0 details are included
        $this->assertEquals(1.0, $result['details']['comparisons'][0]['zone_multiplier']);
        $this->assertEquals(1.0, $result['details']['comparisons'][0]['total_multiplier']);
    }

    public function testGetTotalScoreOptimizedDelegatesToRepository(): void
    {
        $this->dailyScoreRepository
            ->method('getTotalScore')
            ->willReturn(150);

        $result = $this->scoringService->getTotalScoreOptimized();

        $this->assertEquals(150, $result);
    }

    public function testGetStreakDelegatesToStreakService(): void
    {
        $this->streakService
            ->method('getStreakOptimized')
            ->willReturn(['current' => 5, 'best' => 10]);

        $result = $this->scoringService->getStreak();

        $this->assertEquals(['current' => 5, 'best' => 10], $result);
    }

    public function testGetCurrentRankDelegatesToRankService(): void
    {
        $rankData = [
            'name' => 'Débutant',
            'level' => 1,
            'progress' => 50,
        ];

        $this->rankService
            ->method('getCurrentRank')
            ->willReturn($rankData);

        $result = $this->scoringService->getCurrentRank();

        $this->assertEquals($rankData, $result);
    }

    public function testInvalidateCacheDelegatesToIntervalCalculator(): void
    {
        $this->intervalCalculator
            ->expects($this->once())
            ->method('invalidateCache');

        $this->scoringService->invalidateCache();
    }

    private function createCigarette(User $user, string $time): Cigarette
    {
        $cig = $this->createMock(Cigarette::class);
        $smokedAt = \DateTime::createFromFormat('H:i', $time);
        $cig->method('getSmokedAt')->willReturn($smokedAt);
        $cig->method('getUser')->willReturn($user);
        return $cig;
    }
}
