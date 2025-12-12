<?php

namespace App\Tests\Repository;

use App\Entity\DailyScore;
use App\Entity\User;
use App\Repository\DailyScoreRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class DailyScoreRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private DailyScoreRepository $repository;
    private User $testUser;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->repository = $container->get(DailyScoreRepository::class);

        // Create test user
        $this->testUser = new User();
        $this->testUser->setEmail('dailyscore-test-' . uniqid() . '@example.com');
        $this->testUser->setPassword('password');
        $this->entityManager->persist($this->testUser);
        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        // Clean up test data
        $conn = $this->entityManager->getConnection();
        $conn->executeStatement('DELETE FROM daily_score WHERE user_id = ?', [$this->testUser->getId()]);
        $conn->executeStatement('DELETE FROM user WHERE id = ?', [$this->testUser->getId()]);

        parent::tearDown();
    }

    private function createDailyScore(\DateTimeInterface $date, int $score, int $cigCount, int $streak): DailyScore
    {
        $dailyScore = new DailyScore();
        $dailyScore->setUser($this->testUser);
        $dailyScore->setDate($date);
        $dailyScore->setScore($score);
        $dailyScore->setCigaretteCount($cigCount);
        $dailyScore->setStreak($streak);
        $this->entityManager->persist($dailyScore);
        return $dailyScore;
    }

    public function testFindByDateReturnsNullWithoutUser(): void
    {
        // Without security context (no logged in user), should return null
        $result = $this->repository->findByDate(new \DateTime());
        $this->assertNull($result);
    }

    public function testGetTotalScoreReturnsZeroWithoutUser(): void
    {
        $result = $this->repository->getTotalScore();
        $this->assertEquals(0, $result);
    }

    public function testGetCurrentStreakReturnsZeroWithoutUser(): void
    {
        $result = $this->repository->getCurrentStreak();
        $this->assertEquals(0, $result);
    }

    public function testGetBestStreakReturnsZeroWithoutUser(): void
    {
        $result = $this->repository->getBestStreak();
        $this->assertEquals(0, $result);
    }

    public function testGetRecentScoresReturnsEmptyWithoutUser(): void
    {
        $result = $this->repository->getRecentScores(7);
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testDailyScoreEntityGettersAndSetters(): void
    {
        $dailyScore = new DailyScore();

        $dailyScore->setUser($this->testUser);
        $this->assertEquals($this->testUser, $dailyScore->getUser());

        $date = new \DateTime('2024-12-10');
        $dailyScore->setDate($date);
        $this->assertEquals($date, $dailyScore->getDate());

        $dailyScore->setScore(150);
        $this->assertEquals(150, $dailyScore->getScore());

        $dailyScore->setCigaretteCount(8);
        $this->assertEquals(8, $dailyScore->getCigaretteCount());

        $dailyScore->setStreak(5);
        $this->assertEquals(5, $dailyScore->getStreak());

        $dailyScore->setAverageInterval(65.5);
        $this->assertEquals(65.5, $dailyScore->getAverageInterval());

        $calcAt = new \DateTime();
        $dailyScore->setCalculatedAt($calcAt);
        $this->assertEquals($calcAt, $dailyScore->getCalculatedAt());
    }

    public function testUpsertCreatesNewRecord(): void
    {
        $dailyScore = new DailyScore();
        $dailyScore->setUser($this->testUser);
        $dailyScore->setDate(new \DateTime('2024-01-15'));
        $dailyScore->setScore(100);
        $dailyScore->setCigaretteCount(10);
        $dailyScore->setStreak(3);

        $this->repository->upsert($dailyScore);

        // Verify it was created
        $found = $this->repository->findOneBy([
            'user' => $this->testUser,
            'date' => new \DateTime('2024-01-15'),
        ]);

        $this->assertNotNull($found);
        $this->assertEquals(100, $found->getScore());
    }

    public function testUpsertUpdatesExistingRecord(): void
    {
        // Create initial record
        $date = new \DateTime('2024-01-16');
        $dailyScore1 = new DailyScore();
        $dailyScore1->setUser($this->testUser);
        $dailyScore1->setDate($date);
        $dailyScore1->setScore(50);
        $dailyScore1->setCigaretteCount(12);
        $dailyScore1->setStreak(1);
        $this->entityManager->persist($dailyScore1);
        $this->entityManager->flush();

        // Upsert with new values
        $dailyScore2 = new DailyScore();
        $dailyScore2->setUser($this->testUser);
        $dailyScore2->setDate($date);
        $dailyScore2->setScore(75);
        $dailyScore2->setCigaretteCount(10);
        $dailyScore2->setStreak(2);

        $this->repository->upsert($dailyScore2);

        // Verify it was updated
        $this->entityManager->clear();
        $found = $this->repository->findOneBy([
            'user' => $this->testUser,
            'date' => $date,
        ]);

        $this->assertNotNull($found);
        $this->assertEquals(75, $found->getScore());
        $this->assertEquals(10, $found->getCigaretteCount());
        $this->assertEquals(2, $found->getStreak());
    }
}
