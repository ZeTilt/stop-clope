<?php

namespace App\Tests\Service;

use App\Service\ScoringService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ScoringServiceTest extends TestCase
{
    #[DataProvider('pointsForDiffProvider')]
    public function testGetPointsForDiff(float $diff, float $interval, int $expectedPoints): void
    {
        $points = ScoringService::getPointsForDiff($diff, $interval);
        $this->assertEquals($expectedPoints, $points);
    }

    public static function pointsForDiffProvider(): array
    {
        return [
            // Exact timing = slight malus
            'exact timing' => [0, 60, -1],

            // Positive diff = bonus (20 points per interval)
            'waited 1 interval' => [60, 60, 20],
            'waited 2 intervals' => [120, 60, 40],
            'waited half interval' => [30, 60, 10],
            'waited small amount (min 1)' => [3, 60, 1],

            // Negative diff = malus (capped at -20)
            'smoked early 1 interval' => [-60, 60, -20],
            'smoked early half interval' => [-30, 60, -10],
            'smoked way too early (capped)' => [-180, 60, -20],

            // Edge case: very small interval
            'small interval' => [30, 30, 20],
        ];
    }

    public function testGetPointsForDiffWithZeroInterval(): void
    {
        // Should use default interval of 60
        $points = ScoringService::getPointsForDiff(60, 0);
        $this->assertEquals(20, $points);
    }

    public function testGetPointsForDiffWithNegativeInterval(): void
    {
        // Should use default interval of 60
        $points = ScoringService::getPointsForDiff(60, -30);
        $this->assertEquals(20, $points);
    }

    public function testTimeToMinutes(): void
    {
        $time1 = new \DateTime('2024-12-09 08:30:00');
        $this->assertEquals(510, ScoringService::timeToMinutes($time1)); // 8*60 + 30

        $time2 = new \DateTime('2024-12-09 00:00:00');
        $this->assertEquals(0, ScoringService::timeToMinutes($time2));

        $time3 = new \DateTime('2024-12-09 23:59:00');
        $this->assertEquals(1439, ScoringService::timeToMinutes($time3)); // 23*60 + 59
    }

    public function testMinutesSinceWakeUp(): void
    {
        $wakeTime = new \DateTime('2024-12-09 07:00:00');
        $cigTime = new \DateTime('2024-12-09 08:30:00');

        $minutes = ScoringService::minutesSinceWakeUp($cigTime, $wakeTime);
        $this->assertEquals(90, $minutes); // 1h30 = 90 min
    }

    public function testMinutesSinceWakeUpBeforeWakeUp(): void
    {
        $wakeTime = new \DateTime('2024-12-09 09:00:00');
        $cigTime = new \DateTime('2024-12-09 08:30:00');

        $minutes = ScoringService::minutesSinceWakeUp($cigTime, $wakeTime);
        $this->assertEquals(-30, $minutes); // 30 min before wake up
    }
}
