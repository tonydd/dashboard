<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AuthenticationControllerTest extends WebTestCase
{
    public function testSessionEndpointIsPublicAndProvidesCsrfToken(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/session');

        self::assertResponseIsSuccessful();
        $response = json_decode($client->getResponse()->getContent(), true);
        self::assertFalse($response['authenticated']);
        self::assertNotEmpty($response['csrfToken']);
    }

    public function testApiEndpointsRequireAnAuthenticatedSession(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/thermor');

        self::assertResponseStatusCodeSame(401);

        $client->request('POST', '/api/recipes', server: ['CONTENT_TYPE' => 'application/json'], content: '{}');

        self::assertResponseStatusCodeSame(401);
    }
}
