<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class StatusControllerTest extends WebTestCase
{
    public function testStatusPageRendersServerLoginForAnonymousUsers(): void
    {
        $client = static::createClient();

        $client->request('GET', '/status');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#dashboard-login');
    }

    public function testStatusApiRequiresAnAuthenticatedSession(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/status');

        self::assertResponseStatusCodeSame(401);
    }
}
