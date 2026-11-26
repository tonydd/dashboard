<?php

namespace App\Tests\Controller;

use App\Entity\DashboardUser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CameraControllerTest extends WebTestCase
{
    public function testCameraNodeRendersServerLoginForAnonymousUsers(): void
    {
        $client = static::createClient();

        $client->request('GET', '/camera-node');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#dashboard-login');
    }

    public function testCameraNodePageCarriesTheCameraNodeContract(): void
    {
        $client = static::createClient();
        $user = $this->persistUser('camera-node-test');
        $client->loginUser($user);

        $client->request('GET', '/camera-node');
        $crawler = $client->getCrawler();

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-camera-node]');
        self::assertSelectorExists('[data-camera-label]');
        self::assertSelectorExists('[data-ws-state]');
        // The camera node is installed as its own PWA, distinct from the tablet one.
        self::assertSelectorExists('link[rel="manifest"][href="/manifest-camera-node.json"]');
    }

    private function persistUser(string $username): DashboardUser
    {
        $entityManager = static::getContainer()->get('doctrine')->getManager();
        $repository = $entityManager->getRepository(DashboardUser::class);
        if (($existing = $repository->findOneBy(['username' => $username])) !== null) {
            return $existing;
        }

        $user = new DashboardUser($username);
        $user->setPassword('not-used-for-page-render');
        $entityManager->persist($user);
        $entityManager->flush();

        return $user;
    }
}
