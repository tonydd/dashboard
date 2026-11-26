<?php

namespace App\Tests\Service;

use App\Service\CameraTokenService;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class CameraTokenServiceTest extends TestCase
{
    private const SECRET = 'test-camera-secret';

    public function testIssuedTokenResolvesToItsRole(): void
    {
        $service = new CameraTokenService(self::SECRET);

        foreach ([CameraTokenService::ROLE_PUBLISHER, CameraTokenService::ROLE_VIEWER] as $role) {
            $issued = $service->issue($role);

            self::assertSame($role, $service->resolve($issued['token']));
            self::assertGreaterThan(time(), $issued['expiresAt']);
        }
    }

    public function testTokenExpiresAfterItsTtl(): void
    {
        $service = new CameraTokenService(self::SECRET);
        $payload = base64_encode((string) json_encode(['role' => CameraTokenService::ROLE_VIEWER, 'exp' => time() - 1]));
        $expired = $payload.'.'.hash_hmac('sha256', $payload, self::SECRET);

        self::assertNull($service->resolve($expired));
    }

    public function testTokenIsRejectedWhenSignedWithAnotherSecret(): void
    {
        $issuer = new CameraTokenService('secret-a');
        $validator = new CameraTokenService('secret-b');

        self::assertNull($validator->resolve($issuer->issue(CameraTokenService::ROLE_VIEWER)['token']));
    }

    #[TestWith([''])]
    #[TestWith(['not-a-token'])]
    #[TestWith(['a.b.c'])]
    public function testMalformedTokensAreRejected(string $token): void
    {
        $service = new CameraTokenService(self::SECRET);

        self::assertNull($service->resolve($token));
    }

    #[TestWith(['admin'])]
    #[TestWith([''])]
    public function testUnsupportedRolesAreRefused(string $role): void
    {
        $service = new CameraTokenService(self::SECRET);

        $this->expectException(\InvalidArgumentException::class);
        $service->issue($role);
    }
}
