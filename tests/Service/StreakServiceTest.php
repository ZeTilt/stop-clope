<?php

namespace App\Tests\Service;

use App\Repository\CigaretteRepository;
use App\Repository\DailyScoreRepository;
use App\Repository\WakeUpRepository;
use App\Service\IntervalCalculator;
use App\Service\StreakService;
use PHPUnit\Framework\TestCase;

class StreakServiceTest extends TestCase
{
    private StreakService $streakService;
    private CigaretteRepository $cigaretteRepository;
    private WakeUpRepository $wakeUpRepository;
    private DailyScoreRepository $dailyScoreRepository;
    private IntervalCalculator $intervalCalculator;

    protected function setUp(): void
    {
        $this->cigaretteRepository = $this->createMock(CigaretteRepository::class);
        $this->wakeUpRepository = $this->createMock(WakeUpRepository::class);
        $this->dailyScoreRepository = $this->createMock(DailyScoreRepository::class);
        $this->intervalCalculator = $this->createMock(IntervalCalculator::class);

        $this->streakService = new StreakService(
            $this->cigaretteRepository,
            $this->wakeUpRepository,
            $this->dailyScoreRepository,
            $this->intervalCalculator
        );
    }

    public function testGetStreakOptimized(): void
    {
        $this->dailyScoreRepository->method('getCurrentStreak')->willReturn(5);
        $this->dailyScoreRepository->method('getBestStreak')->willReturn(10);

        $result = $this->streakService->getStreakOptimized();

        $this->assertEquals(5, $result['current']);
        $this->assertEquals(10, $result['best']);
        $this->assertFalse($result['today_positive']);
    }

    public function testGetStreakNoData(): void
    {
        $this->cigaretteRepository->method('getFirstCigaretteDate')->willReturn(null);

        $result = $this->streakService->getStreak();

        $this->assertEquals(0, $result['current']);
        $this->assertEquals(0, $result['best']);
        $this->assertFalse($result['today_positive']);
    }

    public function testCheckMilestoneAt3Days(): void
    {
        $result = $this->streakService->checkMilestone(3, 2);

        $this->assertNotNull($result);
        $this->assertEquals(3, $result['days']);
        $this->assertEquals('🌟', $result['emoji']);
        $this->assertStringContainsString('3 jours', $result['message']);
    }

    public function testCheckMilestoneAt7Days(): void
    {
        $result = $this->streakService->checkMilestone(7, 6);

        $this->assertNotNull($result);
        $this->assertEquals(7, $result['days']);
        $this->assertEquals('🔥', $result['emoji']);
        $this->assertStringContainsString('semaine', $result['message']);
    }

    public function testCheckMilestoneAt30Days(): void
    {
        $result = $this->streakService->checkMilestone(30, 29);

        $this->assertNotNull($result);
        $this->assertEquals(30, $result['days']);
        $this->assertEquals('🏆', $result['emoji']);
        $this->assertStringContainsString('mois', $result['message']);
    }

    public function testCheckMilestoneNoMilestone(): void
    {
        // From 4 to 5, no milestone
        $result = $this->streakService->checkMilestone(5, 4);
        $this->assertNull($result);
    }

    public function testCheckMilestoneAlreadyPassed(): void
    {
        // Already at 10, staying at 10, no new milestone
        $result = $this->streakService->checkMilestone(10, 10);
        $this->assertNull($result);
    }

    public function testGetNextMilestoneFrom0(): void
    {
        $result = $this->streakService->getNextMilestone(0);

        $this->assertNotNull($result);
        $this->assertEquals(3, $result['days']);
        $this->assertEquals(3, $result['days_remaining']);
    }

    public function testGetNextMilestoneFrom5(): void
    {
        $result = $this->streakService->getNextMilestone(5);

        $this->assertNotNull($result);
        $this->assertEquals(7, $result['days']);
        $this->assertEquals(2, $result['days_remaining']);
    }

    public function testGetNextMilestoneFrom100(): void
    {
        $result = $this->streakService->getNextMilestone(100);

        $this->assertNotNull($result);
        $this->assertEquals(180, $result['days']);
        $this->assertEquals(80, $result['days_remaining']);
    }

    public function testGetNextMilestoneAllAchieved(): void
    {
        // 365 is the last milestone
        $result = $this->streakService->getNextMilestone(400);
        $this->assertNull($result);
    }

    public function testGetAllMilestonesNoStreak(): void
    {
        $result = $this->streakService->getAllMilestones(0);

        $this->assertIsArray($result);
        $this->assertGreaterThan(0, count($result));

        // All should be not achieved
        foreach ($result as $milestone) {
            $this->assertFalse($milestone['achieved']);
        }
    }

    public function testGetAllMilestonesSomeAchieved(): void
    {
        $result = $this->streakService->getAllMilestones(10);

        $achievedCount = 0;
        foreach ($result as $milestone) {
            if ($milestone['achieved']) {
                $achievedCount++;
            }
        }

        // 3 and 7 should be achieved
        $this->assertEquals(2, $achievedCount);
    }

    public function testGetAllMilestonesAllAchieved(): void
    {
        $result = $this->streakService->getAllMilestones(500);

        foreach ($result as $milestone) {
            if ($milestone['days'] <= 365) {
                $this->assertTrue($milestone['achieved']);
            }
        }
    }

    public function testMilestoneStructure(): void
    {
        $result = $this->streakService->getAllMilestones(0);

        foreach ($result as $milestone) {
            $this->assertArrayHasKey('days', $milestone);
            $this->assertArrayHasKey('emoji', $milestone);
            $this->assertArrayHasKey('message', $milestone);
            $this->assertArrayHasKey('achieved', $milestone);
        }
    }

    // ========================================
    // Tests getStreakBonus v2.0
    // ========================================

    public function testGetStreakBonusZero(): void
    {
        $result = $this->streakService->getStreakBonus(0);
        $this->assertEquals(0.0, $result);
    }

    public function testGetStreakBonusUnder3Days(): void
    {
        $result = $this->streakService->getStreakBonus(2);
        $this->assertEquals(0.0, $result);
    }

    public function testGetStreakBonus3Days(): void
    {
        $result = $this->streakService->getStreakBonus(3);
        $this->assertEquals(0.05, $result);
    }

    public function testGetStreakBonus5Days(): void
    {
        $result = $this->streakService->getStreakBonus(5);
        $this->assertEquals(0.05, $result);
    }

    public function testGetStreakBonus7Days(): void
    {
        $result = $this->streakService->getStreakBonus(7);
        $this->assertEquals(0.10, $result);
    }

    public function testGetStreakBonus10Days(): void
    {
        $result = $this->streakService->getStreakBonus(10);
        $this->assertEquals(0.10, $result);
    }

    public function testGetStreakBonus14Days(): void
    {
        $result = $this->streakService->getStreakBonus(14);
        $this->assertEquals(0.15, $result);
    }

    public function testGetStreakBonus30Days(): void
    {
        $result = $this->streakService->getStreakBonus(30);
        $this->assertEquals(0.15, $result);
    }

    // ========================================
    // Tests getNextBonusTier v2.0
    // ========================================

    public function testGetNextBonusTierFrom0(): void
    {
        $result = $this->streakService->getNextBonusTier(0);

        $this->assertNotNull($result);
        $this->assertEquals(3, $result['days_needed']);
        $this->assertEquals(3, $result['days_remaining']);
        $this->assertEquals(0.05, $result['bonus']);
        $this->assertEquals(5, $result['bonus_percentage']);
    }

    public function testGetNextBonusTierFrom5(): void
    {
        $result = $this->streakService->getNextBonusTier(5);

        $this->assertNotNull($result);
        $this->assertEquals(7, $result['days_needed']);
        $this->assertEquals(2, $result['days_remaining']);
        $this->assertEquals(0.10, $result['bonus']);
    }

    public function testGetNextBonusTierFrom10(): void
    {
        $result = $this->streakService->getNextBonusTier(10);

        $this->assertNotNull($result);
        $this->assertEquals(14, $result['days_needed']);
        $this->assertEquals(4, $result['days_remaining']);
    }

    public function testGetNextBonusTierMaxReached(): void
    {
        $result = $this->streakService->getNextBonusTier(14);

        $this->assertNull($result);
    }

    // ========================================
    // Tests getStreakInfo v2.0
    // ========================================

    public function testGetStreakInfoBasic(): void
    {
        $this->dailyScoreRepository->method('getCurrentStreak')->willReturn(5);
        $this->dailyScoreRepository->method('getBestStreak')->willReturn(10);
        $this->dailyScoreRepository->method('findByDate')->willReturn(null);

        $result = $this->streakService->getStreakInfo();

        $this->assertEquals(5, $result['current']);
        $this->assertEquals(10, $result['best']);
        $this->assertEquals(0.05, $result['bonus_multiplier']);
        $this->assertEquals(5, $result['bonus_percentage']);
        $this->assertNotNull($result['next_bonus_tier']);
        $this->assertNotNull($result['next_milestone']);
    }

    // ========================================
    // Tests isStreakProtected v2.0
    // ========================================

    public function testIsStreakProtectedNoScore(): void
    {
        $this->dailyScoreRepository->method('findByDate')->willReturn(null);

        $result = $this->streakService->isStreakProtected(new \DateTime());

        $this->assertFalse($result);
    }

    public function testIsStreakProtectedMaintenanceDay(): void
    {
        $dailyScore = $this->createMock(\App\Entity\DailyScore::class);
        $dailyScore->method('isMaintenanceDay')->willReturn(true);

        $this->dailyScoreRepository->method('findByDate')->willReturn($dailyScore);

        $result = $this->streakService->isStreakProtected(new \DateTime());

        $this->assertTrue($result);
    }

    public function testIsStreakProtectedNormalDay(): void
    {
        $dailyScore = $this->createMock(\App\Entity\DailyScore::class);
        $dailyScore->method('isMaintenanceDay')->willReturn(false);

        $this->dailyScoreRepository->method('findByDate')->willReturn($dailyScore);

        $result = $this->streakService->isStreakProtected(new \DateTime());

        $this->assertFalse($result);
    }
}
