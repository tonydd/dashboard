<?php

namespace App\Tests\Service;

use App\Enum\KeyValueKey;
use App\Repository\KeyValueRepository;
use App\Service\ThermorScheduleService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ThermorScheduleServiceTest extends TestCase
{
    public function testPersistsDefaultScheduleWhenNoneExists(): void
    {
        $repository = $this->createMock(KeyValueRepository::class);
        $repository->expects($this->once())
            ->method('getValue')
            ->with(KeyValueKey::ThermorSchedule)
            ->willReturn(null);
        $repository->expects($this->once())
            ->method('setValue')
            ->with(KeyValueKey::ThermorSchedule, $this->callback(static function (array $schedule): bool {
                return $schedule['days']['monday'] === [['start' => '00:00', 'end' => '08:00']]
                    && count($schedule['days']) === 7;
            }));

        $scheduleService = new ThermorScheduleService($repository);
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
        $repository = $this->createMock(KeyValueRepository::class);
        $repository->expects($this->exactly(2))
            ->method('getValue')
            ->with(KeyValueKey::ThermorScheduleState)
            ->willReturn(['isOffPeak' => true]);
        $service = new ThermorScheduleService($repository);

        self::assertFalse($service->hasOffPeakStateChanged(true));
        self::assertTrue($service->hasOffPeakStateChanged(false));
    }

    public function testRemembersOffPeakStateInKeyValue(): void
    {
        $repository = $this->createMock(KeyValueRepository::class);
        $repository->expects($this->once())
            ->method('setValue')
            ->with(KeyValueKey::ThermorScheduleState, ['isOffPeak' => false]);

        $service = new ThermorScheduleService($repository);
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

    public function testSetScheduleValidatesAndPersistsInCanonicalOrder(): void
    {
        $repository = $this->createMock(KeyValueRepository::class);
        $repository->expects($this->once())
            ->method('setValue')
            ->with(KeyValueKey::ThermorSchedule, $this->callback(static function (array $schedule): bool {
                return array_keys($schedule['days']) === ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday']
                    && $schedule['days']['monday'] === [
                        ['start' => '00:00', 'end' => '05:00'],
                        ['start' => '22:00', 'end' => '23:30'],
                    ]
                    && $schedule['days']['sunday'] === [];
            }));

        $scheduleService = new ThermorScheduleService($repository);
        $days = array_fill_keys(
            ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'],
            [],
        );
        // Days deliberately out of canonical order, periods ordered and
        // non-overlapping.
        $days['monday'] = [
            ['start' => '00:00', 'end' => '05:00'],
            ['start' => '22:00', 'end' => '23:30'],
        ];

        $stored = $scheduleService->setSchedule(['days' => $days]);

        self::assertSame('00:00', $stored['days']['monday'][0]['start']);
        self::assertSame([], $stored['days']['sunday']);
    }

    public function testSetScheduleRejectsOverlappingPeriods(): void
    {
        $repository = $this->createMock(KeyValueRepository::class);
        $repository->expects($this->never())->method('setValue');

        $days = array_fill_keys(
            ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'],
            [],
        );
        $days['monday'] = [
            ['start' => '01:00', 'end' => '04:00'],
            ['start' => '03:00', 'end' => '05:00'],
        ];

        $this->expectException(\InvalidArgumentException::class);
        (new ThermorScheduleService($repository))->setSchedule(['days' => $days]);
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
        $repository = $this->createMock(KeyValueRepository::class);
        $repository->method('getValue')->willReturn($schedule);

        return new ThermorScheduleService($repository);
    }
}
