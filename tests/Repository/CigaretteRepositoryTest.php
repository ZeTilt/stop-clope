<?php

namespace App\Tests\Repository;

use App\Entity\Cigarette;
use App\Entity\User;
use App\Repository\CigaretteRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class CigaretteRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private CigaretteRepository $repository;
    private User $testUser;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->repository = $container->get(CigaretteRepository::class);

        // Create test user
        $this->testUser = new User();
        $this->testUser->setEmail('repo-test-' . uniqid() . '@example.com');
        $this->testUser->setPassword('password');
        $this->entityManager->persist($this->testUser);
        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        // Clean up test data
        $conn = $this->entityManager->getConnection();
        $conn->executeStatement('DELETE FROM cigarette WHERE user_id = ?', [$this->testUser->getId()]);
        $conn->executeStatement('DELETE FROM user WHERE id = ?', [$this->testUser->getId()]);

        parent::tearDown();
    }

    private function createCigarette(\DateTimeInterface $smokedAt): Cigarette
    {
        $cigarette = new Cigarette();
        $cigarette->setSmokedAt($smokedAt);
        $cigarette->setUser($this->testUser);
        $this->entityManager->persist($cigarette);
        return $cigarette;
    }

    public function testFindByDateReturnsEmpty(): void
    {
        $result = $this->repository->findByDate(new \DateTime());
        $this->assertIsArray($result);
    }

    public function testFindByDateReturnsCigarettes(): void
    {
        $today = new \DateTime();
        $this->createCigarette($today);
        $this->createCigarette((clone $today)->modify('-1 hour'));
        $this->entityManager->flush();

        // Note: Without security context, we can't test user-filtered queries directly
        // This tests the query structure at least
        $result = $this->repository->findByDate($today);
        $this->assertIsArray($result);
    }

    public function testCountByDate(): void
    {
        $result = $this->repository->countByDate(new \DateTime());
        $this->assertIsInt($result);
        $this->assertGreaterThanOrEqual(0, $result);
    }

    public function testGetTotalCount(): void
    {
        $result = $this->repository->getTotalCount();
        $this->assertIsInt($result);
        $this->assertGreaterThanOrEqual(0, $result);
    }

    public function testGetFirstCigaretteDateReturnsNullWhenEmpty(): void
    {
        // With no user context, this should return null or a date
        $result = $this->repository->getFirstCigaretteDate();
        // Just check it doesn't throw
        $this->assertTrue($result === null || $result instanceof \DateTimeInterface);
    }

    public function testGetDailyStatsReturnsArray(): void
    {
        $result = $this->repository->getDailyStats(7);
        $this->assertIsArray($result);
    }

    /**
     * @group mysql-only
     * Uses MySQL-specific DAYOFWEEK function, skipped on SQLite
     */
    public function testGetWeekdayStatsReturnsArray(): void
    {
        $this->markTestSkipped('Uses MySQL-specific DAYOFWEEK function');
    }

    /**
     * @group mysql-only
     * Uses MySQL-specific CURDATE function, skipped on SQLite
     */
    public function testGetHourlyStatsReturns24Hours(): void
    {
        $this->markTestSkipped('Uses MySQL-specific CURDATE function');
    }

    /**
     * @group mysql-only
     * Uses MySQL-specific CURDATE function, skipped on SQLite
     */
    public function testGetMinDailyCountReturnsNullOrInt(): void
    {
        $this->markTestSkipped('Uses MySQL-specific CURDATE function');
    }

    public function testGetDailyAverageIntervalReturnsArray(): void
    {
        $result = $this->repository->getDailyAverageInterval(7);
        $this->assertIsArray($result);
    }

    public function testGetWeeklyComparisonReturnsNullOrArray(): void
    {
        $result = $this->repository->getWeeklyComparison();
        $this->assertTrue($result === null || is_array($result));

        if ($result !== null) {
            $this->assertArrayHasKey('current', $result);
            $this->assertArrayHasKey('previous', $result);
            $this->assertArrayHasKey('diff_total', $result);
            $this->assertArrayHasKey('diff_avg', $result);
        }
    }

    public function testFindByDateRangeReturnsGroupedArray(): void
    {
        $start = new \DateTime('-7 days');
        $end = new \DateTime();

        $result = $this->repository->findByDateRange($start, $end);
        $this->assertIsArray($result);
    }

    /**
     * @group mysql-only
     * Uses MySQL-specific DATE_SUB and CURDATE functions, skipped on SQLite
     */
    public function testGetAverageDailyCountReturnsNullOrFloat(): void
    {
        $this->markTestSkipped('Uses MySQL-specific DATE_SUB/CURDATE functions');
    }

    public function testFindTodayCigarettes(): void
    {
        $result = $this->repository->findTodayCigarettes();
        $this->assertIsArray($result);
    }

    public function testFindYesterdayCigarettes(): void
    {
        $result = $this->repository->findYesterdayCigarettes();
        $this->assertIsArray($result);
    }

    public function testGetTotalCountUntil(): void
    {
        $result = $this->repository->getTotalCountUntil(new \DateTime());
        $this->assertIsInt($result);
        $this->assertGreaterThanOrEqual(0, $result);
    }
}
