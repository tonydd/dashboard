<?php

namespace App\Tests\Service;

use App\Entity\CurrentStatValue;
use App\Enum\StatValueType;
use App\Repository\CurrentStatValueRepository;
use App\Service\ThermorScheduleService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ThermorScheduleServiceTest extends TestCase
{
    public function testPersistsDefaultScheduleWhenNoneExists(): void
    {
        $repository = $this->createMock(CurrentStatValueRepository::class);
        $repository->method('findOneBy')->willReturn(null);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())
            ->method('persist')
            ->with($this->callback(static function (CurrentStatValue $value): bool {
                return $value->getType() === StatValueType::ThermorSchedule
                    && $value->getValue()['days']['monday'] === [['start' => '00:00', 'end' => '08:00']]
                    && count($value->getValue()['days']) === 7;
            }));
        $entityManager->expects($this->once())->method('flush');

        $scheduleService = new ThermorScheduleService($repository, $entityManager);
        $schedule = $scheduleService->getSchedule();

        self::assertSame([['start' => '00:00', 'end' => '08:00']], $schedule['days']['sunday']);
    }

    public function testSupportsMultiplePeriodsAndHalfOpenBoundaries(): void
    {
        $schedule = ['days' => array_fill_keys(
            ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'],
            [],
        )];
        $schedule['days']['monday'] = [
            ['start' => '00:00', 'end' => '08:00'],
            ['start' => '12:00', 'end' => '14:00'],
        ];
        $scheduleService = $this->serviceWithSchedule($schedule);

        $timeZone = new \DateTimeZone('Europe/Paris');
        self::assertTrue($scheduleService->isOffPeakAt(new \DateTimeImmutable('2026-11-23 07:59:00', $timeZone)));
        self::assertFalse($scheduleService->isOffPeakAt(new \DateTimeImmutable('2026-11-23 08:00:00', $timeZone)));
        self::assertTrue($scheduleService->isOffPeakAt(new \DateTimeImmutable('2026-11-23 12:00:00', $timeZone)));
        self::assertFalse($scheduleService->isOffPeakAt(new \DateTimeImmutable('2026-11-23 14:00:00', $timeZone)));
    }

    public function testOffPeakStateChangesOnlyWhenStoredConditionDiffers(): void
    {
        $state = (new CurrentStatValue())
            ->setType(StatValueType::ThermorScheduleState)
            ->setValue(['isOffPeak' => true]);
        $repository = $this->createMock(CurrentStatValueRepository::class);
        $repository->expects($this->exactly(2))
            ->method('findOneBy')
            ->with(['type' => StatValueType::ThermorScheduleState])
            ->willReturn($state);
        $service = new ThermorScheduleService(
            $repository,
            $this->createMock(EntityManagerInterface::class),
        );

        self::assertFalse($service->hasOffPeakStateChanged(true));
        self::assertTrue($service->hasOffPeakStateChanged(false));
    }

    public function testRemembersOffPeakStateInCurrentStatValue(): void
    {
        $repository = $this->createMock(CurrentStatValueRepository::class);
        $repository->expects($this->once())
            ->method('findOneBy')
            ->with(['type' => StatValueType::ThermorScheduleState])
            ->willReturn(null);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())
            ->method('persist')
            ->with($this->callback(static function (CurrentStatValue $value): bool {
                return $value->getType() === StatValueType::ThermorScheduleState
                    && $value->getValue() === ['isOffPeak' => false];
            }));
        $entityManager->expects($this->once())->method('flush');

        $service = new ThermorScheduleService($repository, $entityManager);
        $service->rememberOffPeakState(false);
    }

    #[DataProvider('invalidSchedules')]
    public function testRejectsInvalidPeriods(array $periods): void
    {
        $schedule = ['days' => array_fill_keys(
            ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'],
            [],
        )];
        $schedule['days']['monday'] = $periods;

        $this->expectException(\InvalidArgumentException::class);
        $this->serviceWithSchedule($schedule)->getSchedule();
    }

    public static function invalidSchedules(): iterable
    {
        yield 'invalid time' => [[['start' => '8:00', 'end' => '09:00']]];
        yield 'zero duration' => [[['start' => '09:00', 'end' => '09:00']]];
        yield 'overnight range' => [[['start' => '23:00', 'end' => '01:00']]];
        yield 'overlapping ranges' => [[
            ['start' => '01:00', 'end' => '04:00'],
            ['start' => '03:00', 'end' => '05:00'],
        ]];
    }

    private function serviceWithSchedule(array $schedule): ThermorScheduleService
    {
        $value = (new CurrentStatValue())
            ->setType(StatValueType::ThermorSchedule)
            ->setValue($schedule);
        $repository = $this->createMock(CurrentStatValueRepository::class);
        $repository->method('findOneBy')->willReturn($value);

        return new ThermorScheduleService(
            $repository,
            $this->createMock(EntityManagerInterface::class),
        );
    }
}
