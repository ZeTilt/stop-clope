<?php

namespace App\Tests\Repository;

use App\Entity\User;
use App\Entity\UserState;
use App\Repository\UserStateRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class UserStateRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private UserStateRepository $repository;
    private User $testUser;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->repository = $container->get(UserStateRepository::class);

        // Create test user
        $this->testUser = new User();
        $this->testUser->setEmail('userstate-test-' . uniqid() . '@example.com');
        $this->testUser->setPassword('password');
        $this->entityManager->persist($this->testUser);
        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        $conn = $this->entityManager->getConnection();
        $conn->executeStatement('DELETE FROM user_states WHERE user_id = ?', [$this->testUser->getId()]);
        $conn->executeStatement('DELETE FROM user WHERE id = ?', [$this->testUser->getId()]);

        parent::tearDown();
    }

    public function testUserStateEntityGettersAndSetters(): void
    {
        $userState = new UserState();

        $userState->setUser($this->testUser);
        $this->assertEquals($this->testUser, $userState->getUser());

        $userState->setShieldsCount(3);
        $this->assertEquals(3, $userState->getShieldsCount());

        $userState->setPermanentMultiplier(1.5);
        $this->assertEquals(1.5, $userState->getPermanentMultiplier());

        $userState->setCurrentRank('ex-fumeur');
        $this->assertEquals('ex-fumeur', $userState->getCurrentRank());

        $userState->setTotalScore(500);
        $this->assertEquals(500, $userState->getTotalScore());
    }

    public function testAddShield(): void
    {
        $userState = new UserState();
        $userState->setShieldsCount(2);

        $userState->addShield();
        $this->assertEquals(3, $userState->getShieldsCount());

        $userState->addShield();
        $this->assertEquals(4, $userState->getShieldsCount());
    }

    public function testUseShieldSuccess(): void
    {
        $userState = new UserState();
        $userState->setShieldsCount(2);

        $result = $userState->useShield();
        $this->assertTrue($result);
        $this->assertEquals(1, $userState->getShieldsCount());
    }

    public function testUseShieldFailsWhenEmpty(): void
    {
        $userState = new UserState();
        $userState->setShieldsCount(0);

        $result = $userState->useShield();
        $this->assertFalse($result);
        $this->assertEquals(0, $userState->getShieldsCount());
    }

    public function testAddPermanentMultiplier(): void
    {
        $userState = new UserState();
        $userState->setPermanentMultiplier(1.0);

        $userState->addPermanentMultiplier(0.2);
        $this->assertEquals(1.2, $userState->getPermanentMultiplier());

        $userState->addPermanentMultiplier(0.5);
        $this->assertEquals(1.7, $userState->getPermanentMultiplier());
    }

    public function testAddScore(): void
    {
        $userState = new UserState();
        $userState->setTotalScore(100);

        $userState->addScore(50);
        $this->assertEquals(150, $userState->getTotalScore());

        $userState->addScore(200);
        $this->assertEquals(350, $userState->getTotalScore());
    }

    public function testFindByUserReturnsNull(): void
    {
        $result = $this->repository->findByUser($this->testUser);
        $this->assertNull($result);
    }

    public function testFindByUserReturnsState(): void
    {
        $userState = new UserState();
        $userState->setUser($this->testUser);
        $userState->setShieldsCount(5);
        $userState->setTotalScore(1000);
        $this->entityManager->persist($userState);
        $this->entityManager->flush();

        $result = $this->repository->findByUser($this->testUser);
        $this->assertNotNull($result);
        $this->assertEquals(5, $result->getShieldsCount());
        $this->assertEquals(1000, $result->getTotalScore());
    }

    public function testFindOrCreateByUserCreatesNew(): void
    {
        $result = $this->repository->findOrCreateByUser($this->testUser);

        $this->assertNotNull($result);
        $this->assertEquals($this->testUser, $result->getUser());
        $this->assertEquals(0, $result->getShieldsCount());
        $this->assertEquals(0.0, $result->getPermanentMultiplier());
        $this->assertEquals('fumeur', $result->getCurrentRank());
        $this->assertEquals(0, $result->getTotalScore());
    }

    public function testFindOrCreateByUserReturnsExisting(): void
    {
        // Create existing state
        $userState = new UserState();
        $userState->setUser($this->testUser);
        $userState->setShieldsCount(10);
        $userState->setTotalScore(5000);
        $userState->setCurrentRank('champion');
        $this->entityManager->persist($userState);
        $this->entityManager->flush();

        // Should return existing, not create new
        $result = $this->repository->findOrCreateByUser($this->testUser);

        $this->assertNotNull($result);
        $this->assertEquals(10, $result->getShieldsCount());
        $this->assertEquals(5000, $result->getTotalScore());
        $this->assertEquals('champion', $result->getCurrentRank());
    }

    public function testDefaultValues(): void
    {
        $userState = new UserState();

        $this->assertEquals(0, $userState->getShieldsCount());
        $this->assertEquals(0.0, $userState->getPermanentMultiplier());
        $this->assertEquals('fumeur', $userState->getCurrentRank());
        $this->assertEquals(0, $userState->getTotalScore());
    }
}
