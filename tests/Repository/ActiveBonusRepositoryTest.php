<?php

namespace App\Tests\Repository;

use App\Entity\ActiveBonus;
use App\Entity\User;
use App\Repository\ActiveBonusRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class ActiveBonusRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private ActiveBonusRepository $repository;
    private User $testUser;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->repository = $container->get(ActiveBonusRepository::class);

        // Create test user
        $this->testUser = new User();
        $this->testUser->setEmail('activebonus-test-' . uniqid() . '@example.com');
        $this->testUser->setPassword('password');
        $this->entityManager->persist($this->testUser);
        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        $conn = $this->entityManager->getConnection();
        $conn->executeStatement('DELETE FROM active_bonuses WHERE user_id = ?', [$this->testUser->getId()]);
        $conn->executeStatement('DELETE FROM user WHERE id = ?', [$this->testUser->getId()]);

        parent::tearDown();
    }

    private function createBonus(string $type, float $value, string $source, \DateTimeInterface $expiresAt): ActiveBonus
    {
        $bonus = new ActiveBonus();
        $bonus->setUser($this->testUser);
        $bonus->setBonusType($type);
        $bonus->setBonusValue($value);
        $bonus->setSourceBadge($source);
        $bonus->setExpiresAt($expiresAt);
        $this->entityManager->persist($bonus);
        return $bonus;
    }

    public function testActiveBonusEntityGettersAndSetters(): void
    {
        $bonus = new ActiveBonus();

        $bonus->setUser($this->testUser);
        $this->assertEquals($this->testUser, $bonus->getUser());

        $bonus->setBonusType(ActiveBonus::TYPE_SCORE_PERCENT);
        $this->assertEquals(ActiveBonus::TYPE_SCORE_PERCENT, $bonus->getBonusType());

        $bonus->setBonusValue(1.5);
        $this->assertEquals(1.5, $bonus->getBonusValue());

        $bonus->setSourceBadge('week_streak');
        $this->assertEquals('week_streak', $bonus->getSourceBadge());

        $expiresAt = new \DateTime('+7 days');
        $bonus->setExpiresAt($expiresAt);
        $this->assertEquals($expiresAt, $bonus->getExpiresAt());

        $createdAt = new \DateTime();
        $bonus->setCreatedAt($createdAt);
        $this->assertEquals($createdAt, $bonus->getCreatedAt());
    }

    public function testIsExpired(): void
    {
        $bonus = new ActiveBonus();

        // Future date - not expired
        $bonus->setExpiresAt(new \DateTime('+1 day'));
        $this->assertFalse($bonus->isExpired());

        // Past date - expired
        $bonus->setExpiresAt(new \DateTime('-1 day'));
        $this->assertTrue($bonus->isExpired());
    }

    public function testIsActive(): void
    {
        $bonus = new ActiveBonus();

        // Future date - active
        $bonus->setExpiresAt(new \DateTime('+1 day'));
        $this->assertTrue($bonus->isActive());

        // Past date - not active
        $bonus->setExpiresAt(new \DateTime('-1 day'));
        $this->assertFalse($bonus->isActive());
    }

    public function testBonusTypeConstants(): void
    {
        $this->assertEquals('score_percent', ActiveBonus::TYPE_SCORE_PERCENT);
        $this->assertEquals('multiplier', ActiveBonus::TYPE_MULTIPLIER);
        $this->assertEquals('shield', ActiveBonus::TYPE_SHIELD);
        $this->assertEquals('maintenance_day', ActiveBonus::TYPE_MAINTENANCE_DAY);
    }

    public function testFindActiveByUserReturnsEmpty(): void
    {
        $result = $this->repository->findActiveByUser($this->testUser);
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testFindActiveByUserFiltersExpired(): void
    {
        // Create active bonus
        $activeBonus = $this->createBonus(
            ActiveBonus::TYPE_SCORE_PERCENT,
            1.2,
            'badge_active',
            new \DateTime('+7 days')
        );

        // Create expired bonus
        $expiredBonus = $this->createBonus(
            ActiveBonus::TYPE_MULTIPLIER,
            1.5,
            'badge_expired',
            new \DateTime('-1 day')
        );

        $this->entityManager->flush();

        $result = $this->repository->findActiveByUser($this->testUser);

        $this->assertCount(1, $result);
        $this->assertEquals('badge_active', $result[0]->getSourceBadge());
    }

    public function testFindActiveByUserAndType(): void
    {
        // Create different bonus types
        $this->createBonus(ActiveBonus::TYPE_SCORE_PERCENT, 1.2, 'badge1', new \DateTime('+7 days'));
        $this->createBonus(ActiveBonus::TYPE_MULTIPLIER, 1.5, 'badge2', new \DateTime('+7 days'));
        $this->createBonus(ActiveBonus::TYPE_SCORE_PERCENT, 1.3, 'badge3', new \DateTime('+14 days'));

        $this->entityManager->flush();

        $scorePercent = $this->repository->findActiveByUserAndType($this->testUser, ActiveBonus::TYPE_SCORE_PERCENT);
        $this->assertCount(2, $scorePercent);

        $multiplier = $this->repository->findActiveByUserAndType($this->testUser, ActiveBonus::TYPE_MULTIPLIER);
        $this->assertCount(1, $multiplier);

        $shield = $this->repository->findActiveByUserAndType($this->testUser, ActiveBonus::TYPE_SHIELD);
        $this->assertCount(0, $shield);
    }

    public function testDeleteExpired(): void
    {
        // Create active and expired bonuses
        $this->createBonus(ActiveBonus::TYPE_SCORE_PERCENT, 1.2, 'active1', new \DateTime('+7 days'));
        $this->createBonus(ActiveBonus::TYPE_MULTIPLIER, 1.5, 'expired1', new \DateTime('-1 day'));
        $this->createBonus(ActiveBonus::TYPE_SHIELD, 1.0, 'expired2', new \DateTime('-2 days'));

        $this->entityManager->flush();

        // Should have 3 total
        $all = $this->repository->findBy(['user' => $this->testUser]);
        $this->assertCount(3, $all);

        // Delete expired
        $deleted = $this->repository->deleteExpired();
        $this->assertEquals(2, $deleted);

        // Should have 1 left
        $this->entityManager->clear();
        $remaining = $this->repository->findActiveByUser($this->testUser);
        $this->assertCount(1, $remaining);
        $this->assertEquals('active1', $remaining[0]->getSourceBadge());
    }

    public function testCreatedAtDefaultValue(): void
    {
        $bonus = new ActiveBonus();

        $createdAt = $bonus->getCreatedAt();
        $this->assertInstanceOf(\DateTimeInterface::class, $createdAt);

        // Should be approximately now
        $diff = abs(time() - $createdAt->getTimestamp());
        $this->assertLessThan(5, $diff);
    }

    public function testFindActiveByUserOrdersByExpiresAt(): void
    {
        // Create bonuses in non-chronological order
        $this->createBonus(ActiveBonus::TYPE_SCORE_PERCENT, 1.2, 'later', new \DateTime('+14 days'));
        $this->createBonus(ActiveBonus::TYPE_MULTIPLIER, 1.5, 'sooner', new \DateTime('+3 days'));
        $this->createBonus(ActiveBonus::TYPE_SHIELD, 1.0, 'middle', new \DateTime('+7 days'));

        $this->entityManager->flush();

        $result = $this->repository->findActiveByUser($this->testUser);

        $this->assertCount(3, $result);
        $this->assertEquals('sooner', $result[0]->getSourceBadge());
        $this->assertEquals('middle', $result[1]->getSourceBadge());
        $this->assertEquals('later', $result[2]->getSourceBadge());
    }
}
