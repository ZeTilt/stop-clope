<?php

namespace App\Tests\Service;

use App\Repository\CigaretteRepository;
use App\Repository\SettingsRepository;
use App\Service\GoalService;
use PHPUnit\Framework\TestCase;

class GoalServiceTest extends TestCase
{
    private GoalService $goalService;
    private CigaretteRepository $cigaretteRepository;
    private SettingsRepository $settingsRepository;

    protected function setUp(): void
    {
        $this->cigaretteRepository = $this->createMock(CigaretteRepository::class);
        $this->settingsRepository = $this->createMock(SettingsRepository::class);
        $this->goalService = new GoalService(
            $this->cigaretteRepository,
            $this->settingsRepository
        );
    }

    public function testGetTierInfoNoData(): void
    {
        // No historical data, should use initial value
        $this->cigaretteRepository->method('getAverageDailyCount')->willReturn(null);
        $this->settingsRepository->method('get')
            ->willReturnMap([
                ['initial_daily_cigs', '20', '15'],
                ['current_auto_tier', null, null],
            ]);

        $tierInfo = $this->goalService->getTierInfo();

        $this->assertEquals(15, $tierInfo['current_tier']);
        $this->assertEquals(14, $tierInfo['next_tier']);
        $this->assertEquals(15, $tierInfo['initial']);
        $this->assertNull($tierInfo['avg_14d']);
    }

    public function testGetTierInfoWithAverage(): void
    {
        // Average of 10.5 should give tier = floor(10.5) - 1 = 9
        $this->cigaretteRepository->method('getAverageDailyCount')->willReturn(10.5);
        $this->settingsRepository->method('get')
            ->willReturnMap([
                ['initial_daily_cigs', '20', '20'],
                ['current_auto_tier', null, null],
            ]);
        $this->settingsRepository->expects($this->once())
            ->method('set')
            ->with('current_auto_tier', '9');

        $tierInfo = $this->goalService->getTierInfo();

        $this->assertEquals(9, $tierInfo['current_tier']);
        $this->assertEquals(8, $tierInfo['next_tier']);
        $this->assertEquals(20, $tierInfo['initial']);
        $this->assertEquals(10.5, $tierInfo['avg_14d']);
    }

    public function testGetTierInfoCeilingEffect(): void
    {
        // Previous tier was 8, but average suggests 10
        // Tier should stay at 8 (ceiling - never goes up)
        $this->cigaretteRepository->method('getAverageDailyCount')->willReturn(11.5); // floor - 1 = 10
        $this->settingsRepository->method('get')
            ->willReturnMap([
                ['initial_daily_cigs', '20', '20'],
                ['current_auto_tier', null, '8'],
            ]);

        $tierInfo = $this->goalService->getTierInfo();

        $this->assertEquals(8, $tierInfo['current_tier']); // Stays at 8, not 10
    }

    public function testGetTierInfoDecreasesNormally(): void
    {
        // Previous tier was 10, average suggests 7
        // Tier should go down to 7
        $this->cigaretteRepository->method('getAverageDailyCount')->willReturn(8.2); // floor - 1 = 7
        $this->settingsRepository->method('get')
            ->willReturnMap([
                ['initial_daily_cigs', '20', '20'],
                ['current_auto_tier', null, '10'],
            ]);
        $this->settingsRepository->expects($this->once())
            ->method('set')
            ->with('current_auto_tier', '7');

        $tierInfo = $this->goalService->getTierInfo();

        $this->assertEquals(7, $tierInfo['current_tier']);
    }

    public function testGetTierInfoMinimumZero(): void
    {
        // Average of 0.5 should give tier = max(0, floor(0.5) - 1) = max(0, -1) = 0
        $this->cigaretteRepository->method('getAverageDailyCount')->willReturn(0.5);
        $this->settingsRepository->method('get')
            ->willReturnMap([
                ['initial_daily_cigs', '20', '20'],
                ['current_auto_tier', null, null],
            ]);

        $tierInfo = $this->goalService->getTierInfo();

        $this->assertEquals(0, $tierInfo['current_tier']);
        $this->assertNull($tierInfo['next_tier']); // No next tier when at 0
    }

    public function testGetTierInfoAtZeroNextTierIsNull(): void
    {
        $this->cigaretteRepository->method('getAverageDailyCount')->willReturn(1.0);
        $this->settingsRepository->method('get')
            ->willReturnMap([
                ['initial_daily_cigs', '20', '20'],
                ['current_auto_tier', null, '0'],
            ]);

        $tierInfo = $this->goalService->getTierInfo();

        $this->assertEquals(0, $tierInfo['current_tier']);
        $this->assertNull($tierInfo['next_tier']);
    }

    public function testGetDailyProgressUnderGoal(): void
    {
        $this->cigaretteRepository->method('getAverageDailyCount')->willReturn(10.0);
        $this->cigaretteRepository->method('countByDate')->willReturn(5);
        $this->settingsRepository->method('get')
            ->willReturnMap([
                ['initial_daily_cigs', '20', '20'],
                ['current_auto_tier', null, '9'],
            ]);

        $progress = $this->goalService->getDailyProgress();

        $this->assertEquals(9, $progress['goal']);
        $this->assertEquals(5, $progress['current']);
        $this->assertEquals(4, $progress['remaining']);
        $this->assertFalse($progress['exceeded']);
        $this->assertEquals(0, $progress['exceeded_by']);
        $this->assertEquals(56, $progress['progress_percent']); // 5/9 = 55.5%
    }

    public function testGetDailyProgressExceeded(): void
    {
        $this->cigaretteRepository->method('getAverageDailyCount')->willReturn(10.0);
        $this->cigaretteRepository->method('countByDate')->willReturn(12);
        $this->settingsRepository->method('get')
            ->willReturnMap([
                ['initial_daily_cigs', '20', '20'],
                ['current_auto_tier', null, '9'],
            ]);

        $progress = $this->goalService->getDailyProgress();

        $this->assertEquals(9, $progress['goal']);
        $this->assertEquals(12, $progress['current']);
        $this->assertEquals(0, $progress['remaining']);
        $this->assertTrue($progress['exceeded']);
        $this->assertEquals(3, $progress['exceeded_by']);
        $this->assertEquals(100, $progress['progress_percent']); // capped at 100
    }

    public function testGetDailyProgressExactlyAtGoal(): void
    {
        $this->cigaretteRepository->method('getAverageDailyCount')->willReturn(10.0);
        $this->cigaretteRepository->method('countByDate')->willReturn(9);
        $this->settingsRepository->method('get')
            ->willReturnMap([
                ['initial_daily_cigs', '20', '20'],
                ['current_auto_tier', null, '9'],
            ]);

        $progress = $this->goalService->getDailyProgress();

        $this->assertEquals(9, $progress['goal']);
        $this->assertEquals(9, $progress['current']);
        $this->assertEquals(0, $progress['remaining']);
        $this->assertFalse($progress['exceeded']);
        $this->assertEquals(0, $progress['exceeded_by']);
        $this->assertEquals(100, $progress['progress_percent']);
    }

    public function testCheckTierAchievementFirstTime(): void
    {
        $this->cigaretteRepository->method('getAverageDailyCount')->willReturn(10.0);
        $this->settingsRepository->method('get')
            ->willReturnMap([
                ['initial_daily_cigs', '20', '20'],
                ['current_auto_tier', null, '9'],
                ['previous_displayed_tier', null, null],
            ]);
        $this->settingsRepository->expects($this->once())
            ->method('set')
            ->with('previous_displayed_tier', '9');

        $result = $this->goalService->checkTierAchievement();

        $this->assertFalse($result['achieved']);
        $this->assertNull($result['new_tier']);
    }

    public function testCheckTierAchievementNewTier(): void
    {
        $this->cigaretteRepository->method('getAverageDailyCount')->willReturn(8.0);
        $this->settingsRepository->method('get')
            ->willReturnMap([
                ['initial_daily_cigs', '20', '20'],
                ['current_auto_tier', null, '7'],
                ['previous_displayed_tier', null, '9'],
            ]);

        $result = $this->goalService->checkTierAchievement();

        $this->assertTrue($result['achieved']);
        $this->assertEquals(7, $result['new_tier']);
        $this->assertNotEmpty($result['message']);
    }

    public function testCheckTierAchievementNoChange(): void
    {
        $this->cigaretteRepository->method('getAverageDailyCount')->willReturn(10.0);
        $this->settingsRepository->method('get')
            ->willReturnMap([
                ['initial_daily_cigs', '20', '20'],
                ['current_auto_tier', null, '9'],
                ['previous_displayed_tier', null, '9'],
            ]);

        $result = $this->goalService->checkTierAchievement();

        $this->assertFalse($result['achieved']);
        $this->assertNull($result['new_tier']);
    }
}
