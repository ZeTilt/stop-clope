<?php

namespace App\Tests\Controller;

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
}
