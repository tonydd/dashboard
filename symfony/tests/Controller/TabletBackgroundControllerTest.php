<?php

namespace App\Tests\Controller;

use App\Entity\DashboardUser;
use App\Service\TabletBackgroundService;
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

    public function testSaveStoresAnImageUrlForAdminUsers(): void
    {
        $client = static::createClient();
        $user = $this->persistAdminUser('tablet-background-test');
        $client->loginUser($user);

        $crawler = $client->request('GET', '/tablet-background');
        $csrfToken = $crawler->filter('input[name="_csrf_token"]')->attr('value');

        try {
            $client->request('POST', '/tablet-background', [
                'url' => 'https://example.com/background.jpg',
                '_csrf_token' => $csrfToken,
            ]);

            self::assertResponseRedirects('/tablet-background');
            self::assertSame(
                ['type' => 'url', 'url' => 'https://example.com/background.jpg'],
                static::getContainer()->get(TabletBackgroundService::class)->get()
            );
        } finally {
            static::getContainer()->get(TabletBackgroundService::class)->clear();
        }
    }

    private function persistAdminUser(string $username): DashboardUser
    {
        $entityManager = static::getContainer()->get('doctrine')->getManager();
        $repository = $entityManager->getRepository(DashboardUser::class);
        if (($user = $repository->findOneBy(['username' => $username])) !== null) {
            $user->setRoles(['ROLE_ADMIN']);
            $entityManager->flush();

            return $user;
        }

        $user = new DashboardUser($username);
        $user->setRoles(['ROLE_ADMIN']);
        $user->setPassword('not-used-for-page-render');
        $entityManager->persist($user);
        $entityManager->flush();

        return $user;
    }
}
