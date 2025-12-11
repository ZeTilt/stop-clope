<?php

namespace App\Tests\Service;

use App\Service\DateTimeHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DateTimeHelperTest extends TestCase
{
    public function testStartOfDay(): void
    {
        $date = new \DateTime('2024-12-09 15:30:45');
        $result = DateTimeHelper::startOfDay($date);

        $this->assertEquals('2024-12-09', $result->format('Y-m-d'));
        $this->assertEquals('00:00:00', $result->format('H:i:s'));
    }

    public function testEndOfDay(): void
    {
        $date = new \DateTime('2024-12-09 08:15:00');
        $result = DateTimeHelper::endOfDay($date);

        $this->assertEquals('2024-12-09', $result->format('Y-m-d'));
        $this->assertEquals('23:59:59', $result->format('H:i:s'));
    }

    public function testTimeToMinutes(): void
    {
        $time1 = new \DateTime('2024-12-09 08:30:00');
        $this->assertEquals(510, DateTimeHelper::timeToMinutes($time1));

        $time2 = new \DateTime('2024-12-09 00:00:00');
        $this->assertEquals(0, DateTimeHelper::timeToMinutes($time2));

        $time3 = new \DateTime('2024-12-09 12:00:00');
        $this->assertEquals(720, DateTimeHelper::timeToMinutes($time3));
    }

    #[DataProvider('formatDurationProvider')]
    public function testFormatDuration(int $minutes, string $expected): void
    {
        $result = DateTimeHelper::formatDuration($minutes);
        $this->assertEquals($expected, $result);
    }

    public static function formatDurationProvider(): array
    {
        return [
            'zero minutes' => [0, '0min'],
            '30 minutes' => [30, '30min'],
            '59 minutes' => [59, '59min'],
            '1 hour exact' => [60, '1h'],
            '1h30' => [90, '1h 30min'],
            '2 hours exact' => [120, '2h'],
            '2h45' => [165, '2h 45min'],
            '10 hours' => [600, '10h'],
        ];
    }

    public function testFromLocalTime(): void
    {
        // Browser in UTC+1 (Paris winter time) sends -60 offset
        $result = DateTimeHelper::fromLocalTime('2024-12-09 14:30', -60);

        // 14:30 local time with -60 offset means 15:30 UTC
        $this->assertEquals('15:30', $result->format('H:i'));
    }

    public function testFromLocalTimePositiveOffset(): void
    {
        // Browser in UTC-5 (New York) sends +300 offset
        $result = DateTimeHelper::fromLocalTime('2024-12-09 10:00', 300);

        // 10:00 local time with +300 offset means 05:00 UTC
        $this->assertEquals('05:00', $result->format('H:i'));
    }

    public function testTodayAndYesterday(): void
    {
        $today = DateTimeHelper::today();
        $yesterday = DateTimeHelper::yesterday();

        $expectedYesterday = (new \DateTime())->modify('-1 day');

        $this->assertEquals(date('Y-m-d'), $today->format('Y-m-d'));
        $this->assertEquals($expectedYesterday->format('Y-m-d'), $yesterday->format('Y-m-d'));
    }

    public function testStartOfDayDoesNotMutateOriginal(): void
    {
        $original = new \DateTime('2024-12-09 15:30:45');
        $originalFormat = $original->format('Y-m-d H:i:s');

        DateTimeHelper::startOfDay($original);

        $this->assertEquals($originalFormat, $original->format('Y-m-d H:i:s'));
    }

    public function testEndOfDayDoesNotMutateOriginal(): void
    {
        $original = new \DateTime('2024-12-09 08:15:00');
        $originalFormat = $original->format('Y-m-d H:i:s');

        DateTimeHelper::endOfDay($original);

        $this->assertEquals($originalFormat, $original->format('Y-m-d H:i:s'));
    }
}
