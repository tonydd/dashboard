<?php

namespace App\Scheduler;

use App\Scheduler\Message\FuelMessage;
use App\Scheduler\Message\SolarMessage;
use App\Scheduler\Message\ThermorMessage;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

#[AsSchedule]
class ScheduleProvider implements ScheduleProviderInterface
{
    public function getSchedule(): Schedule
    {
        return (new Schedule())->add(
            RecurringMessage::every(3600, new FuelMessage()),
            RecurringMessage::every(100, new SolarMessage()),
            RecurringMessage::every(240, new ThermorMessage()),
        );
    }
}