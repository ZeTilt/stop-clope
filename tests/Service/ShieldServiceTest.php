<?php

namespace App\Tests\Service;

use App\Entity\User;
use App\Entity\UserState;
use App\Repository\SettingsRepository;
use App\Repository\UserStateRepository;
use App\Service\ShieldService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class ShieldServiceTest extends TestCase
{
    private ShieldService $shieldService;
    private UserStateRepository $userStateRepository;
    private SettingsRepository $settingsRepository;
    private EntityManagerInterface $entityManager;
    private User $user;

    protected function setUp(): void
    {
        $this->userStateRepository = $this->createMock(UserStateRepository::class);
        $this->settingsRepository = $this->createMock(SettingsRepository::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);

        $this->shieldService = new ShieldService(
            $this->userStateRepository,
            $this->settingsRepository,
            $this->entityManager
        );

        $this->user = new User();
    }

    public function testCanUseShieldNoUserState(): void
    {
        $this->userStateRepository->method('findByUser')->willReturn(null);

        $result = $this->shieldService->canUseShield($this->user);

        $this->assertFalse($result);
    }

    public function testCanUseShieldNoShields(): void
    {
        $userState = $this->createMock(UserState::class);
        $userState->method('getShieldsCount')->willReturn(0);

        $this->userStateRepository->method('findByUser')->willReturn($userState);

        $result = $this->shieldService->canUseShield($this->user);

        $this->assertFalse($result);
    }

    public function testCanUseShieldHasShields(): void
    {
        $userState = $this->createMock(UserState::class);
        $userState->method('getShieldsCount')->willReturn(2);

        $this->userStateRepository->method('findByUser')->willReturn($userState);

        $result = $this->shieldService->canUseShield($this->user);

        $this->assertTrue($result);
    }

    public function testGetAvailableShieldsNoState(): void
    {
        $this->userStateRepository->method('findByUser')->willReturn(null);

        $result = $this->shieldService->getAvailableShields($this->user);

        $this->assertEquals(0, $result);
    }

    public function testGetAvailableShields(): void
    {
        $userState = $this->createMock(UserState::class);
        $userState->method('getShieldsCount')->willReturn(3);

        $this->userStateRepository->method('findByUser')->willReturn($userState);

        $result = $this->shieldService->getAvailableShields($this->user);

        $this->assertEquals(3, $result);
    }

    public function testUseShieldNoShields(): void
    {
        $userState = new UserState();
        $userState->setUser($this->user);
        $userState->setShieldsCount(0);

        $this->userStateRepository->method('findOrCreateByUser')->willReturn($userState);

        $result = $this->shieldService->useShield($this->user);

        $this->assertFalse($result['success']);
        $this->assertEquals('Aucun bouclier disponible', $result['error']);
    }

    public function testUseShieldSuccess(): void
    {
        $userState = new UserState();
        $userState->setUser($this->user);
        $userState->setShieldsCount(2);

        $this->userStateRepository->method('findOrCreateByUser')->willReturn($userState);
        $this->settingsRepository->method('get')->willReturn('0');

        $result = $this->shieldService->useShield($this->user);

        $this->assertTrue($result['success']);
        $this->assertEquals(1, $result['shields_remaining']);
        $this->assertEquals(1, $userState->getShieldsCount());
    }

    public function testGetTotalShieldsUsed(): void
    {
        $this->settingsRepository->method('get')
            ->with('shields_used', '0')
            ->willReturn('5');

        $result = $this->shieldService->getTotalShieldsUsed();

        $this->assertEquals(5, $result);
    }

    public function testHasMonthlyShieldNoState(): void
    {
        $this->userStateRepository->method('findByUser')->willReturn(null);

        $result = $this->shieldService->hasMonthlyShieldAvailable($this->user);

        $this->assertFalse($result);
    }

    public function testHasMonthlyShieldLowScore(): void
    {
        $userState = $this->createMock(UserState::class);
        $userState->method('getTotalScore')->willReturn(100000);

        $this->userStateRepository->method('findByUser')->willReturn($userState);

        $result = $this->shieldService->hasMonthlyShieldAvailable($this->user);

        $this->assertFalse($result);
    }

    public function testHasMonthlyShieldAvailable(): void
    {
        $userState = $this->createMock(UserState::class);
        $userState->method('getTotalScore')->willReturn(200000);

        $this->userStateRepository->method('findByUser')->willReturn($userState);
        $this->settingsRepository->method('get')
            ->with('monthly_shield_claimed')
            ->willReturn(null);

        $result = $this->shieldService->hasMonthlyShieldAvailable($this->user);

        $this->assertTrue($result);
    }

    public function testHasMonthlyShieldAlreadyClaimed(): void
    {
        $userState = $this->createMock(UserState::class);
        $userState->method('getTotalScore')->willReturn(200000);

        $this->userStateRepository->method('findByUser')->willReturn($userState);
        $this->settingsRepository->method('get')
            ->with('monthly_shield_claimed')
            ->willReturn((new \DateTime())->format('Y-m-d'));

        $result = $this->shieldService->hasMonthlyShieldAvailable($this->user);

        $this->assertFalse($result);
    }

    public function testGetShieldInfo(): void
    {
        $userState = $this->createMock(UserState::class);
        $userState->method('getShieldsCount')->willReturn(2);
        $userState->method('getTotalScore')->willReturn(100000);

        $this->userStateRepository->method('findByUser')->willReturn($userState);
        $this->settingsRepository->method('get')
            ->willReturnMap([
                ['shields_used', '0', '3'],
                ['monthly_shield_claimed', null, null],
            ]);

        $result = $this->shieldService->getShieldInfo($this->user);

        $this->assertEquals(2, $result['available']);
        $this->assertTrue($result['can_use']);
        $this->assertFalse($result['monthly_available']); // Score too low
        $this->assertEquals(3, $result['total_used']);
    }
}
