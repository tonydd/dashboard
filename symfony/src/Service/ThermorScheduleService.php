<?php

namespace App\Service;

use App\Enum\KeyValueKey;
use App\Repository\KeyValueRepository;

final readonly class ThermorScheduleService
{
    private const DAYS = [
        1 => 'monday',
        2 => 'tuesday',
        3 => 'wednesday',
        4 => 'thursday',
        5 => 'friday',
        6 => 'saturday',
        7 => 'sunday',
    ];

    public function __construct(
        private KeyValueRepository $keyValueRepository,
        private string $appTimezone = 'Europe/Paris',
    ) {
    }

    public function isOffPeakAt(?\DateTimeImmutable $dateTime = null): bool
    {
        $timeZone = new \DateTimeZone($this->appTimezone);
        $dateTime = ($dateTime ?? new \DateTimeImmutable('now', $timeZone))->setTimezone($timeZone);
        $day = self::DAYS[(int) $dateTime->format('N')];
        $currentMinute = ((int) $dateTime->format('G') * 60) + (int) $dateTime->format('i');

        foreach ($this->getSchedule()['days'][$day] as $period) {
            $startMinute = $this->timeToMinutes($period['start']);
            $endMinute = $this->timeToMinutes($period['end']);

            if ($currentMinute >= $startMinute && $currentMinute < $endMinute) {
                return true;
            }
        }

        return false;
    }

    public function hasOffPeakStateChanged(bool $isOffPeak): bool
    {
        $value = $this->keyValueRepository->getValue(KeyValueKey::ThermorScheduleState);
        if ($value === null) {
            return true;
        }

        if (!is_array($value)
            || array_keys($value) !== ['isOffPeak']
            || !is_bool($value['isOffPeak'])
        ) {
            throw new \InvalidArgumentException('The stored Thermor schedule state is invalid.');
        }

        return $value['isOffPeak'] !== $isOffPeak;
    }

    public function rememberOffPeakState(bool $isOffPeak): void
    {
        $this->keyValueRepository->setValue(KeyValueKey::ThermorScheduleState, ['isOffPeak' => $isOffPeak]);
    }

    public function getSchedule(): array
    {
        $schedule = $this->keyValueRepository->getValue(KeyValueKey::ThermorSchedule);
        if ($schedule === null) {
            $schedule = $this->defaultSchedule();
            $this->keyValueRepository->setValue(KeyValueKey::ThermorSchedule, $schedule);

            return $schedule;
        }

        if (!is_array($schedule)) {
            throw new \InvalidArgumentException('The stored Thermor schedule must be a JSON object.');
        }

        $this->validateSchedule($schedule);

        return $schedule;
    }

    private function defaultSchedule(): array
    {
        return [
            'days' => array_fill_keys(
                self::DAYS,
                [['start' => '00:00', 'end' => '08:00']],
            ),
        ];
    }

    private function validateSchedule(array $schedule): void
    {
        if (array_keys($schedule) !== ['days'] || !is_array($schedule['days'])) {
            throw new \InvalidArgumentException('The Thermor schedule must contain a days object.');
        }

        $days = $schedule['days'];
        if (count($days) !== count(self::DAYS) || array_diff(array_values(self::DAYS), array_keys($days)) !== []) {
            throw new \InvalidArgumentException('The Thermor schedule must define all seven days.');
        }

        foreach (self::DAYS as $day) {
            $periods = $days[$day];
            if (!is_array($periods) || !array_is_list($periods)) {
                throw new \InvalidArgumentException(sprintf('The periods for %s must be a JSON list.', $day));
            }

            $previousEnd = -1;
            foreach ($periods as $period) {
                if (!is_array($period)
                    || count($period) !== 2
                    || !array_key_exists('start', $period)
                    || !array_key_exists('end', $period)
                    || !is_string($period['start'])
                    || !is_string($period['end'])
                ) {
                    throw new \InvalidArgumentException(sprintf('Each period for %s must define start and end times.', $day));
                }

                $start = $this->timeToMinutes($period['start']);
                $end = $this->timeToMinutes($period['end']);
                if ($start >= $end) {
                    throw new \InvalidArgumentException(sprintf('Each period for %s must end after it starts.', $day));
                }
                if ($start < $previousEnd) {
                    throw new \InvalidArgumentException(sprintf('The periods for %s must not overlap or be out of order.', $day));
                }

                $previousEnd = $end;
            }
        }
    }

    private function timeToMinutes(string $time): int
    {
        if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $time) !== 1) {
            throw new \InvalidArgumentException(sprintf('Invalid schedule time "%s"; expected HH:MM.', $time));
        }

        [$hour, $minute] = array_map('intval', explode(':', $time));

        return ($hour * 60) + $minute;
    }
}
