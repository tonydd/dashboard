<?php

namespace App\Tests\Service;

use App\Enum\KeyValueKey;
use App\Repository\KeyValueRepository;
use App\Service\TabletBackgroundService;
use PHPUnit\Framework\TestCase;

final class TabletBackgroundServiceTest extends TestCase
{
    private KeyValueRepository $repository;
    private TabletBackgroundService $service;

    protected function setUp(): void
    {
        $this->repository = $this->getMockBuilder(KeyValueRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getValue'])
            ->getMock();
        $this->service = new TabletBackgroundService($this->repository);
    }

    public function testReturnsNullWhenNothingIsStored(): void
    {
        $this->repository->method('getValue')->willReturn(null);

        self::assertNull($this->service->get());
    }

    public function testReturnsNullWhenStoredValueIsMalformed(): void
    {
        $this->repository->method('getValue')->willReturn(['type' => 'data', 'data' => 'not valid base64!']);

        self::assertNull($this->service->get());
    }

    public function testStoresAndReturnsAnUrl(): void
    {
        $stored = null;
        $repository = $this->getMockBuilder(KeyValueRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getValue', 'setValue'])
            ->getMock();
        $repository->method('getValue')
            ->with(KeyValueKey::TabletBackground)
            ->willReturnCallback(static function () use (&$stored) {
                return $stored;
            });
        $repository->expects($this->once())
            ->method('setValue')
            ->with(
                KeyValueKey::TabletBackground,
                ['type' => 'url', 'url' => 'https://example.com/image.jpg'],
            )
            ->willReturnCallback(static function (KeyValueKey $key, $value) use (&$stored): void {
                $stored = $value;
            });

        $service = new TabletBackgroundService($repository);
        $service->setUrl('https://example.com/image.jpg');

        self::assertSame(['type' => 'url', 'url' => 'https://example.com/image.jpg'], $service->get());
    }

    public function testStoresAnUploadedImageAsBase64Data(): void
    {
        $stored = null;
        $binary = random_bytes(16);
        $repository = $this->getMockBuilder(KeyValueRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getValue', 'setValue'])
            ->getMock();
        $repository->method('getValue')->willReturnCallback(static function () use (&$stored) {
            return $stored;
        });
        $repository->expects($this->once())
            ->method('setValue')
            ->with(KeyValueKey::TabletBackground, $this->callback(static function (array $value) use ($binary): bool {
                return $value['type'] === 'data'
                    && $value['mimeType'] === 'image/png'
                    && base64_decode($value['data'], true) === $binary;
            }))
            ->willReturnCallback(static function (KeyValueKey $key, $value) use (&$stored): void {
                $stored = $value;
            });

        $service = new TabletBackgroundService($repository);
        $service->setImage($binary, 'image/png');

        self::assertSame($binary, base64_decode($service->get()['data'], true));
    }

    public function testClearRemovesTheBackground(): void
    {
        $stored = ['type' => 'url', 'url' => 'https://example.com/image.jpg'];
        $repository = $this->getMockBuilder(KeyValueRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getValue', 'setValue'])
            ->getMock();
        $repository->method('getValue')->willReturnCallback(static function () use (&$stored) {
            return $stored;
        });
        $repository->expects($this->once())
            ->method('setValue')
            ->with(KeyValueKey::TabletBackground, null)
            ->willReturnCallback(static function (KeyValueKey $key, $value) use (&$stored): void {
                $stored = $value;
            });

        $service = new TabletBackgroundService($repository);
        $service->clear();

        self::assertNull($service->get());
    }

    public function testRejectsAnInvalidUrl(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->setUrl('not-an-url');
    }

    public function testRejectsANonHttpUrl(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->setUrl('ftp://example.com/image.jpg');
    }

    public function testRejectsAnUnsupportedMimeType(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->setImage('binary', 'application/pdf');
    }

    public function testRejectsAnEmptyImage(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->setImage('', 'image/jpeg');
    }

    public function testRejectsAnOversizedImage(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->setImage(str_repeat('a', TabletBackgroundService::MAX_IMAGE_BYTES + 1), 'image/png');
    }
}
