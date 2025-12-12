<?php

namespace App\Tests\Service;

use App\Repository\DailyScoreRepository;
use App\Service\RankService;
use PHPUnit\Framework\TestCase;

class RankServiceTest extends TestCase
{
    private RankService $rankService;
    private DailyScoreRepository $dailyScoreRepository;

    protected function setUp(): void
    {
        $this->dailyScoreRepository = $this->createMock(DailyScoreRepository::class);
        $this->rankService = new RankService($this->dailyScoreRepository);
    }

    public function testGetCurrentRankDebutant(): void
    {
        $result = $this->rankService->getCurrentRank(0);

        $this->assertEquals('Débutant', $result['rank']);
        $this->assertEquals('🌱', $result['emoji']);
        $this->assertEquals(0, $result['total_score']);
        $this->assertEquals(0, $result['current_threshold']);
        $this->assertEquals(101, $result['next_rank_threshold']);
        $this->assertEquals('Apprenti', $result['next_rank']);
    }

    public function testGetCurrentRankApprenti(): void
    {
        $result = $this->rankService->getCurrentRank(150);

        $this->assertEquals('Apprenti', $result['rank']);
        $this->assertEquals('📚', $result['emoji']);
        $this->assertEquals(101, $result['current_threshold']);
        $this->assertEquals(301, $result['next_rank_threshold']);
    }

    public function testGetCurrentRankResistant(): void
    {
        $result = $this->rankService->getCurrentRank(400);

        $this->assertEquals('Résistant', $result['rank']);
        $this->assertEquals('💪', $result['emoji']);
    }

    public function testGetCurrentRankGuerrier(): void
    {
        $result = $this->rankService->getCurrentRank(700);

        $this->assertEquals('Guerrier', $result['rank']);
        $this->assertEquals('⚔️', $result['emoji']);
    }

    public function testGetCurrentRankChampion(): void
    {
        $result = $this->rankService->getCurrentRank(1200);

        $this->assertEquals('Champion', $result['rank']);
        $this->assertEquals('🏆', $result['emoji']);
    }

    public function testGetCurrentRankHeros(): void
    {
        $result = $this->rankService->getCurrentRank(2000);

        $this->assertEquals('Héros', $result['rank']);
        $this->assertEquals('🦸', $result['emoji']);
    }

    public function testGetCurrentRankLegende(): void
    {
        $result = $this->rankService->getCurrentRank(3000);

        $this->assertEquals('Légende', $result['rank']);
        $this->assertEquals('⭐', $result['emoji']);
    }

    public function testGetCurrentRankMaitreDuSouffle(): void
    {
        $result = $this->rankService->getCurrentRank(5000);

        $this->assertEquals('Maître du souffle', $result['rank']);
        $this->assertEquals('🧘', $result['emoji']);
        $this->assertNull($result['next_rank_threshold']);
        $this->assertNull($result['next_rank']);
        $this->assertEquals(100, $result['progress']);
    }

    public function testGetCurrentRankExactThreshold(): void
    {
        // Exactly at Apprenti threshold
        $result = $this->rankService->getCurrentRank(101);
        $this->assertEquals('Apprenti', $result['rank']);
    }

    public function testGetCurrentRankFromRepository(): void
    {
        $this->dailyScoreRepository->method('getTotalScore')->willReturn(500);

        $result = $this->rankService->getCurrentRank();

        $this->assertEquals('Résistant', $result['rank']);
        $this->assertEquals(500, $result['total_score']);
    }

    public function testGetCurrentRankProgress(): void
    {
        // Halfway between Débutant (0) and Apprenti (101)
        $result = $this->rankService->getCurrentRank(50);

        $this->assertEquals('Débutant', $result['rank']);
        $this->assertGreaterThan(45, $result['progress']);
        $this->assertLessThan(55, $result['progress']);
    }

    public function testGetNextRankFromDebutant(): void
    {
        $result = $this->rankService->getNextRank('Débutant');
        $this->assertEquals('Apprenti', $result);
    }

    public function testGetNextRankFromLegende(): void
    {
        $result = $this->rankService->getNextRank('Légende');
        $this->assertEquals('Maître du souffle', $result);
    }

    public function testGetNextRankFromMax(): void
    {
        $result = $this->rankService->getNextRank('Maître du souffle');
        $this->assertNull($result);
    }

    public function testGetNextRankUnknown(): void
    {
        $result = $this->rankService->getNextRank('Unknown Rank');
        $this->assertNull($result);
    }

    public function testGetAllRanks(): void
    {
        $result = $this->rankService->getAllRanks();

        $this->assertIsArray($result);
        $this->assertCount(8, $result);

        // Check structure
        foreach ($result as $rank) {
            $this->assertArrayHasKey('rank', $rank);
            $this->assertArrayHasKey('emoji', $rank);
            $this->assertArrayHasKey('threshold', $rank);
        }

        // Check first and last
        $this->assertEquals('Débutant', $result[0]['rank']);
        $this->assertEquals(0, $result[0]['threshold']);
        $this->assertEquals('Maître du souffle', $result[7]['rank']);
        $this->assertEquals(4001, $result[7]['threshold']);
    }

    public function testCheckRankUpNoChange(): void
    {
        $result = $this->rankService->checkRankUp(50, 80);
        $this->assertNull($result); // Both are Débutant
    }

    public function testCheckRankUpToApprenti(): void
    {
        $result = $this->rankService->checkRankUp(90, 120);

        $this->assertNotNull($result);
        $this->assertEquals('Débutant', $result['previous_rank']);
        $this->assertEquals('Apprenti', $result['new_rank']);
        $this->assertEquals('📚', $result['new_emoji']);
        $this->assertTrue($result['is_rank_up']);
    }

    public function testCheckRankDown(): void
    {
        // Score can go down (negative daily scores)
        $result = $this->rankService->checkRankUp(150, 50);

        $this->assertNotNull($result);
        $this->assertEquals('Apprenti', $result['previous_rank']);
        $this->assertEquals('Débutant', $result['new_rank']);
        $this->assertFalse($result['is_rank_up']);
    }

    public function testCheckRankUpMultipleLevels(): void
    {
        // Jump from Débutant to Guerrier
        $result = $this->rankService->checkRankUp(50, 700);

        $this->assertNotNull($result);
        $this->assertEquals('Débutant', $result['previous_rank']);
        $this->assertEquals('Guerrier', $result['new_rank']);
        $this->assertTrue($result['is_rank_up']);
    }

    public function testGetScoreForRankDebutant(): void
    {
        $result = $this->rankService->getScoreForRank('Débutant');
        $this->assertEquals(0, $result);
    }

    public function testGetScoreForRankApprenti(): void
    {
        $result = $this->rankService->getScoreForRank('Apprenti');
        $this->assertEquals(101, $result);
    }

    public function testGetScoreForRankMaitre(): void
    {
        $result = $this->rankService->getScoreForRank('Maître du souffle');
        $this->assertEquals(4001, $result);
    }

    public function testGetScoreForRankUnknown(): void
    {
        $result = $this->rankService->getScoreForRank('Unknown');
        $this->assertNull($result);
    }

    public function testPointsToNextRank(): void
    {
        $result = $this->rankService->getCurrentRank(80);

        $this->assertEquals(21, $result['points_to_next']); // 101 - 80
    }

    public function testPointsToNextRankAtMax(): void
    {
        $result = $this->rankService->getCurrentRank(5000);

        $this->assertEquals(0, $result['points_to_next']);
    }
}
