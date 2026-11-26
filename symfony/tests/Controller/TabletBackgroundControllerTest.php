<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class TabletBackgroundControllerTest extends WebTestCase
{
    public function testSettingsScreenRendersServerLoginForAnonymousUsers(): void
    {
        $client = static::createClient();

        $client->request('GET', '/tablet-background');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#dashboard-login');
    }

    public function testImageEndpointRequiresAnAuthenticatedSession(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/tablet-background/image');

        self::assertResponseStatusCodeSame(401);
    }

    public function testSaveRequiresAnAuthenticatedSession(): void
    {
        $client = static::createClient();

        $client->request('POST', '/tablet-background');

        self::assertResponseStatusCodeSame(401);
    }
}
