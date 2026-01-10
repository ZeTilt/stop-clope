<?php

namespace App\Tests\Service;

use App\Entity\User;
use App\Entity\UserState;
use App\Repository\CigaretteRepository;
use App\Repository\DailyScoreRepository;
use App\Repository\SettingsRepository;
use App\Repository\UserBadgeRepository;
use App\Repository\UserStateRepository;
use App\Repository\WakeUpRepository;
use App\Service\ResetService;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

class ResetServiceTest extends TestCase
{
    private ResetService $resetService;
    private EntityManagerInterface $entityManager;
    private CigaretteRepository $cigaretteRepository;
    private WakeUpRepository $wakeUpRepository;
    private DailyScoreRepository $dailyScoreRepository;
    private UserBadgeRepository $userBadgeRepository;
    private UserStateRepository $userStateRepository;
    private SettingsRepository $settingsRepository;
    private Security $security;
    private User $user;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->cigaretteRepository = $this->createMock(CigaretteRepository::class);
        $this->wakeUpRepository = $this->createMock(WakeUpRepository::class);
        $this->dailyScoreRepository = $this->createMock(DailyScoreRepository::class);
        $this->userBadgeRepository = $this->createMock(UserBadgeRepository::class);
        $this->userStateRepository = $this->createMock(UserStateRepository::class);
        $this->settingsRepository = $this->createMock(SettingsRepository::class);
        $this->security = $this->createMock(Security::class);

        $this->resetService = new ResetService(
            $this->entityManager,
            $this->cigaretteRepository,
            $this->wakeUpRepository,
            $this->dailyScoreRepository,
            $this->userBadgeRepository,
            $this->userStateRepository,
            $this->settingsRepository,
            $this->security
        );

        $this->user = new User();
    }

    public function testGetPreResetStatsNoState(): void
    {
        $this->userStateRepository->method('findByUser')->willReturn(null);
        $this->cigaretteRepository->method('getFirstCigaretteDate')->willReturn(null);
        $this->userBadgeRepository->method('findUserBadgeCodes')->willReturn([]);

        $stats = $this->resetService->getPreResetStats($this->user);

        $this->assertEquals(0, $stats['total_score']);
        $this->assertEquals(0, $stats['days_count']);
        $this->assertEquals('fumeur', $stats['rank']);
        $this->assertEquals(0, $stats['badges_count']);
    }

    public function testGetPreResetStatsWithData(): void
    {
        $userState = $this->createMock(UserState::class);
        $userState->method('getTotalScore')->willReturn(5000);
        $userState->method('getCurrentRank')->willReturn('confirmé');
        $userState->method('getShieldsCount')->willReturn(2);
        $userState->method('getPermanentMultiplier')->willReturn(0.05);

        $this->userStateRepository->method('findByUser')->willReturn($userState);
        $this->cigaretteRepository->method('getFirstCigaretteDate')
            ->willReturn((new \DateTime())->modify('-10 days'));
        $this->userBadgeRepository->method('findUserBadgeCodes')
            ->willReturn(['zen_beginner', 'streak_3']);

        $stats = $this->resetService->getPreResetStats($this->user);

        $this->assertEquals(5000, $stats['total_score']);
        $this->assertEquals(10, $stats['days_count']);
        $this->assertEquals('confirmé', $stats['rank']);
        $this->assertEquals(2, $stats['badges_count']);
        $this->assertEquals(2, $stats['shields_count']);
        $this->assertEquals(0.05, $stats['permanent_multiplier']);
    }

    public function testGetResetHistoryEmpty(): void
    {
        $this->settingsRepository->method('get')
            ->with('reset_history', '[]')
            ->willReturn('[]');

        $history = $this->resetService->getResetHistory();

        $this->assertEmpty($history);
    }

    public function testGetResetHistoryWithData(): void
    {
        $data = [
            ['date' => '2026-01-01', 'total_score' => 1000],
            ['date' => '2026-01-05', 'total_score' => 2000],
        ];

        $this->settingsRepository->method('get')
            ->with('reset_history', '[]')
            ->willReturn(json_encode($data));

        $history = $this->resetService->getResetHistory();

        $this->assertCount(2, $history);
        $this->assertEquals(1000, $history[0]['total_score']);
        $this->assertEquals(2000, $history[1]['total_score']);
    }

    public function testHasResetHistoryFalse(): void
    {
        $this->settingsRepository->method('get')
            ->with('reset_history', '[]')
            ->willReturn('[]');

        $this->assertFalse($this->resetService->hasResetHistory());
    }

    public function testHasResetHistoryTrue(): void
    {
        $this->settingsRepository->method('get')
            ->with('reset_history', '[]')
            ->willReturn('[{"date":"2026-01-01"}]');

        $this->assertTrue($this->resetService->hasResetHistory());
    }

    public function testGetResetCount(): void
    {
        $data = [
            ['date' => '2026-01-01'],
            ['date' => '2026-01-05'],
            ['date' => '2026-01-10'],
        ];

        $this->settingsRepository->method('get')
            ->with('reset_history', '[]')
            ->willReturn(json_encode($data));

        $this->assertEquals(3, $this->resetService->getResetCount());
    }

    public function testGetLastResetEmpty(): void
    {
        $this->settingsRepository->method('get')
            ->with('reset_history', '[]')
            ->willReturn('[]');

        $this->assertNull($this->resetService->getLastReset());
    }

    public function testGetLastReset(): void
    {
        $data = [
            ['date' => '2026-01-01', 'total_score' => 1000],
            ['date' => '2026-01-05', 'total_score' => 2000],
        ];

        $this->settingsRepository->method('get')
            ->with('reset_history', '[]')
            ->willReturn(json_encode($data));

        $lastReset = $this->resetService->getLastReset();

        $this->assertNotNull($lastReset);
        $this->assertEquals('2026-01-05', $lastReset['date']);
        $this->assertEquals(2000, $lastReset['total_score']);
    }
}
