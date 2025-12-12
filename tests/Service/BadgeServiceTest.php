<?php

namespace App\Tests\Service;

use App\Service\BadgeService;
use PHPUnit\Framework\TestCase;

class BadgeServiceTest extends TestCase
{
    public function testBadgesConstantExists(): void
    {
        $this->assertIsArray(BadgeService::BADGES);
        $this->assertNotEmpty(BadgeService::BADGES);
    }

    public function testAllBadgesHaveRequiredFields(): void
    {
        foreach (BadgeService::BADGES as $code => $badge) {
            $this->assertArrayHasKey('name', $badge, "Badge {$code} missing 'name'");
            $this->assertArrayHasKey('description', $badge, "Badge {$code} missing 'description'");
            $this->assertArrayHasKey('icon', $badge, "Badge {$code} missing 'icon'");
            $this->assertArrayHasKey('category', $badge, "Badge {$code} missing 'category'");
        }
    }

    public function testBadgeCategoriesAreValid(): void
    {
        $validCategories = ['milestone', 'streak', 'savings', 'reduction'];

        foreach (BadgeService::BADGES as $code => $badge) {
            $this->assertContains(
                $badge['category'],
                $validCategories,
                "Badge {$code} has invalid category: {$badge['category']}"
            );
        }
    }

    public function testExpectedBadgesExist(): void
    {
        $expectedBadges = [
            'first_step',
            'week_streak',
            'two_weeks',
            'month_streak',
            'saver_10',
            'saver_50',
            'saver_100',
            'reducer_25',
            'reducer_50',
            'reducer_75',
            'zero_day',
            'champion',
        ];

        foreach ($expectedBadges as $code) {
            $this->assertArrayHasKey($code, BadgeService::BADGES, "Expected badge '{$code}' not found");
        }
    }

    public function testFirstStepBadge(): void
    {
        $badge = BadgeService::BADGES['first_step'];

        $this->assertEquals('Premier pas', $badge['name']);
        $this->assertEquals('👣', $badge['icon']);
        $this->assertEquals('milestone', $badge['category']);
    }

    public function testWeekStreakBadge(): void
    {
        $badge = BadgeService::BADGES['week_streak'];

        $this->assertEquals('Une semaine', $badge['name']);
        $this->assertEquals('🔥', $badge['icon']);
        $this->assertEquals('streak', $badge['category']);
    }

    public function testSaverBadges(): void
    {
        $this->assertEquals('savings', BadgeService::BADGES['saver_10']['category']);
        $this->assertEquals('savings', BadgeService::BADGES['saver_50']['category']);
        $this->assertEquals('savings', BadgeService::BADGES['saver_100']['category']);
    }

    public function testReducerBadges(): void
    {
        $this->assertEquals('reduction', BadgeService::BADGES['reducer_25']['category']);
        $this->assertEquals('reduction', BadgeService::BADGES['reducer_50']['category']);
        $this->assertEquals('reduction', BadgeService::BADGES['reducer_75']['category']);
    }

    public function testZeroDayBadge(): void
    {
        $badge = BadgeService::BADGES['zero_day'];

        $this->assertEquals('Jour parfait', $badge['name']);
        $this->assertEquals('⭐', $badge['icon']);
        $this->assertEquals('milestone', $badge['category']);
    }

    public function testChampionBadge(): void
    {
        $badge = BadgeService::BADGES['champion'];

        $this->assertEquals('Champion', $badge['name']);
        $this->assertEquals('🏆', $badge['icon']);
        $this->assertEquals('milestone', $badge['category']);
    }

    public function testBadgeIconsAreEmojis(): void
    {
        foreach (BadgeService::BADGES as $code => $badge) {
            // Simple check: icon should be non-empty and short
            $this->assertNotEmpty($badge['icon'], "Badge {$code} has empty icon");
            $this->assertLessThan(10, strlen($badge['icon']), "Badge {$code} icon too long");
        }
    }

    public function testBadgeDescriptionsAreNotEmpty(): void
    {
        foreach (BadgeService::BADGES as $code => $badge) {
            $this->assertNotEmpty($badge['description'], "Badge {$code} has empty description");
        }
    }

    public function testBadgeNamesAreUnique(): void
    {
        $names = array_column(BadgeService::BADGES, 'name');
        $uniqueNames = array_unique($names);

        $this->assertCount(count($names), $uniqueNames, 'Badge names are not unique');
    }
}
