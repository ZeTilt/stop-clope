<?php

namespace App\Tests\Service;

use App\Entity\DailyScore;
use App\Entity\User;
use App\Entity\UserState;
use App\Repository\DailyScoreRepository;
use App\Repository\UserStateRepository;
use App\Service\IntervalCalculator;
use App\Service\IntervalProgressionService;
use PHPUnit\Framework\TestCase;

class IntervalProgressionServiceTest extends TestCase
{
    private IntervalProgressionService $service;
    private UserStateRepository $userStateRepository;
    private DailyScoreRepository $dailyScoreRepository;
    private IntervalCalculator $intervalCalculator;
    private User $user;

    protected function setUp(): void
    {
        $this->userStateRepository = $this->createMock(UserStateRepository::class);
        $this->dailyScoreRepository = $this->createMock(DailyScoreRepository::class);
        $this->intervalCalculator = $this->createMock(IntervalCalculator::class);

        $this->service = new IntervalProgressionService(
            $this->userStateRepository,
            $this->dailyScoreRepository,
            $this->intervalCalculator
        );

        $this->user = new User();
    }

    // ========================================
    // Tests getTodayTargetInterval
    // ========================================

    public function testGetTodayTargetIntervalWithExistingTarget(): void
    {
        $userState = $this->createMock(UserState::class);
        $userState->method('getCurrentTargetInterval')->willReturn(75.0);

        $this->userStateRepository->method('findByUser')->willReturn($userState);

        $result = $this->service->getTodayTargetInterval($this->user, new \DateTime());

        $this->assertEquals(75.0, $result);
    }

    public function testGetTodayTargetIntervalNoStateUsesDefault(): void
    {
        $this->userStateRepository->method('findByUser')->willReturn(null);
        $this->intervalCalculator->method('getSmoothedAverageInterval')->willReturn(45.0);

        $result = $this->service->getTodayTargetInterval($this->user, new \DateTime());

        // Default is 60.0 (max of default and smoothed)
        $this->assertEquals(60.0, $result);
    }

    public function testGetTodayTargetIntervalUsesSmoothedIfHigher(): void
    {
        $userState = $this->createMock(UserState::class);
        $userState->method('getCurrentTargetInterval')->willReturn(null);

        $this->userStateRepository->method('findByUser')->willReturn($userState);
        $this->intervalCalculator->method('getSmoothedAverageInterval')->willReturn(90.0);

        $result = $this->service->getTodayTargetInterval($this->user, new \DateTime());

        $this->assertEquals(90.0, $result);
    }

    // ========================================
    // Tests updateDailyTargetInterval
    // ========================================

    public function testUpdateDailyTargetIntervalIncreasesBy1Min(): void
    {
        $userState = $this->createMock(UserState::class);
        $userState->method('getCurrentTargetInterval')->willReturn(60.0);

        $this->userStateRepository->method('findByUser')->willReturn($userState);
        $this->dailyScoreRepository->method('findByUserAndDate')->willReturn(null);

        // Expect setCurrentTargetInterval to be called with 61.0
        $userState->expects($this->once())
            ->method('setCurrentTargetInterval')
            ->with(61.0);

        $result = $this->service->updateDailyTargetInterval($this->user, new \DateTime());

        $this->assertEquals(61.0, $result);
    }

    public function testUpdateDailyTargetIntervalMaintenanceDayNoIncrease(): void
    {
        $userState = $this->createMock(UserState::class);
        $userState->method('getCurrentTargetInterval')->willReturn(60.0);

        $this->userStateRepository->method('findByUser')->willReturn($userState);

        // Maintenance day should not increase
        $userState->expects($this->never())->method('setCurrentTargetInterval');

        $result = $this->service->updateDailyTargetInterval($this->user, new \DateTime(), true);

        $this->assertEquals(60.0, $result);
    }

    public function testUpdateDailyTargetIntervalBonusIfExceededTarget(): void
    {
        $userState = $this->createMock(UserState::class);
        $userState->method('getCurrentTargetInterval')->willReturn(60.0);

        $yesterdayScore = $this->createMock(DailyScore::class);
        $yesterdayScore->method('getAverageInterval')->willReturn(72.0); // 120% of target
        $yesterdayScore->method('getTargetInterval')->willReturn(60.0);

        $this->userStateRepository->method('findByUser')->willReturn($userState);
        $this->dailyScoreRepository->method('findByUserAndDate')->willReturn($yesterdayScore);

        // Should be 60 + 1 (base) + 0.5 (bonus) = 61.5
        $userState->expects($this->once())
            ->method('setCurrentTargetInterval')
            ->with(61.5);

        $result = $this->service->updateDailyTargetInterval($this->user, new \DateTime());

        $this->assertEquals(61.5, $result);
    }

    // ========================================
    // Tests initializeTargetInterval
    // ========================================

    public function testInitializeTargetIntervalWithCustomValue(): void
    {
        $userState = $this->createMock(UserState::class);

        $this->userStateRepository->method('findByUser')->willReturn($userState);

        $userState->expects($this->once())
            ->method('setCurrentTargetInterval')
            ->with(45.0);

        $result = $this->service->initializeTargetInterval($this->user, 45.0);

        $this->assertEquals(45.0, $result);
    }

    public function testInitializeTargetIntervalDefault(): void
    {
        $userState = $this->createMock(UserState::class);

        $this->userStateRepository->method('findByUser')->willReturn($userState);

        $userState->expects($this->once())
            ->method('setCurrentTargetInterval')
            ->with(60.0);

        $result = $this->service->initializeTargetInterval($this->user);

        $this->assertEquals(60.0, $result);
    }

    public function testInitializeTargetIntervalNoUserState(): void
    {
        $this->userStateRepository->method('findByUser')->willReturn(null);

        $result = $this->service->initializeTargetInterval($this->user);

        $this->assertEquals(60.0, $result);
    }

    // ========================================
    // Tests canReduceInterval
    // ========================================

    public function testCanReduceIntervalAlwaysFalse(): void
    {
        $result = $this->service->canReduceInterval($this->user, new \DateTime());

        $this->assertFalse($result);
    }
}
