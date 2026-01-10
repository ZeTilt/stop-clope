<?php

namespace App\Tests\Service;

use App\Entity\ActiveBonus;
use App\Entity\User;
use App\Entity\UserState;
use App\Repository\ActiveBonusRepository;
use App\Repository\CigaretteRepository;
use App\Repository\DailyScoreRepository;
use App\Repository\SettingsRepository;
use App\Repository\UserBadgeRepository;
use App\Repository\UserStateRepository;
use App\Service\BadgeService;
use App\Service\ScoringService;
use App\Service\StatsService;
use App\Service\StreakService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class BadgeServiceTest extends TestCase
{
    private BadgeService $badgeService;
    private CigaretteRepository $cigaretteRepository;
    private SettingsRepository $settingsRepository;
    private UserBadgeRepository $userBadgeRepository;
    private DailyScoreRepository $dailyScoreRepository;
    private ActiveBonusRepository $activeBonusRepository;
    private UserStateRepository $userStateRepository;
    private EntityManagerInterface $entityManager;
    private ScoringService $scoringService;
    private StatsService $statsService;
    private StreakService $streakService;
    private Security $security;
    private ParameterBagInterface $params;

    private array $testBadges = [
        'zen_beginner' => [
            'name' => 'Zen Débutant',
            'description' => '3 jours sans zone négative',
            'icon' => '🧘',
            'category' => 'discipline',
            'condition' => ['type' => 'days_no_negative', 'value' => 3],
            'bonus' => ['type' => 'score_percent', 'value' => 5, 'duration' => 1440],
        ],
        'streak_7' => [
            'name' => 'Une semaine',
            'description' => '7 jours consécutifs positifs',
            'icon' => '🔥',
            'category' => 'sequence',
            'condition' => ['type' => 'streak_days', 'value' => 7],
            'bonus' => ['type' => 'score_percent', 'value' => 10, 'duration' => 1440],
        ],
        'reducer_50' => [
            'name' => 'Réducteur',
            'description' => '-50% vs consommation initiale',
            'icon' => '🎯',
            'category' => 'reduction',
            'condition' => ['type' => 'reduction_percent', 'value' => 50],
            'bonus' => ['type' => 'multiplier', 'value' => 0.03],
        ],
        'saver_100' => [
            'name' => 'Riche',
            'description' => '100€ économisés',
            'icon' => '🏦',
            'category' => 'savings',
            'condition' => ['type' => 'savings', 'value' => 100],
            'bonus' => ['type' => 'shield', 'value' => 1],
        ],
        'zero_day' => [
            'name' => 'Jour Parfait',
            'description' => 'Une journée sans fumer',
            'icon' => '⭐',
            'category' => 'reduction',
            'condition' => ['type' => 'zero_day', 'value' => 1],
            'bonus' => ['type' => 'score_percent', 'value' => 10, 'duration' => 2880],
        ],
    ];

    protected function setUp(): void
    {
        $this->cigaretteRepository = $this->createMock(CigaretteRepository::class);
        $this->settingsRepository = $this->createMock(SettingsRepository::class);
        $this->userBadgeRepository = $this->createMock(UserBadgeRepository::class);
        $this->dailyScoreRepository = $this->createMock(DailyScoreRepository::class);
        $this->activeBonusRepository = $this->createMock(ActiveBonusRepository::class);
        $this->userStateRepository = $this->createMock(UserStateRepository::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->scoringService = $this->createMock(ScoringService::class);
        $this->statsService = $this->createMock(StatsService::class);
        $this->streakService = $this->createMock(StreakService::class);
        $this->security = $this->createMock(Security::class);
        $this->params = $this->createMock(ParameterBagInterface::class);

        // Configure params to return test badges
        $this->params->method('has')->with('badges')->willReturn(true);
        $this->params->method('get')->with('badges')->willReturn($this->testBadges);

        $this->badgeService = new BadgeService(
            $this->cigaretteRepository,
            $this->settingsRepository,
            $this->userBadgeRepository,
            $this->dailyScoreRepository,
            $this->activeBonusRepository,
            $this->userStateRepository,
            $this->entityManager,
            $this->scoringService,
            $this->statsService,
            $this->streakService,
            $this->security,
            $this->params
        );
    }

    public function testGetAllBadgeDefinitions(): void
    {
        $badges = $this->badgeService->getAllBadgeDefinitions();

        $this->assertIsArray($badges);
        $this->assertCount(5, $badges);
        $this->assertArrayHasKey('zen_beginner', $badges);
        $this->assertArrayHasKey('streak_7', $badges);
    }

    public function testGetBadgeInfo(): void
    {
        $badge = $this->badgeService->getBadgeInfo('zen_beginner');

        $this->assertNotNull($badge);
        $this->assertEquals('Zen Débutant', $badge['name']);
        $this->assertEquals('🧘', $badge['icon']);
        $this->assertEquals('discipline', $badge['category']);
    }

    public function testGetBadgeInfoUnknown(): void
    {
        $badge = $this->badgeService->getBadgeInfo('unknown_badge');

        $this->assertNull($badge);
    }

    public function testGetAllBadgesWithStatusNoUser(): void
    {
        $this->security->method('getUser')->willReturn(null);

        $badges = $this->badgeService->getAllBadgesWithStatus();

        $this->assertCount(5, $badges);
        foreach ($badges as $badge) {
            $this->assertFalse($badge['unlocked']);
            $this->assertNull($badge['unlocked_at']);
        }
    }

    public function testCountUnlockedBadgesNoUser(): void
    {
        $this->security->method('getUser')->willReturn(null);

        $count = $this->badgeService->countUnlockedBadges();

        $this->assertEquals(0, $count);
    }

    public function testCountUnlockedBadges(): void
    {
        $user = $this->createMock(User::class);
        $this->security->method('getUser')->willReturn($user);
        $this->userBadgeRepository->method('findUserBadgeCodes')
            ->with($user)
            ->willReturn(['zen_beginner', 'streak_7']);

        $count = $this->badgeService->countUnlockedBadges();

        $this->assertEquals(2, $count);
    }

    public function testGetActiveBonusesNoUser(): void
    {
        $this->security->method('getUser')->willReturn(null);

        $bonuses = $this->badgeService->getActiveBonuses();

        $this->assertEmpty($bonuses);
    }

    public function testGetTotalTemporaryBonus(): void
    {
        $user = $this->createMock(User::class);
        $this->security->method('getUser')->willReturn($user);

        $bonus1 = $this->createMock(ActiveBonus::class);
        $bonus1->method('getBonusType')->willReturn(ActiveBonus::TYPE_SCORE_PERCENT);
        $bonus1->method('getBonusValue')->willReturn(5.0);

        $bonus2 = $this->createMock(ActiveBonus::class);
        $bonus2->method('getBonusType')->willReturn(ActiveBonus::TYPE_SCORE_PERCENT);
        $bonus2->method('getBonusValue')->willReturn(10.0);

        $this->activeBonusRepository->method('findActiveByUser')
            ->with($user)
            ->willReturn([$bonus1, $bonus2]);

        $total = $this->badgeService->getTotalTemporaryBonus();

        // 5 + 10 = 15% -> 0.15
        $this->assertEquals(0.15, $total);
    }

    public function testGetAvailableShieldsNoUser(): void
    {
        $this->security->method('getUser')->willReturn(null);

        $shields = $this->badgeService->getAvailableShields();

        $this->assertEquals(0, $shields);
    }

    public function testGetAvailableShields(): void
    {
        $user = $this->createMock(User::class);
        $this->security->method('getUser')->willReturn($user);

        $userState = $this->createMock(UserState::class);
        $userState->method('getShieldsCount')->willReturn(3);

        $this->userStateRepository->method('findByUser')
            ->with($user)
            ->willReturn($userState);

        $shields = $this->badgeService->getAvailableShields();

        $this->assertEquals(3, $shields);
    }

    public function testGetPermanentMultiplierNoUser(): void
    {
        $this->security->method('getUser')->willReturn(null);

        $multiplier = $this->badgeService->getPermanentMultiplier();

        $this->assertEquals(0.0, $multiplier);
    }

    public function testGetPermanentMultiplier(): void
    {
        $user = $this->createMock(User::class);
        $this->security->method('getUser')->willReturn($user);

        $userState = $this->createMock(UserState::class);
        $userState->method('getPermanentMultiplier')->willReturn(0.15);

        $this->userStateRepository->method('findByUser')
            ->with($user)
            ->willReturn($userState);

        $multiplier = $this->badgeService->getPermanentMultiplier();

        $this->assertEquals(0.15, $multiplier);
    }

    public function testGetBadgesByCategory(): void
    {
        $user = $this->createMock(User::class);
        $this->security->method('getUser')->willReturn($user);
        $this->userBadgeRepository->method('findUserBadges')->willReturn([]);

        $byCategory = $this->badgeService->getBadgesByCategory();

        $this->assertArrayHasKey('discipline', $byCategory);
        $this->assertArrayHasKey('sequence', $byCategory);
        $this->assertArrayHasKey('reduction', $byCategory);
        $this->assertArrayHasKey('savings', $byCategory);

        $this->assertCount(1, $byCategory['discipline']);
        $this->assertCount(1, $byCategory['sequence']);
        $this->assertCount(2, $byCategory['reduction']);
        $this->assertCount(1, $byCategory['savings']);
    }

    public function testCheckAndAwardBadgesNoUser(): void
    {
        $this->security->method('getUser')->willReturn(null);

        $newBadges = $this->badgeService->checkAndAwardBadges();

        $this->assertEmpty($newBadges);
    }

    public function testBadgeConditionTypes(): void
    {
        $badges = $this->badgeService->getAllBadgeDefinitions();

        $expectedConditionTypes = [
            'zen_beginner' => 'days_no_negative',
            'streak_7' => 'streak_days',
            'reducer_50' => 'reduction_percent',
            'saver_100' => 'savings',
            'zero_day' => 'zero_day',
        ];

        foreach ($expectedConditionTypes as $code => $expectedType) {
            $this->assertEquals(
                $expectedType,
                $badges[$code]['condition']['type'],
                "Badge {$code} should have condition type {$expectedType}"
            );
        }
    }

    public function testBadgeBonusTypes(): void
    {
        $badges = $this->badgeService->getAllBadgeDefinitions();

        $expectedBonusTypes = [
            'zen_beginner' => 'score_percent',
            'streak_7' => 'score_percent',
            'reducer_50' => 'multiplier',
            'saver_100' => 'shield',
            'zero_day' => 'score_percent',
        ];

        foreach ($expectedBonusTypes as $code => $expectedType) {
            $this->assertEquals(
                $expectedType,
                $badges[$code]['bonus']['type'],
                "Badge {$code} should have bonus type {$expectedType}"
            );
        }
    }

    public function testGetUnlockedBadges(): void
    {
        $user = $this->createMock(User::class);
        $this->security->method('getUser')->willReturn($user);

        $userBadge = $this->createMock(\App\Entity\UserBadge::class);
        $userBadge->method('getBadgeCode')->willReturn('zen_beginner');
        $userBadge->method('getUnlockedAt')->willReturn(new \DateTime());

        $this->userBadgeRepository->method('findUserBadges')
            ->with($user)
            ->willReturn([$userBadge]);

        $unlocked = $this->badgeService->getUnlockedBadges();

        $this->assertCount(1, $unlocked);
        $this->assertArrayHasKey('zen_beginner', $unlocked);
        $this->assertTrue($unlocked['zen_beginner']['unlocked']);
    }
}
