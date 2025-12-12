<?php

namespace App\Tests\Service;

use App\Service\IntervalCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class IntervalCalculatorTest extends TestCase
{
    #[DataProvider('pointsForDiffProvider')]
    public function testGetPointsForDiff(float $diff, float $interval, int $expectedPoints): void
    {
        $points = IntervalCalculator::getPointsForDiff($diff, $interval);
        $this->assertEquals($expectedPoints, $points);
    }

    public static function pointsForDiffProvider(): array
    {
        return [
            // Exact timing = slight malus
            'exact timing' => [0, 60, -1],
            'near exact timing' => [0.0001, 60, -1],

            // Positive diff = bonus (20 points per interval)
            'waited 1 interval' => [60, 60, 20],
            'waited 2 intervals' => [120, 60, 40],
            'waited 3 intervals' => [180, 60, 60],
            'waited half interval' => [30, 60, 10],
            'waited quarter interval' => [15, 60, 5],
            'waited small amount (min 1)' => [3, 60, 1],
            'waited tiny amount (min 1)' => [0.5, 60, 1],

            // Negative diff = malus (capped at -20)
            'smoked early 1 interval' => [-60, 60, -20],
            'smoked early half interval' => [-30, 60, -10],
            'smoked early quarter interval' => [-15, 60, -5],
            'smoked way too early (capped at -20)' => [-180, 60, -20],
            'smoked very early (capped at -20)' => [-300, 60, -20],

            // Different intervals
            'small interval positive' => [30, 30, 20],
            'small interval negative' => [-30, 30, -20],
            'large interval positive' => [120, 120, 20],
            'large interval negative' => [-60, 120, -10],
        ];
    }

    public function testGetPointsForDiffWithZeroInterval(): void
    {
        // Should use default interval of 60
        $points = IntervalCalculator::getPointsForDiff(60, 0);
        $this->assertEquals(20, $points);
    }

    public function testGetPointsForDiffWithNegativeInterval(): void
    {
        // Should use default interval of 60
        $points = IntervalCalculator::getPointsForDiff(60, -30);
        $this->assertEquals(20, $points);
    }

    public function testTimeToMinutes(): void
    {
        $time1 = new \DateTime('2024-12-09 08:30:00');
        $this->assertEquals(510, IntervalCalculator::timeToMinutes($time1)); // 8*60 + 30

        $time2 = new \DateTime('2024-12-09 00:00:00');
        $this->assertEquals(0, IntervalCalculator::timeToMinutes($time2));

        $time3 = new \DateTime('2024-12-09 23:59:00');
        $this->assertEquals(1439, IntervalCalculator::timeToMinutes($time3)); // 23*60 + 59

        $time4 = new \DateTime('2024-12-09 12:00:00');
        $this->assertEquals(720, IntervalCalculator::timeToMinutes($time4)); // noon
    }

    public function testMinutesSinceWakeUp(): void
    {
        $wakeTime = new \DateTime('2024-12-09 07:00:00');
        $cigTime = new \DateTime('2024-12-09 08:30:00');

        $minutes = IntervalCalculator::minutesSinceWakeUp($cigTime, $wakeTime);
        $this->assertEquals(90, $minutes); // 1h30 = 90 min
    }

    public function testMinutesSinceWakeUpBeforeWakeUp(): void
    {
        $wakeTime = new \DateTime('2024-12-09 09:00:00');
        $cigTime = new \DateTime('2024-12-09 08:30:00');

        $minutes = IntervalCalculator::minutesSinceWakeUp($cigTime, $wakeTime);
        $this->assertEquals(-30, $minutes); // 30 min before wake up
    }

    public function testMinutesSinceWakeUpExactWakeUp(): void
    {
        $wakeTime = new \DateTime('2024-12-09 07:00:00');
        $cigTime = new \DateTime('2024-12-09 07:00:00');

        $minutes = IntervalCalculator::minutesSinceWakeUp($cigTime, $wakeTime);
        $this->assertEquals(0, $minutes);
    }

    public function testMinutesSinceWakeUpLateEvening(): void
    {
        $wakeTime = new \DateTime('2024-12-09 07:00:00');
        $cigTime = new \DateTime('2024-12-09 22:00:00');

        $minutes = IntervalCalculator::minutesSinceWakeUp($cigTime, $wakeTime);
        $this->assertEquals(900, $minutes); // 15 hours = 900 min
    }
}
