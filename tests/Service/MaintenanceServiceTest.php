<?php

namespace App\Tests\Service;

use App\Entity\DailyScore;
use App\Entity\User;
use App\Repository\DailyScoreRepository;
use App\Service\MaintenanceService;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

class MaintenanceServiceTest extends TestCase
{
    private MaintenanceService $service;
    private DailyScoreRepository $dailyScoreRepository;
    private Security $security;
    private User $user;

    protected function setUp(): void
    {
        $this->dailyScoreRepository = $this->createMock(DailyScoreRepository::class);
        $this->security = $this->createMock(Security::class);

        $this->service = new MaintenanceService(
            $this->dailyScoreRepository,
            $this->security
        );

        $this->user = new User();
        $this->security->method('getUser')->willReturn($this->user);
    }

    // ========================================
    // Tests canUseMaintenanceDay
    // ========================================

    public function testCanUseMaintenanceDayTrue(): void
    {
        $this->dailyScoreRepository
            ->method('hasMaintenanceDayInRange')
            ->willReturn(false);

        $result = $this->service->canUseMaintenanceDay();

        $this->assertTrue($result);
    }

    public function testCanUseMaintenanceDayFalseAlreadyUsed(): void
    {
        $this->dailyScoreRepository
            ->method('hasMaintenanceDayInRange')
            ->willReturn(true);

        $result = $this->service->canUseMaintenanceDay();

        $this->assertFalse($result);
    }

    public function testCanUseMaintenanceDayFalseNoUser(): void
    {
        $this->security = $this->createMock(Security::class);
        $this->security->method('getUser')->willReturn(null);

        $service = new MaintenanceService(
            $this->dailyScoreRepository,
            $this->security
        );

        $result = $service->canUseMaintenanceDay();

        $this->assertFalse($result);
    }

    // ========================================
    // Tests activateMaintenanceDay
    // ========================================

    public function testActivateMaintenanceDaySuccess(): void
    {
        $this->dailyScoreRepository
            ->method('hasMaintenanceDayInRange')
            ->willReturn(false);

        $this->dailyScoreRepository
            ->method('findByUserAndDate')
            ->willReturn(null);

        $this->dailyScoreRepository
            ->expects($this->once())
            ->method('upsert');

        $result = $this->service->activateMaintenanceDay();

        $this->assertTrue($result['success']);
        $this->assertEquals('Jour de maintenance activé', $result['message']);
    }

    public function testActivateMaintenanceDayFailAlreadyUsed(): void
    {
        $this->dailyScoreRepository
            ->method('hasMaintenanceDayInRange')
            ->willReturn(true);

        $result = $this->service->activateMaintenanceDay();

        $this->assertFalse($result['success']);
        $this->assertEquals('Jour de maintenance déjà utilisé cette semaine', $result['error']);
    }

    public function testActivateMaintenanceDayUpdatesExisting(): void
    {
        $this->dailyScoreRepository
            ->method('hasMaintenanceDayInRange')
            ->willReturn(false);

        $existingScore = $this->createMock(DailyScore::class);
        $existingScore->expects($this->once())->method('setIsMaintenanceDay')->with(true);

        $this->dailyScoreRepository
            ->method('findByUserAndDate')
            ->willReturn($existingScore);

        $result = $this->service->activateMaintenanceDay();

        $this->assertTrue($result['success']);
    }

    // ========================================
    // Tests deactivateMaintenanceDay
    // ========================================

    public function testDeactivateMaintenanceDaySuccess(): void
    {
        $existingScore = $this->createMock(DailyScore::class);
        $existingScore->method('isMaintenanceDay')->willReturn(true);
        $existingScore->expects($this->once())->method('setIsMaintenanceDay')->with(false);

        $this->dailyScoreRepository
            ->method('findByUserAndDate')
            ->willReturn($existingScore);

        $result = $this->service->deactivateMaintenanceDay();

        $this->assertTrue($result['success']);
        $this->assertEquals('Jour de maintenance désactivé', $result['message']);
    }

    public function testDeactivateMaintenanceDayFailNotMaintenance(): void
    {
        $existingScore = $this->createMock(DailyScore::class);
        $existingScore->method('isMaintenanceDay')->willReturn(false);

        $this->dailyScoreRepository
            ->method('findByUserAndDate')
            ->willReturn($existingScore);

        $result = $this->service->deactivateMaintenanceDay();

        $this->assertFalse($result['success']);
        $this->assertEquals("Ce jour n'est pas un jour de maintenance", $result['error']);
    }

    // ========================================
    // Tests getWeeklyMaintenanceInfo
    // ========================================

    public function testGetWeeklyMaintenanceInfoAvailable(): void
    {
        $this->dailyScoreRepository
            ->method('hasMaintenanceDayInRange')
            ->willReturn(false);

        $result = $this->service->getWeeklyMaintenanceInfo();

        $this->assertTrue($result['available']);
        $this->assertFalse($result['used']);
        $this->assertNull($result['used_date']);
    }

    public function testGetWeeklyMaintenanceInfoUsed(): void
    {
        $usedDate = new \DateTime('2024-01-15');

        $this->dailyScoreRepository
            ->method('hasMaintenanceDayInRange')
            ->willReturn(true);

        $this->dailyScoreRepository
            ->method('getMaintenanceDayInRange')
            ->willReturn($usedDate);

        $result = $this->service->getWeeklyMaintenanceInfo();

        $this->assertFalse($result['available']);
        $this->assertTrue($result['used']);
        $this->assertEquals('2024-01-15', $result['used_date']);
    }

    // ========================================
    // Tests isTodayMaintenanceDay
    // ========================================

    public function testIsTodayMaintenanceDayTrue(): void
    {
        $dailyScore = $this->createMock(DailyScore::class);
        $dailyScore->method('isMaintenanceDay')->willReturn(true);

        $this->dailyScoreRepository
            ->method('findByUserAndDate')
            ->willReturn($dailyScore);

        $result = $this->service->isTodayMaintenanceDay();

        $this->assertTrue($result);
    }

    public function testIsTodayMaintenanceDayFalse(): void
    {
        $this->dailyScoreRepository
            ->method('findByUserAndDate')
            ->willReturn(null);

        $result = $this->service->isTodayMaintenanceDay();

        $this->assertFalse($result);
    }
}
