<?php

namespace App\Tests\Controller;

use App\Entity\DashboardUser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class WaterHeaterControllerTest extends WebTestCase
{
    public function testScreenRendersServerLoginForAnonymousUsers(): void
    {
        $client = static::createClient();

        $client->request('GET', '/water-heater');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#dashboard-login');
    }

    public function testScreenIsDeniedToViewers(): void
    {
        $client = static::createClient();
        $client->loginUser($this->persistUser('water-heater-viewer', ['ROLE_VIEWER']));

        $client->request('GET', '/water-heater');

        self::assertResponseStatusCodeSame(403);
    }

    public function testScreenRendersForAdmins(): void
    {
        $client = static::createClient();
        $client->loginUser($this->persistUser('water-heater-admin', ['ROLE_ADMIN']));

        $client->request('GET', '/water-heater');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-thermor-actions]');
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
