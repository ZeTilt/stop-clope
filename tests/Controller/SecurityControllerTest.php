<?php

namespace App\Tests\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class SecurityControllerTest extends WebTestCase
{
    public function testLoginPageIsAccessible(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/login');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form');
    }

    public function testLoginPageContainsUsernameField(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/login');

        // Login form uses _username for email
        $this->assertSelectorExists('input[name="_username"]');
    }

    public function testLoginPageContainsPasswordField(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/login');

        // Login form uses _password
        $this->assertSelectorExists('input[name="_password"]');
    }

    public function testRegisterPageIsAccessible(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/register');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form');
    }

    public function testRegisterFormContainsEmailField(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/register');

        $this->assertSelectorExists('input[type="email"]');
    }

    public function testRegisterFormContainsPasswordField(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/register');

        $this->assertSelectorExists('input[type="password"]');
    }

    public function testLoginWithInvalidCredentials(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/login');

        // Find submit button by type
        $form = $crawler->selectButton('Connexion')->form([
            '_username' => 'invalid@example.com',
            '_password' => 'wrongpassword',
        ]);

        $client->submit($form);

        // Should redirect back to login with error
        $this->assertResponseRedirects('/login');
    }

    public function testRegisterWithValidData(): void
    {
        $client = static::createClient();

        // Clean up any existing test user
        $container = static::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $userRepository = $container->get(UserRepository::class);

        $existingUser = $userRepository->findOneBy(['email' => 'test-register@example.com']);
        if ($existingUser) {
            $entityManager->remove($existingUser);
            $entityManager->flush();
        }

        $crawler = $client->request('GET', '/register');

        $form = $crawler->selectButton('Creer mon compte')->form([
            'registration_form[email]' => 'test-register@example.com',
            'registration_form[plainPassword][first]' => 'TestPassword123!',
            'registration_form[plainPassword][second]' => 'TestPassword123!',
        ]);

        $client->submit($form);

        // Should redirect to login after successful registration
        $this->assertResponseRedirects('/login');

        // Verify user was created
        $entityManager->clear();
        $user = $userRepository->findOneBy(['email' => 'test-register@example.com']);
        $this->assertNotNull($user);
        $this->assertEquals('test-register@example.com', $user->getEmail());

        // Cleanup
        $entityManager->remove($user);
        $entityManager->flush();
    }

    public function testRegisterWithInvalidEmail(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/register');

        $form = $crawler->selectButton('Creer mon compte')->form([
            'registration_form[email]' => 'invalid-email',
            'registration_form[plainPassword][first]' => 'TestPassword123!',
            'registration_form[plainPassword][second]' => 'TestPassword123!',
        ]);

        $client->submit($form);

        // Form invalid returns 422 Unprocessable Content in Symfony 7
        $this->assertResponseStatusCodeSame(422);
    }

    public function testRegisterWithMismatchedPasswords(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/register');

        $form = $crawler->selectButton('Creer mon compte')->form([
            'registration_form[email]' => 'mismatch@example.com',
            'registration_form[plainPassword][first]' => 'Password123!',
            'registration_form[plainPassword][second]' => 'DifferentPassword456!',
        ]);

        $client->submit($form);

        // Form invalid returns 422 Unprocessable Content in Symfony 7
        $this->assertResponseStatusCodeSame(422);
    }

    public function testLogoutRequiresAuthentication(): void
    {
        $client = static::createClient();
        $client->request('GET', '/logout');

        // Logout without being logged in should redirect
        $this->assertResponseRedirects();
    }

    public function testLoginRedirectsAuthenticatedUser(): void
    {
        $client = static::createClient();

        // Create and login a test user
        $container = static::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $userRepository = $container->get(UserRepository::class);
        $passwordHasher = $container->get('security.password_hasher');

        // Clean up
        $existingUser = $userRepository->findOneBy(['email' => 'auth-test@example.com']);
        if ($existingUser) {
            $entityManager->remove($existingUser);
            $entityManager->flush();
        }

        // Create user
        $user = new User();
        $user->setEmail('auth-test@example.com');
        $user->setPassword($passwordHasher->hashPassword($user, 'password'));
        $entityManager->persist($user);
        $entityManager->flush();

        // Login
        $client->loginUser($user);

        // Try to access login page
        $client->request('GET', '/login');

        // Should redirect to home
        $this->assertResponseRedirects('/');

        // Cleanup
        $entityManager->remove($user);
        $entityManager->flush();
    }

    public function testRegisterRedirectsAuthenticatedUser(): void
    {
        $client = static::createClient();

        // Create and login a test user
        $container = static::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $userRepository = $container->get(UserRepository::class);
        $passwordHasher = $container->get('security.password_hasher');

        // Clean up
        $existingUser = $userRepository->findOneBy(['email' => 'auth-test2@example.com']);
        if ($existingUser) {
            $entityManager->remove($existingUser);
            $entityManager->flush();
        }

        // Create user
        $user = new User();
        $user->setEmail('auth-test2@example.com');
        $user->setPassword($passwordHasher->hashPassword($user, 'password'));
        $entityManager->persist($user);
        $entityManager->flush();

        // Login
        $client->loginUser($user);

        // Try to access register page
        $client->request('GET', '/register');

        // Should redirect to home
        $this->assertResponseRedirects('/');

        // Cleanup
        $entityManager->remove($user);
        $entityManager->flush();
    }
}
