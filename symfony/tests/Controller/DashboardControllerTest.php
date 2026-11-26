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

        $client->request('GET', '/camera');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#dashboard-login');
    }

    public function testLoginPageCarriesTheRequestedPathForThePostLoginRedirect(): void
    {
        $client = static::createClient();

        $client->request('GET', '/slideshow?name=cuisine');
        self::assertSelectorExists('#dashboard-login');
        self::assertInputValueSame('redirect', '/slideshow?name=cuisine');
    }

    public function testCameraTokenRequiresAnAuthenticatedSession(): void
    {
        $client = static::createClient();

        $client->request('POST', '/api/camera/token', server: ['CONTENT_TYPE' => 'application/json'], content: '{"role":"viewer"}');

        self::assertResponseStatusCodeSame(401);
    }

    public function testCameraTokenRefusesUnsupportedRolesForAnonymousUsersToo(): void
    {
        $client = static::createClient();

        $client->request('POST', '/api/camera/token', server: ['CONTENT_TYPE' => 'application/json'], content: '{"role":"admin"}');

        self::assertResponseStatusCodeSame(401);
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
