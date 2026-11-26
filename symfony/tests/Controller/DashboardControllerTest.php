<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class DashboardControllerTest extends WebTestCase
{
    public function testTabletAndSlideshowRenderServerLoginForAnonymousUsers(): void
    {
        $client = static::createClient();

        $client->request('GET', '/tablet');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#dashboard-login');

        $client->request('GET', '/slideshow');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#dashboard-login');
    }

    public function testDeprecatedDashboardRoutesAreNotRegistered(): void
    {
        $client = static::createClient();
        $client->request('GET', '/calendar');
        self::assertResponseStatusCodeSame(404);

        $client->request('GET', '/recipe/list');
        self::assertResponseStatusCodeSame(404);
    }

}
