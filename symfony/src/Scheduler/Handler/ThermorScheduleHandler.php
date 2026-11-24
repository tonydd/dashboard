<?php

namespace App\Scheduler\Handler;

use App\Scheduler\Message\ThermorScheduleMessage;
use App\Service\ThermorScheduleService;
use App\Service\ThermorService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ThermorScheduleHandler
{
    public function __construct(
        private ThermorScheduleService $scheduleService,
        private ThermorService $thermorService,
    ) {
    }

    public function __invoke(ThermorScheduleMessage $message): void
    {
        $isOffPeak = $this->scheduleService->isOffPeakAt();
        $this->thermorService->reconcileAbsenceMode(!$isOffPeak);
    }
}
