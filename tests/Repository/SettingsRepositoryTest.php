<?php

namespace App\Tests\Repository;

use App\Entity\Settings;
use App\Entity\User;
use App\Repository\SettingsRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class SettingsRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private SettingsRepository $repository;
    private User $testUser;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->repository = $container->get(SettingsRepository::class);

        // Create test user
        $this->testUser = new User();
        $this->testUser->setEmail('settings-test-' . uniqid() . '@example.com');
        $this->testUser->setPassword('password');
        $this->entityManager->persist($this->testUser);
        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        // Clean up test data
        $conn = $this->entityManager->getConnection();
        $conn->executeStatement('DELETE FROM settings WHERE user_id = ?', [$this->testUser->getId()]);
        $conn->executeStatement('DELETE FROM user WHERE id = ?', [$this->testUser->getId()]);

        parent::tearDown();
    }

    public function testGetReturnsDefaultWhenNotFound(): void
    {
        $result = $this->repository->get('non_existent_setting', 'default_value');
        $this->assertEquals('default_value', $result);
    }

    public function testGetReturnsNullWhenNotFoundAndNoDefault(): void
    {
        $result = $this->repository->get('non_existent_setting');
        $this->assertNull($result);
    }

    public function testSetAndGetWithoutUser(): void
    {
        // Without user context, settings are global
        $settingName = 'test_setting_' . uniqid();

        // Create a global setting directly
        $setting = new Settings();
        $setting->setName($settingName);
        $setting->setValue('test_value');
        $this->entityManager->persist($setting);
        $this->entityManager->flush();

        // Should be able to get it
        $result = $this->repository->get($settingName);
        $this->assertEquals('test_value', $result);

        // Cleanup
        $this->entityManager->remove($setting);
        $this->entityManager->flush();
    }

    public function testSettingEntityGettersAndSetters(): void
    {
        $setting = new Settings();

        $setting->setName('test_name');
        $this->assertEquals('test_name', $setting->getName());

        $setting->setValue('test_value');
        $this->assertEquals('test_value', $setting->getValue());

        $setting->setUser($this->testUser);
        $this->assertEquals($this->testUser, $setting->getUser());
    }

    public function testGetDefaultValues(): void
    {
        // Test common default values
        $packPrice = $this->repository->get('pack_price', '12.00');
        $this->assertEquals('12.00', $packPrice);

        $cigsPerPack = $this->repository->get('cigs_per_pack', '20');
        $this->assertEquals('20', $cigsPerPack);

        $initialDaily = $this->repository->get('initial_daily_cigs', '20');
        $this->assertEquals('20', $initialDaily);
    }
}
