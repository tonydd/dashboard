<?php

namespace App\Tests\Service;

use App\Repository\KeyValueRepository;
use App\Repository\WaterHeaterStatRepository;
use App\Service\ThermorApiRateLimiter;
use App\Service\ThermorService;
use App\Service\ThermorStateParser;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Contracts\Cache\CacheInterface;

final class ThermorServiceTest extends TestCase
{
    private ThermorService $service;

    protected function setUp(): void
    {
        $this->service = new ThermorService(
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(CacheInterface::class),
            $this->createMock(KeyValueRepository::class),
            $this->createMock(SerializerInterface::class),
            $this->createMock(WaterHeaterStatRepository::class),
            new \Symfony\Component\Filesystem\Filesystem(),
            new MockHttpClient(),
            $this->createMock(LoggerInterface::class),
            new ThermorStateParser(),
            new ThermorApiRateLimiter($this->createMock(KeyValueRepository::class), $this->createMock(LoggerInterface::class)),
        );
    }

    public function testTargetTemperatureOutsideTheManualRangeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->setTargetTemperature(49.0);
    }

    public function testUnknownDhwModeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->setDhwMode('turbo');
    }

    public function testUnknownSmartGridOptionIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->setSmartGridOption('turbo');
    }

    public function testUnknownOperatingRangeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->setOperatingRange('turbo');
    }

    public function testExpectedNumberOfShowerOutsideTheManualRangeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->setExpectedNumberOfShower(6);
    }

    public function testEmptyProgrammingSlotsAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->setProgrammingSlots([]);
    }

    public function testMalformedProgrammingSlotsAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->setProgrammingSlots(['slot1' => ['start' => '8h', 'end' => '20:00']]);
    }

    public function testMalformedSlotNamesAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->setProgrammingSlots(['window' => ['start' => '08:00', 'end' => '20:00']]);
    }
}
