<?php

namespace App\Tests\Controller;

use App\Entity\DashboardUser;
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

    public function testActionsRequireAnAuthenticatedSession(): void
    {
        $client = static::createClient();

        $client->request('POST', '/api/thermor/actions/boost', [], [], [], '{"enabled": true}');

        self::assertResponseStatusCodeSame(401);
    }

    public function testActionsAreDeniedToViewers(): void
    {
        $client = static::createClient();
        $client->loginUser($this->persistUser('thermor-actions-viewer', ['ROLE_VIEWER']));

        $client->request(
            'POST',
            '/api/thermor/actions/boost',
            [],
            [],
            ['HTTP_X_CSRF_TOKEN' => $this->csrfToken($client)],
            '{"enabled": true}',
        );

        self::assertResponseStatusCodeSame(403);
    }

    public function testActionsRequireTheCsrfHeader(): void
    {
        $client = static::createClient();
        $client->loginUser($this->persistUser('thermor-actions-admin', ['ROLE_ADMIN']));

        $client->request('POST', '/api/thermor/actions/boost', [], [], [], '{"enabled": true}');

        // An invalid CSRF token is an authentication failure handled by the
        // API entry point, not a plain authorization denial.
        self::assertResponseStatusCodeSame(401);
    }

    public function testActionsRejectInvalidJson(): void
    {
        $client = static::createClient();
        $client->loginUser($this->persistUser('thermor-actions-admin', ['ROLE_ADMIN']));

        $client->request(
            'POST',
            '/api/thermor/actions/boost',
            [],
            [],
            ['HTTP_X_CSRF_TOKEN' => $this->csrfToken($client)],
            '{invalid',
        );

        self::assertResponseStatusCodeSame(400);
    }

    public function testActionsRejectWronglyTypedFields(): void
    {
        $client = static::createClient();
        $client->loginUser($this->persistUser('thermor-actions-admin', ['ROLE_ADMIN']));

        $client->request(
            'POST',
            '/api/thermor/actions/boost',
            [],
            [],
            ['HTTP_X_CSRF_TOKEN' => $this->csrfToken($client)],
            '{"enabled": "yes"}',
        );

        self::assertResponseStatusCodeSame(400);
    }

    public function testActionsRejectUnknownValuesWithoutCallingTheCloud(): void
    {
        $client = static::createClient();
        $client->loginUser($this->persistUser('thermor-actions-admin', ['ROLE_ADMIN']));

        foreach ([
            ['/api/thermor/actions/dhw-mode', '{"mode": "turbo"}'],
            ['/api/thermor/actions/smart-grid', '{"option": "turbo"}'],
            ['/api/thermor/actions/operating-range', '{"range": "turbo"}'],
            ['/api/thermor/actions/expected-showers', '{"count": 12}'],
            ['/api/thermor/actions/target-temperature', '{"temperature": 80}'],
            ['/api/thermor/actions/programming-slots', '{"slots": {"slot1": {"start": "8h", "end": "20h"}}}'],
        ] as [$url, $body]) {
            $client->request(
                'POST',
                $url,
                [],
                [],
                ['HTTP_X_CSRF_TOKEN' => $this->csrfToken($client)],
                $body,
            );

            self::assertResponseStatusCodeSame(422, $url);
        }
    }

    private function csrfToken($client): string
    {
        $client->request('GET', '/api/session');
        $session = json_decode($client->getResponse()->getContent(), true);

        if (!is_array($session) || !is_string($session['csrfToken'] ?? null)) {
            self::fail('The /api/session response did not contain a CSRF token.');
        }

        return $session['csrfToken'];
    }

    public function testOffPeakScheduleIsDeniedToViewers(): void
    {
        $client = static::createClient();
        $client->loginUser($this->persistUser('thermor-actions-viewer', ['ROLE_VIEWER']));

        $client->request(
            'POST',
            '/api/thermor/actions/off-peak-schedule',
            [],
            [],
            ['HTTP_X_CSRF_TOKEN' => $this->csrfToken($client)],
            '{"days": {}}',
        );

        self::assertResponseStatusCodeSame(403);
    }

    public function testOffPeakScheduleRejectsAMissingDaysObject(): void
    {
        $client = static::createClient();
        $client->loginUser($this->persistUser('thermor-actions-admin', ['ROLE_ADMIN']));

        $client->request(
            'POST',
            '/api/thermor/actions/off-peak-schedule',
            [],
            [],
            ['HTTP_X_CSRF_TOKEN' => $this->csrfToken($client)],
            '{"periods": []}',
        );

        self::assertResponseStatusCodeSame(400);
    }

    public function testOffPeakScheduleRejectsInvalidPeriods(): void
    {
        $client = static::createClient();
        $client->loginUser($this->persistUser('thermor-actions-admin', ['ROLE_ADMIN']));

        $client->request(
            'POST',
            '/api/thermor/actions/off-peak-schedule',
            [],
            [],
            ['HTTP_X_CSRF_TOKEN' => $this->csrfToken($client)],
            '{"days": {"monday": [{"start": "22:00", "end": "01:00"}]}}',
        );

        self::assertResponseStatusCodeSame(422);
    }

    public function testOffPeakScheduleStoresMultiplePeriodsPerDay(): void
    {
        $client = static::createClient();
        $client->loginUser($this->persistUser('thermor-actions-admin', ['ROLE_ADMIN']));

        $days = [
            'monday' => [['start' => '00:00', 'end' => '05:00'], ['start' => '22:30', 'end' => '23:45']],
            'tuesday' => [],
            'wednesday' => [['start' => '01:00', 'end' => '07:00']],
            'thursday' => [],
            'friday' => [],
            'saturday' => [['start' => '00:30', 'end' => '08:00']],
            'sunday' => [],
        ];

        $client->request(
            'POST',
            '/api/thermor/actions/off-peak-schedule',
            [],
            [],
            ['HTTP_X_CSRF_TOKEN' => $this->csrfToken($client)],
            json_encode(['days' => $days]),
        );

        self::assertResponseIsSuccessful();
        self::assertSame(
            $days,
            json_decode($client->getResponse()->getContent(), true)['schedule']['days'],
        );
    }

    private function persistUser(string $username, array $roles): DashboardUser
    {
        $entityManager = static::getContainer()->get('doctrine')->getManager();
        $repository = $entityManager->getRepository(DashboardUser::class);
        if (($user = $repository->findOneBy(['username' => $username])) !== null) {
            $user->setRoles($roles);
            $entityManager->flush();

            return $user;
        }

        $user = new DashboardUser($username);
        $user->setRoles($roles);
        $user->setPassword('not-used-for-page-render');
        $entityManager->persist($user);
        $entityManager->flush();

        return $user;
    }
}

