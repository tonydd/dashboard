<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ThermorControllerTest extends WebTestCase
{
    public function testAbsenceModeControlRequiresBearerToken(): void
    {
        $client = static::createClient();

        foreach (['POST', 'DELETE'] as $method) {
            $client->request($method, '/api/thermor/absence-mode');
            self::assertResponseStatusCodeSame(401);
        }
    }

    public function testAbsenceModeControlRejectsInvalidBearerToken(): void
    {
        $client = static::createClient();
        $client->request(
            'POST',
            '/api/thermor/absence-mode',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer invalid-token'],
        );

        self::assertResponseStatusCodeSame(401);
    }
}
