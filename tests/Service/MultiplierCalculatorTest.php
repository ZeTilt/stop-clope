<?php

namespace App\Tests\Service;

use App\Entity\User;
use App\Entity\UserState;
use App\Repository\ActiveBonusRepository;
use App\Repository\UserStateRepository;
use App\Service\MultiplierCalculator;
use App\Service\RankService;
use PHPUnit\Framework\TestCase;

/**
 * Tests pour le MultiplierCalculator v2.0
 * Zone-based scoring multipliers
 */
class MultiplierCalculatorTest extends TestCase
{
    private MultiplierCalculator $calculator;
    private UserStateRepository $userStateRepository;
    private ActiveBonusRepository $activeBonusRepository;
    private RankService $rankService;
    private User $user;

    protected function setUp(): void
    {
        $this->userStateRepository = $this->createMock(UserStateRepository::class);
        $this->activeBonusRepository = $this->createMock(ActiveBonusRepository::class);
        $this->rankService = $this->createMock(RankService::class);

        $this->calculator = new MultiplierCalculator(
            $this->userStateRepository,
            $this->activeBonusRepository,
            $this->rankService
        );

        $this->user = new User();
    }

    // ========================================
    // Tests getZoneMultiplier - Zone négative (avant cible)
    // ========================================

    public function testZoneMultiplierNegativeTier1(): void
    {
        // 0-10 min avant la cible: ×1.0
        $this->assertEquals(1.0, $this->calculator->getZoneMultiplier(-5));
        $this->assertEquals(1.0, $this->calculator->getZoneMultiplier(-10));
    }

    public function testZoneMultiplierNegativeTier2(): void
    {
        // 10-20 min avant la cible: ×1.5
        $this->assertEquals(1.5, $this->calculator->getZoneMultiplier(-15));
        $this->assertEquals(1.5, $this->calculator->getZoneMultiplier(-20));
    }

    public function testZoneMultiplierNegativeTier3(): void
    {
        // 20+ min avant la cible: ×2.0
        $this->assertEquals(2.0, $this->calculator->getZoneMultiplier(-25));
        $this->assertEquals(2.0, $this->calculator->getZoneMultiplier(-60));
    }

    // ========================================
    // Tests getZoneMultiplier - Zone positive (après cible)
    // ========================================

    public function testZoneMultiplierPositiveTier1(): void
    {
        // 0-30 min après la cible: ×1.0
        $this->assertEquals(1.0, $this->calculator->getZoneMultiplier(0));
        $this->assertEquals(1.0, $this->calculator->getZoneMultiplier(15));
        $this->assertEquals(1.0, $this->calculator->getZoneMultiplier(30));
    }

    public function testZoneMultiplierPositiveTier2(): void
    {
        // 30-60 min après la cible: ×1.2
        $this->assertEquals(1.2, $this->calculator->getZoneMultiplier(45));
        $this->assertEquals(1.2, $this->calculator->getZoneMultiplier(60));
    }

    public function testZoneMultiplierPositiveTier3(): void
    {
        // 60+ min après la cible: ×1.5
        $this->assertEquals(1.5, $this->calculator->getZoneMultiplier(90));
        $this->assertEquals(1.5, $this->calculator->getZoneMultiplier(120));
    }

    // ========================================
    // Tests getTotalMultiplier
    // ========================================

    public function testGetTotalMultiplierBasic(): void
    {
        $userState = $this->createMock(UserState::class);
        $userState->method('getTotalScore')->willReturn(0);
        $userState->method('getPermanentMultiplier')->willReturn(0.0);

        $this->userStateRepository->method('findByUser')->willReturn($userState);
        $this->rankService->method('getCurrentRank')->willReturn([
            'multiplier_bonus' => 0.0,
        ]);

        // Zone ×1.0, base 1.0, no bonuses = 1.0
        $result = $this->calculator->getTotalMultiplier($this->user, 10);
        $this->assertEquals(1.0, $result);
    }

    public function testGetTotalMultiplierWithRankBonus(): void
    {
        $userState = $this->createMock(UserState::class);
        $userState->method('getTotalScore')->willReturn(2000);
        $userState->method('getPermanentMultiplier')->willReturn(0.0);

        $this->userStateRepository->method('findByUser')->willReturn($userState);
        $this->rankService->method('getCurrentRank')->willReturn([
            'multiplier_bonus' => 0.02, // Apprenti
        ]);

        // Zone ×1.0 (positive 0-30), base 1.0 + 0.02 = 1.02
        $result = $this->calculator->getTotalMultiplier($this->user, 10);
        $this->assertEquals(1.02, $result);
    }

    public function testGetTotalMultiplierWithPermanentBonus(): void
    {
        $userState = $this->createMock(UserState::class);
        $userState->method('getTotalScore')->willReturn(0);
        $userState->method('getPermanentMultiplier')->willReturn(0.05);

        $this->userStateRepository->method('findByUser')->willReturn($userState);
        $this->rankService->method('getCurrentRank')->willReturn([
            'multiplier_bonus' => 0.0,
        ]);

        // Zone ×1.0, base 1.0 + 0.05 = 1.05
        $result = $this->calculator->getTotalMultiplier($this->user, 10);
        $this->assertEquals(1.05, $result);
    }

    public function testGetTotalMultiplierCombined(): void
    {
        $userState = $this->createMock(UserState::class);
        $userState->method('getTotalScore')->willReturn(10000);
        $userState->method('getPermanentMultiplier')->willReturn(0.03);

        $this->userStateRepository->method('findByUser')->willReturn($userState);
        $this->rankService->method('getCurrentRank')->willReturn([
            'multiplier_bonus' => 0.05, // Confirmé
        ]);

        // Zone ×1.5 (negative 10-20), base 1.0 + 0.05 + 0.03 = 1.08
        // Total = 1.5 × 1.08 = 1.62
        $result = $this->calculator->getTotalMultiplier($this->user, -15);
        $this->assertEquals(1.62, $result);
    }

    public function testGetTotalMultiplierNoUserState(): void
    {
        $this->userStateRepository->method('findByUser')->willReturn(null);
        $this->rankService->method('getCurrentRank')->willReturn([
            'multiplier_bonus' => 0.0,
        ]);

        // Zone ×1.0, base 1.0, no state = 1.0
        $result = $this->calculator->getTotalMultiplier($this->user, 10);
        $this->assertEquals(1.0, $result);
    }

    // ========================================
    // Tests calculatePoints
    // ========================================

    public function testCalculatePointsPositive(): void
    {
        $userState = $this->createMock(UserState::class);
        $userState->method('getTotalScore')->willReturn(0);
        $userState->method('getPermanentMultiplier')->willReturn(0.0);

        $this->userStateRepository->method('findByUser')->willReturn($userState);
        $this->rankService->method('getCurrentRank')->willReturn([
            'multiplier_bonus' => 0.0,
        ]);
        $this->activeBonusRepository->method('findActiveByUserAndType')->willReturn([]);

        // 30 min après la cible × 1.0 = +30 points
        $points = $this->calculator->calculatePoints($this->user, 30);
        $this->assertEquals(30, $points);
    }

    public function testCalculatePointsNegative(): void
    {
        $userState = $this->createMock(UserState::class);
        $userState->method('getTotalScore')->willReturn(0);
        $userState->method('getPermanentMultiplier')->willReturn(0.0);

        $this->userStateRepository->method('findByUser')->willReturn($userState);
        $this->rankService->method('getCurrentRank')->willReturn([
            'multiplier_bonus' => 0.0,
        ]);
        $this->activeBonusRepository->method('findActiveByUserAndType')->willReturn([]);

        // 30 min avant la cible × 2.0 = -60 points
        $points = $this->calculator->calculatePoints($this->user, -30);
        $this->assertEquals(-60, $points);
    }

    public function testCalculatePointsWithMultipliers(): void
    {
        $userState = $this->createMock(UserState::class);
        $userState->method('getTotalScore')->willReturn(10000);
        $userState->method('getPermanentMultiplier')->willReturn(0.0);

        $this->userStateRepository->method('findByUser')->willReturn($userState);
        $this->rankService->method('getCurrentRank')->willReturn([
            'multiplier_bonus' => 0.05,
        ]);
        $this->activeBonusRepository->method('findActiveByUserAndType')->willReturn([]);

        // 90 min après la cible: zone ×1.5, base ×1.05 = 1.575
        // 90 × 1.575 = 141.75 → 142
        $points = $this->calculator->calculatePoints($this->user, 90);
        $this->assertEquals(142, $points);
    }

    public function testCalculatePointsExactlyOnTarget(): void
    {
        $userState = $this->createMock(UserState::class);
        $userState->method('getTotalScore')->willReturn(0);
        $userState->method('getPermanentMultiplier')->willReturn(0.0);

        $this->userStateRepository->method('findByUser')->willReturn($userState);
        $this->rankService->method('getCurrentRank')->willReturn([
            'multiplier_bonus' => 0.0,
        ]);
        $this->activeBonusRepository->method('findActiveByUserAndType')->willReturn([]);

        // Exactly on target = 0 points
        $points = $this->calculator->calculatePoints($this->user, 0);
        $this->assertEquals(0, $points);
    }

    public function testCalculatePointsWithTemporaryBonus(): void
    {
        $userState = $this->createMock(UserState::class);
        $userState->method('getTotalScore')->willReturn(0);
        $userState->method('getPermanentMultiplier')->willReturn(0.0);

        $this->userStateRepository->method('findByUser')->willReturn($userState);
        $this->rankService->method('getCurrentRank')->willReturn([
            'multiplier_bonus' => 0.0,
        ]);

        // Mock temporary bonus: 10% score_percent
        $bonus = $this->createMock(\App\Entity\ActiveBonus::class);
        $bonus->method('getBonusValue')->willReturn(10.0);

        $this->activeBonusRepository->method('findActiveByUserAndType')
            ->with($this->user, \App\Entity\ActiveBonus::TYPE_SCORE_PERCENT)
            ->willReturn([$bonus]);

        // 30 min après la cible × 1.0 × 1.10 (temp bonus) = 33 points
        $points = $this->calculator->calculatePoints($this->user, 30);
        $this->assertEquals(33, $points);
    }

    public function testCalculatePointsNegativeNoTempBonus(): void
    {
        $userState = $this->createMock(UserState::class);
        $userState->method('getTotalScore')->willReturn(0);
        $userState->method('getPermanentMultiplier')->willReturn(0.0);

        $this->userStateRepository->method('findByUser')->willReturn($userState);
        $this->rankService->method('getCurrentRank')->willReturn([
            'multiplier_bonus' => 0.0,
        ]);

        // Even with 10% bonus, negative points should NOT be boosted
        $bonus = $this->createMock(\App\Entity\ActiveBonus::class);
        $bonus->method('getBonusValue')->willReturn(10.0);

        $this->activeBonusRepository->method('findActiveByUserAndType')
            ->with($this->user, \App\Entity\ActiveBonus::TYPE_SCORE_PERCENT)
            ->willReturn([$bonus]);

        // 15 min avant la cible × 1.5 = -22.5 → -23 (temp bonus NOT applied to negatives)
        $points = $this->calculator->calculatePoints($this->user, -15);
        $this->assertEquals(-23, $points);
    }

    // ========================================
    // Tests getTemporaryScoreBonus
    // ========================================

    public function testGetTemporaryScoreBonusEmpty(): void
    {
        $this->activeBonusRepository->method('findActiveByUserAndType')
            ->with($this->user, \App\Entity\ActiveBonus::TYPE_SCORE_PERCENT)
            ->willReturn([]);

        $bonus = $this->calculator->getTemporaryScoreBonus($this->user);
        $this->assertEquals(0.0, $bonus);
    }

    public function testGetTemporaryScoreBonusSingle(): void
    {
        $bonus = $this->createMock(\App\Entity\ActiveBonus::class);
        $bonus->method('getBonusValue')->willReturn(5.0);

        $this->activeBonusRepository->method('findActiveByUserAndType')
            ->with($this->user, \App\Entity\ActiveBonus::TYPE_SCORE_PERCENT)
            ->willReturn([$bonus]);

        $result = $this->calculator->getTemporaryScoreBonus($this->user);
        $this->assertEquals(0.05, $result); // 5% -> 0.05
    }

    public function testGetTemporaryScoreBonusMultiple(): void
    {
        $bonus1 = $this->createMock(\App\Entity\ActiveBonus::class);
        $bonus1->method('getBonusValue')->willReturn(5.0);

        $bonus2 = $this->createMock(\App\Entity\ActiveBonus::class);
        $bonus2->method('getBonusValue')->willReturn(10.0);

        $this->activeBonusRepository->method('findActiveByUserAndType')
            ->with($this->user, \App\Entity\ActiveBonus::TYPE_SCORE_PERCENT)
            ->willReturn([$bonus1, $bonus2]);

        $result = $this->calculator->getTemporaryScoreBonus($this->user);
        $this->assertEquals(0.15, $result); // 5% + 10% = 15% -> 0.15
    }

    // ========================================
    // Tests getRankMultiplierBonus
    // ========================================

    public function testGetRankMultiplierBonusNoState(): void
    {
        $this->userStateRepository->method('findByUser')->willReturn(null);
        $this->rankService->method('getCurrentRank')->with(0)->willReturn([
            'multiplier_bonus' => 0.0,
        ]);

        $bonus = $this->calculator->getRankMultiplierBonus($this->user);
        $this->assertEquals(0.0, $bonus);
    }

    public function testGetRankMultiplierBonusWithState(): void
    {
        $userState = $this->createMock(UserState::class);
        $userState->method('getTotalScore')->willReturn(10000);

        $this->userStateRepository->method('findByUser')->willReturn($userState);
        $this->rankService->method('getCurrentRank')->with(10000)->willReturn([
            'multiplier_bonus' => 0.05,
        ]);

        $bonus = $this->calculator->getRankMultiplierBonus($this->user);
        $this->assertEquals(0.05, $bonus);
    }

    // ========================================
    // Tests getActiveMultiplierBonuses
    // ========================================

    public function testGetActiveMultiplierBonusesEmpty(): void
    {
        $this->activeBonusRepository->method('findActiveByUserAndType')->willReturn([]);

        $bonuses = $this->calculator->getActiveMultiplierBonuses($this->user);
        $this->assertEmpty($bonuses);
    }
}
