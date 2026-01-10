<?php

namespace App\Tests\Service;

use App\Repository\DailyScoreRepository;
use App\Service\RankService;
use PHPUnit\Framework\TestCase;

/**
 * Tests pour le RankService v2.0
 * 12 rangs avec multiplicateurs et avantages progressifs
 */
class RankServiceTest extends TestCase
{
    private RankService $rankService;
    private DailyScoreRepository $dailyScoreRepository;

    protected function setUp(): void
    {
        $this->dailyScoreRepository = $this->createMock(DailyScoreRepository::class);
        $this->rankService = new RankService($this->dailyScoreRepository);
    }

    // ========================================
    // Tests getCurrentRank - Rangs de base
    // ========================================

    public function testGetCurrentRankFumeur(): void
    {
        $result = $this->rankService->getCurrentRank(0);

        $this->assertEquals('Fumeur', $result['rank']);
        $this->assertEquals('🚬', $result['emoji']);
        $this->assertEquals(0, $result['total_score']);
        $this->assertEquals(0, $result['current_threshold']);
        $this->assertEquals(100, $result['next_rank_threshold']);
        $this->assertEquals('Curieux', $result['next_rank']);
        $this->assertEquals(0.0, $result['multiplier_bonus']);
        $this->assertEmpty($result['advantages']);
    }

    public function testGetCurrentRankCurieux(): void
    {
        $result = $this->rankService->getCurrentRank(150);

        $this->assertEquals('Curieux', $result['rank']);
        $this->assertEquals('🔍', $result['emoji']);
        $this->assertEquals(100, $result['current_threshold']);
        $this->assertEquals(500, $result['next_rank_threshold']);
        $this->assertEquals(0.0, $result['multiplier_bonus']);
        $this->assertContains('history_access', $result['advantages']);
    }

    public function testGetCurrentRankDebutant(): void
    {
        $result = $this->rankService->getCurrentRank(800);

        $this->assertEquals('Débutant', $result['rank']);
        $this->assertEquals('🌱', $result['emoji']);
        $this->assertEquals(500, $result['current_threshold']);
        $this->assertEquals(1500, $result['next_rank_threshold']);
        $this->assertContains('basic_stats', $result['advantages']);
    }

    public function testGetCurrentRankApprenti(): void
    {
        $result = $this->rankService->getCurrentRank(2000);

        $this->assertEquals('Apprenti', $result['rank']);
        $this->assertEquals('📚', $result['emoji']);
        $this->assertEquals(1500, $result['current_threshold']);
        $this->assertEquals(3500, $result['next_rank_threshold']);
        $this->assertEquals(0.02, $result['multiplier_bonus']);
    }

    public function testGetCurrentRankInitie(): void
    {
        $result = $this->rankService->getCurrentRank(5000);

        $this->assertEquals('Initié', $result['rank']);
        $this->assertEquals('🎯', $result['emoji']);
        $this->assertEquals(3500, $result['current_threshold']);
        $this->assertContains('week_stats', $result['advantages']);
    }

    public function testGetCurrentRankConfirme(): void
    {
        $result = $this->rankService->getCurrentRank(10000);

        $this->assertEquals('Confirmé', $result['rank']);
        $this->assertEquals('💪', $result['emoji']);
        $this->assertEquals(7500, $result['current_threshold']);
        $this->assertEquals(0.05, $result['multiplier_bonus']);
    }

    public function testGetCurrentRankAvance(): void
    {
        $result = $this->rankService->getCurrentRank(20000);

        $this->assertEquals('Avancé', $result['rank']);
        $this->assertEquals('⚔️', $result['emoji']);
        $this->assertEquals(15000, $result['current_threshold']);
        $this->assertContains('extra_maintenance', $result['advantages']);
    }

    public function testGetCurrentRankExpert(): void
    {
        $result = $this->rankService->getCurrentRank(45000);

        $this->assertEquals('Expert', $result['rank']);
        $this->assertEquals('🏆', $result['emoji']);
        $this->assertEquals(30000, $result['current_threshold']);
        $this->assertEquals(0.08, $result['multiplier_bonus']);
    }

    public function testGetCurrentRankMaitre(): void
    {
        $result = $this->rankService->getCurrentRank(90000);

        $this->assertEquals('Maître', $result['rank']);
        $this->assertEquals('🦸', $result['emoji']);
        $this->assertEquals(60000, $result['current_threshold']);
        $this->assertContains('advanced_stats', $result['advantages']);
    }

    public function testGetCurrentRankGrandMaitre(): void
    {
        $result = $this->rankService->getCurrentRank(150000);

        $this->assertEquals('Grand Maître', $result['rank']);
        $this->assertEquals('👑', $result['emoji']);
        $this->assertEquals(120000, $result['current_threshold']);
        $this->assertEquals(0.12, $result['multiplier_bonus']);
    }

    public function testGetCurrentRankSage(): void
    {
        $result = $this->rankService->getCurrentRank(280000);

        $this->assertEquals('Sage', $result['rank']);
        $this->assertEquals('🧘', $result['emoji']);
        $this->assertEquals(200000, $result['current_threshold']);
        $this->assertContains('monthly_shield', $result['advantages']);
    }

    public function testGetCurrentRankLegende(): void
    {
        $result = $this->rankService->getCurrentRank(400000);

        $this->assertEquals('Légende', $result['rank']);
        $this->assertEquals('⭐', $result['emoji']);
        $this->assertNull($result['next_rank_threshold']);
        $this->assertNull($result['next_rank']);
        $this->assertEquals(100, $result['progress']);
        $this->assertContains('exclusive_theme', $result['advantages']);
    }

    public function testGetCurrentRankExactThreshold(): void
    {
        // Exactly at Curieux threshold
        $result = $this->rankService->getCurrentRank(100);
        $this->assertEquals('Curieux', $result['rank']);
    }

    public function testGetCurrentRankFromRepository(): void
    {
        $this->dailyScoreRepository->method('getTotalScore')->willReturn(2500);

        $result = $this->rankService->getCurrentRank();

        $this->assertEquals('Apprenti', $result['rank']);
        $this->assertEquals(2500, $result['total_score']);
    }

    public function testGetCurrentRankProgress(): void
    {
        // Halfway between Fumeur (0) and Curieux (100)
        $result = $this->rankService->getCurrentRank(50);

        $this->assertEquals('Fumeur', $result['rank']);
        $this->assertEquals(50, $result['progress']);
    }

    // ========================================
    // Tests getNextRank
    // ========================================

    public function testGetNextRankFromFumeur(): void
    {
        $result = $this->rankService->getNextRank('Fumeur');
        $this->assertEquals('Curieux', $result);
    }

    public function testGetNextRankFromSage(): void
    {
        $result = $this->rankService->getNextRank('Sage');
        $this->assertEquals('Légende', $result);
    }

    public function testGetNextRankFromMax(): void
    {
        $result = $this->rankService->getNextRank('Légende');
        $this->assertNull($result);
    }

    public function testGetNextRankUnknown(): void
    {
        $result = $this->rankService->getNextRank('Unknown Rank');
        $this->assertNull($result);
    }

    // ========================================
    // Tests getAllRanks
    // ========================================

    public function testGetAllRanks(): void
    {
        $result = $this->rankService->getAllRanks();

        $this->assertIsArray($result);
        $this->assertCount(12, $result);

        // Check structure
        foreach ($result as $rank) {
            $this->assertArrayHasKey('rank', $rank);
            $this->assertArrayHasKey('emoji', $rank);
            $this->assertArrayHasKey('threshold', $rank);
            $this->assertArrayHasKey('multiplier', $rank);
            $this->assertArrayHasKey('advantages', $rank);
        }

        // Check first and last
        $this->assertEquals('Fumeur', $result[0]['rank']);
        $this->assertEquals(0, $result[0]['threshold']);
        $this->assertEquals('Légende', $result[11]['rank']);
        $this->assertEquals(350000, $result[11]['threshold']);
    }

    // ========================================
    // Tests checkRankUp
    // ========================================

    public function testCheckRankUpNoChange(): void
    {
        $result = $this->rankService->checkRankUp(50, 80);
        $this->assertNull($result); // Both are Fumeur
    }

    public function testCheckRankUpToCurieux(): void
    {
        $result = $this->rankService->checkRankUp(80, 120);

        $this->assertNotNull($result);
        $this->assertEquals('Fumeur', $result['previous_rank']);
        $this->assertEquals('Curieux', $result['new_rank']);
        $this->assertEquals('🔍', $result['new_emoji']);
        $this->assertTrue($result['is_rank_up']);
        $this->assertEquals(0.0, $result['new_multiplier']);
        $this->assertContains('history_access', $result['new_advantages']);
    }

    public function testCheckRankDown(): void
    {
        // Score can go down (negative daily scores)
        $result = $this->rankService->checkRankUp(150, 50);

        $this->assertNotNull($result);
        $this->assertEquals('Curieux', $result['previous_rank']);
        $this->assertEquals('Fumeur', $result['new_rank']);
        $this->assertFalse($result['is_rank_up']);
    }

    public function testCheckRankUpMultipleLevels(): void
    {
        // Jump from Fumeur to Apprenti
        $result = $this->rankService->checkRankUp(50, 2000);

        $this->assertNotNull($result);
        $this->assertEquals('Fumeur', $result['previous_rank']);
        $this->assertEquals('Apprenti', $result['new_rank']);
        $this->assertTrue($result['is_rank_up']);
        $this->assertEquals(0.02, $result['new_multiplier']);
    }

    // ========================================
    // Tests getScoreForRank
    // ========================================

    public function testGetScoreForRankFumeur(): void
    {
        $result = $this->rankService->getScoreForRank('Fumeur');
        $this->assertEquals(0, $result);
    }

    public function testGetScoreForRankApprenti(): void
    {
        $result = $this->rankService->getScoreForRank('Apprenti');
        $this->assertEquals(1500, $result);
    }

    public function testGetScoreForRankLegende(): void
    {
        $result = $this->rankService->getScoreForRank('Légende');
        $this->assertEquals(350000, $result);
    }

    public function testGetScoreForRankUnknown(): void
    {
        $result = $this->rankService->getScoreForRank('Unknown');
        $this->assertNull($result);
    }

    // ========================================
    // Tests points_to_next
    // ========================================

    public function testPointsToNextRank(): void
    {
        $result = $this->rankService->getCurrentRank(80);

        $this->assertEquals(20, $result['points_to_next']); // 100 - 80
    }

    public function testPointsToNextRankAtMax(): void
    {
        $result = $this->rankService->getCurrentRank(400000);

        $this->assertEquals(0, $result['points_to_next']);
    }

    // ========================================
    // Tests hasAdvantage
    // ========================================

    public function testHasAdvantageTrue(): void
    {
        // Curieux has history_access
        $result = $this->rankService->hasAdvantage(150, 'history_access');
        $this->assertTrue($result);
    }

    public function testHasAdvantageFalse(): void
    {
        // Fumeur has no advantages
        $result = $this->rankService->hasAdvantage(50, 'history_access');
        $this->assertFalse($result);
    }

    public function testHasAdvantageNotUnlocked(): void
    {
        // Curieux doesn't have advanced_stats (Maître unlocks it)
        $result = $this->rankService->hasAdvantage(150, 'advanced_stats');
        $this->assertFalse($result);
    }

    // ========================================
    // Tests getCumulativeMultiplier
    // ========================================

    public function testGetCumulativeMultiplierFumeur(): void
    {
        // Fumeur: 0.0
        $result = $this->rankService->getCumulativeMultiplier(50);
        $this->assertEquals(0.0, $result);
    }

    public function testGetCumulativeMultiplierApprenti(): void
    {
        // Fumeur(0) + Curieux(0) + Débutant(0) + Apprenti(0.02) = 0.02
        $result = $this->rankService->getCumulativeMultiplier(2000);
        $this->assertEquals(0.02, $result);
    }

    public function testGetCumulativeMultiplierConfirme(): void
    {
        // 0 + 0 + 0 + 0.02 + 0 + 0.05 = 0.07
        $result = $this->rankService->getCumulativeMultiplier(10000);
        $this->assertEquals(0.07, $result);
    }

    public function testGetCumulativeMultiplierGrandMaitre(): void
    {
        // 0 + 0 + 0 + 0.02 + 0 + 0.05 + 0 + 0.08 + 0 + 0.12 = 0.27
        $result = $this->rankService->getCumulativeMultiplier(150000);
        $this->assertEquals(0.27, $result);
    }
}
