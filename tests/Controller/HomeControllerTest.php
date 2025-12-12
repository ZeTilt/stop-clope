<?php

namespace App\Tests\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class HomeControllerTest extends WebTestCase
{
    public function testHomePageRequiresAuthentication(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        // Should redirect to login
        $this->assertResponseRedirects('/login');
    }

    public function testStatsPageRequiresAuthentication(): void
    {
        $client = static::createClient();
        $client->request('GET', '/stats');

        $this->assertResponseRedirects('/login');
    }

    public function testSettingsPageRequiresAuthentication(): void
    {
        $client = static::createClient();
        $client->request('GET', '/settings');

        $this->assertResponseRedirects('/login');
    }

    public function testLogCigaretteRequiresAuthentication(): void
    {
        $client = static::createClient();
        $client->request('POST', '/log');

        $this->assertResponseRedirects('/login');
    }

    public function testDeleteCigaretteRequiresAuthentication(): void
    {
        $client = static::createClient();
        $client->request('POST', '/delete/1');

        $this->assertResponseRedirects('/login');
    }

    public function testLoginPageIsAccessible(): void
    {
        $client = static::createClient();
        $client->request('GET', '/login');

        $this->assertResponseIsSuccessful();
    }

    public function testRegisterPageIsAccessible(): void
    {
        $client = static::createClient();
        $client->request('GET', '/register');

        $this->assertResponseIsSuccessful();
    }

    // ========== Tests avec utilisateur authentifié ==========

    private function createAuthenticatedClient(): array
    {
        $client = static::createClient();
        $container = static::getContainer();

        $entityManager = $container->get(EntityManagerInterface::class);
        $userRepository = $container->get(UserRepository::class);
        $passwordHasher = $container->get('security.password_hasher');

        // Clean up any existing test user
        $existingUser = $userRepository->findOneBy(['email' => 'home-test@example.com']);
        if ($existingUser) {
            $entityManager->remove($existingUser);
            $entityManager->flush();
        }

        // Create test user
        $user = new User();
        $user->setEmail('home-test@example.com');
        $user->setPassword($passwordHasher->hashPassword($user, 'password'));
        $entityManager->persist($user);
        $entityManager->flush();

        $client->loginUser($user);

        return [$client, $user, $entityManager];
    }

    private function cleanupUser(EntityManagerInterface $entityManager, User $user): void
    {
        $entityManager->remove($user);
        $entityManager->flush();
    }

    /**
     * @group mysql-only
     * Uses MySQL-specific functions (DATE_SUB, CURDATE) that don't exist in SQLite
     */
    public function testHomePageAccessibleWhenAuthenticated(): void
    {
        $this->markTestSkipped('Uses MySQL-specific functions in getAverageDailyCount()');
    }

    /**
     * @group mysql-only
     * Uses MySQL-specific functions (DATE_SUB, CURDATE) that don't exist in SQLite
     */
    public function testStatsPageAccessibleWhenAuthenticated(): void
    {
        $this->markTestSkipped('Uses MySQL-specific functions in getAverageDailyCount()');
    }

    /**
     * @group mysql-only
     * Uses MySQL-specific functions (DATE_SUB, CURDATE) that don't exist in SQLite
     */
    public function testSettingsPageAccessibleWhenAuthenticated(): void
    {
        $this->markTestSkipped('Uses MySQL-specific functions in getAverageDailyCount()');
    }

    public function testHistoryPageRedirectsWhenNoData(): void
    {
        [$client, $user, $em] = $this->createAuthenticatedClient();

        $client->request('GET', '/history');
        // Redirects to home when no data
        $this->assertResponseRedirects('/');

        $this->cleanupUser($em, $user);
    }

    public function testLogCigaretteRequiresCsrf(): void
    {
        [$client, $user, $em] = $this->createAuthenticatedClient();

        // Without CSRF token, should return 403
        $client->request('POST', '/log', [], [], [
            'HTTP_X-Requested-With' => 'XMLHttpRequest',
        ]);

        $this->assertResponseStatusCodeSame(403);
        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $response);
        $this->assertStringContainsString('CSRF', $response['error']);

        $this->cleanupUser($em, $user);
    }

    public function testLogWakeupRequiresCsrf(): void
    {
        [$client, $user, $em] = $this->createAuthenticatedClient();

        // Without CSRF token, should return 403
        $client->request('POST', '/wakeup', [
            'wake_time' => '07:30',
        ], [], [
            'HTTP_X-Requested-With' => 'XMLHttpRequest',
        ]);

        $this->assertResponseStatusCodeSame(403);

        $this->cleanupUser($em, $user);
    }

    public function testSaveSettingsRequiresCsrf(): void
    {
        [$client, $user, $em] = $this->createAuthenticatedClient();

        // Without CSRF token, should return 403
        $client->request('POST', '/settings/save', [
            'pack_price' => '12.50',
        ], [], [
            'HTTP_X-Requested-With' => 'XMLHttpRequest',
        ]);

        $this->assertResponseStatusCodeSame(403);

        $this->cleanupUser($em, $user);
    }

    public function testExportCsvRedirectsWhenNoData(): void
    {
        [$client, $user, $em] = $this->createAuthenticatedClient();

        $client->request('GET', '/export/csv');

        // Should redirect (flash message) since no data to export
        $this->assertResponseRedirects('/stats');

        $this->cleanupUser($em, $user);
    }

    public function testExportJsonReturns404WhenNoData(): void
    {
        [$client, $user, $em] = $this->createAuthenticatedClient();

        $client->request('GET', '/export/json');

        // Should return 404 since no data to export
        $this->assertResponseStatusCodeSame(404);

        $this->cleanupUser($em, $user);
    }
}
