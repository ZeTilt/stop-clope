<?php

namespace App\Tests\Service;

use App\Entity\Cigarette;
use App\Repository\CigaretteRepository;
use App\Service\MessageService;
use PHPUnit\Framework\TestCase;

class MessageServiceTest extends TestCase
{
    private MessageService $messageService;
    private CigaretteRepository $cigaretteRepository;

    protected function setUp(): void
    {
        $this->cigaretteRepository = $this->createMock(CigaretteRepository::class);
        $this->messageService = new MessageService($this->cigaretteRepository);
    }

    public function testGetEncouragementMessageZeroCigs(): void
    {
        $this->cigaretteRepository->method('getMinDailyCount')->willReturn(5);

        $result = $this->messageService->getEncouragementMessage(
            [],
            [],
            ['total_score' => 0]
        );

        $this->assertNotNull($result);
        $this->assertEquals('success', $result['type']);
        $this->assertNotEmpty($result['message']);
        $this->assertNotEmpty($result['icon']);
    }

    public function testGetEncouragementMessageGoodScore(): void
    {
        $this->cigaretteRepository->method('getMinDailyCount')->willReturn(5);

        // Create mock cigarettes
        $todayCigs = $this->createCigarettes(8);
        $yesterdayCigs = $this->createCigarettes(8);

        $result = $this->messageService->getEncouragementMessage(
            $todayCigs,
            $yesterdayCigs,
            ['total_score' => 50]
        );

        $this->assertNotNull($result);
        $this->assertEquals('success', $result['type']);
    }

    public function testGetEncouragementMessageOkScore(): void
    {
        $this->cigaretteRepository->method('getMinDailyCount')->willReturn(5);

        $todayCigs = $this->createCigarettes(8);
        $yesterdayCigs = $this->createCigarettes(8);

        $result = $this->messageService->getEncouragementMessage(
            $todayCigs,
            $yesterdayCigs,
            ['total_score' => 15]
        );

        $this->assertNotNull($result);
        $this->assertEquals('success', $result['type']);
    }

    public function testGetEncouragementMessageNegativeScore(): void
    {
        $this->cigaretteRepository->method('getMinDailyCount')->willReturn(5);

        $todayCigs = $this->createCigarettes(8);
        $yesterdayCigs = $this->createCigarettes(8);

        $result = $this->messageService->getEncouragementMessage(
            $todayCigs,
            $yesterdayCigs,
            ['total_score' => -15]
        );

        $this->assertNotNull($result);
        $this->assertEquals('warning', $result['type']);
    }

    public function testGetEncouragementMessageVeryNegativeScore(): void
    {
        $this->cigaretteRepository->method('getMinDailyCount')->willReturn(5);

        $todayCigs = $this->createCigarettes(8);
        $yesterdayCigs = $this->createCigarettes(8);

        $result = $this->messageService->getEncouragementMessage(
            $todayCigs,
            $yesterdayCigs,
            ['total_score' => -50]
        );

        $this->assertNotNull($result);
        $this->assertEquals('warning', $result['type']);
        $this->assertStringContainsString('Demain', $result['message']);
    }

    public function testGetEncouragementMessageLessThanYesterday(): void
    {
        $this->cigaretteRepository->method('getMinDailyCount')->willReturn(10);

        // Create cigarettes: 3 today vs many yesterday
        // Use early morning times so they're always "before now"
        $todayCigs = $this->createCigarettes(3);
        $yesterdayCigs = $this->createCigarettesAtTimes([
            '-1 day 00:00',
            '-1 day 01:00',
            '-1 day 02:00',
            '-1 day 03:00',
            '-1 day 04:00',
            '-1 day 05:00',
            '-1 day 06:00',
            '-1 day 07:00',
        ]);

        $result = $this->messageService->getEncouragementMessage(
            $todayCigs,
            $yesterdayCigs,
            ['total_score' => 10]
        );

        $this->assertNotNull($result);
        // Should be success because we have less cigs than yesterday at this time
        $this->assertEquals('success', $result['type']);
    }

    public function testGetEncouragementMessageMoreThanYesterday(): void
    {
        $this->cigaretteRepository->method('getMinDailyCount')->willReturn(10);

        // Create cigarettes: 8 today vs 2 yesterday at same time
        $todayCigs = $this->createCigarettes(8);
        $yesterdayCigs = $this->createCigarettesAtTimes([
            '-1 day 06:00',
            '-1 day 08:00',
        ]);

        $result = $this->messageService->getEncouragementMessage(
            $todayCigs,
            $yesterdayCigs,
            ['total_score' => 10]
        );

        $this->assertNotNull($result);
        $this->assertEquals('warning', $result['type']);
    }

    public function testMessageContainsIcon(): void
    {
        $this->cigaretteRepository->method('getMinDailyCount')->willReturn(null);

        $result = $this->messageService->getEncouragementMessage(
            [],
            [],
            ['total_score' => 0]
        );

        $this->assertNotNull($result);
        $this->assertArrayHasKey('icon', $result);
        $this->assertNotEmpty($result['icon']);
    }

    public function testMessageTypeIsValid(): void
    {
        $this->cigaretteRepository->method('getMinDailyCount')->willReturn(null);

        $result = $this->messageService->getEncouragementMessage(
            [],
            [],
            ['total_score' => 0]
        );

        $this->assertNotNull($result);
        $this->assertContains($result['type'], ['success', 'warning', 'info']);
    }

    /**
     * Helper method to create mock cigarettes
     */
    private function createCigarettes(int $count): array
    {
        $cigs = [];
        for ($i = 0; $i < $count; $i++) {
            $cig = $this->createMock(Cigarette::class);
            $time = new \DateTime();
            $time->setTime(6 + $i, 0);
            $cig->method('getSmokedAt')->willReturn($time);
            $cigs[] = $cig;
        }
        return $cigs;
    }

    /**
     * Helper method to create mock cigarettes at specific times
     */
    private function createCigarettesAtTimes(array $times): array
    {
        $cigs = [];
        foreach ($times as $timeStr) {
            $cig = $this->createMock(Cigarette::class);
            $time = new \DateTime($timeStr);
            $cig->method('getSmokedAt')->willReturn($time);
            $cigs[] = $cig;
        }
        return $cigs;
    }
}
