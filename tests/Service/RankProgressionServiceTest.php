<?php

namespace App\Tests\Service;

use App\Entity\User;
use App\Entity\UserState;
use App\Repository\DailyScoreRepository;
use App\Repository\UserStateRepository;
use App\Service\RankProgressionService;
use App\Service\RankService;
use PHPUnit\Framework\TestCase;

class RankProgressionServiceTest extends TestCase
{
    private RankProgressionService $service;
    private RankService $rankService;
    private UserStateRepository $userStateRepository;
    private User $user;

    protected function setUp(): void
    {
        $dailyScoreRepository = $this->createMock(DailyScoreRepository::class);
        $this->rankService = new RankService($dailyScoreRepository);
        $this->userStateRepository = $this->createMock(UserStateRepository::class);

        $this->service = new RankProgressionService(
            $this->rankService,
            $this->userStateRepository
        );

        $this->user = new User();
    }

    // ========================================
    // Tests updateUserRank
    // ========================================

    public function testUpdateUserRankNoChange(): void
    {
        $userState = $this->createMock(UserState::class);
        $userState->method('getTotalScore')->willReturn(50);
        $userState->method('getCurrentRank')->willReturn('fumeur');

        $this->userStateRepository->method('findOrCreateByUser')->willReturn($userState);

        $userState->expects($this->once())->method('setTotalScore')->with(80);
        $userState->expects($this->once())->method('setCurrentRank')->with('fumeur');

        $result = $this->service->updateUserRank($this->user, 80);

        $this->assertNull($result);
    }

    public function testUpdateUserRankWithRankUp(): void
    {
        $userState = $this->createMock(UserState::class);
        $userState->method('getTotalScore')->willReturn(50);
        $userState->method('getCurrentRank')->willReturn('fumeur');

        $this->userStateRepository->method('findOrCreateByUser')->willReturn($userState);

        $userState->expects($this->once())->method('setTotalScore')->with(150);
        $userState->expects($this->once())->method('setCurrentRank')->with('curieux');

        $result = $this->service->updateUserRank($this->user, 150);

        $this->assertNotNull($result);
        $this->assertEquals('Fumeur', $result['previous_rank']);
        $this->assertEquals('Curieux', $result['new_rank']);
        $this->assertTrue($result['is_rank_up']);
    }

    // ========================================
    // Tests addPoints
    // ========================================

    public function testAddPointsPositive(): void
    {
        $userState = $this->createMock(UserState::class);
        $userState->method('getTotalScore')->willReturn(100);
        $userState->method('getCurrentRank')->willReturn('curieux');

        $this->userStateRepository->method('findOrCreateByUser')->willReturn($userState);

        $result = $this->service->addPoints($this->user, 50);

        $this->assertEquals(100, $result['previous_score']);
        $this->assertEquals(50, $result['points_added']);
        $this->assertEquals(150, $result['new_total_score']);
        $this->assertFalse($result['rank_changed']);
    }

    public function testAddPointsNeverNegative(): void
    {
        $userState = $this->createMock(UserState::class);
        $userState->method('getTotalScore')->willReturn(30);
        $userState->method('getCurrentRank')->willReturn('fumeur');

        $this->userStateRepository->method('findOrCreateByUser')->willReturn($userState);

        $result = $this->service->addPoints($this->user, -100);

        $this->assertEquals(0, $result['new_total_score']);
    }

    // ========================================
    // Tests getProgressionInfo
    // ========================================

    public function testGetProgressionInfoBasic(): void
    {
        $userState = $this->createMock(UserState::class);
        $userState->method('getTotalScore')->willReturn(2000);

        $this->userStateRepository->method('findByUser')->willReturn($userState);

        $result = $this->service->getProgressionInfo($this->user);

        $this->assertEquals(2000, $result['total_score']);
        $this->assertEquals('Apprenti', $result['current_rank']['rank']);
        $this->assertCount(12, $result['all_ranks']);
        $this->assertGreaterThan(0, count($result['unlocked_ranks']));
        $this->assertEquals(0.02, $result['cumulative_multiplier']);
    }

    public function testGetProgressionInfoNoState(): void
    {
        $this->userStateRepository->method('findByUser')->willReturn(null);

        $result = $this->service->getProgressionInfo($this->user);

        $this->assertEquals(0, $result['total_score']);
        $this->assertEquals('Fumeur', $result['current_rank']['rank']);
    }

    // ========================================
    // Tests getUnlockedAdvantages
    // ========================================

    public function testGetUnlockedAdvantagesFumeur(): void
    {
        $result = $this->service->getUnlockedAdvantages(50);

        $this->assertEmpty($result);
    }

    public function testGetUnlockedAdvantagesCurieux(): void
    {
        $result = $this->service->getUnlockedAdvantages(150);

        $this->assertCount(1, $result);
        $this->assertEquals('history_access', $result[0]['name']);
        $this->assertEquals('Curieux', $result[0]['unlocked_at_rank']);
    }

    public function testGetUnlockedAdvantagesMultiple(): void
    {
        // Score at Débutant (500+)
        $result = $this->service->getUnlockedAdvantages(800);

        $this->assertCount(2, $result);
        $this->assertEquals('history_access', $result[0]['name']);
        $this->assertEquals('basic_stats', $result[1]['name']);
    }

    // ========================================
    // Tests hasAdvantage
    // ========================================

    public function testHasAdvantageTrue(): void
    {
        $userState = $this->createMock(UserState::class);
        $userState->method('getTotalScore')->willReturn(150);

        $this->userStateRepository->method('findByUser')->willReturn($userState);

        $result = $this->service->hasAdvantage($this->user, 'history_access');

        $this->assertTrue($result);
    }

    public function testHasAdvantageFalse(): void
    {
        $userState = $this->createMock(UserState::class);
        $userState->method('getTotalScore')->willReturn(50);

        $this->userStateRepository->method('findByUser')->willReturn($userState);

        $result = $this->service->hasAdvantage($this->user, 'history_access');

        $this->assertFalse($result);
    }
}
