<?php

namespace App\Scheduler\Handler;

use App\Exception\ThermorRateLimitedException;
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
        if (!$this->scheduleService->hasOffPeakStateChanged($isOffPeak)) {
            return;
        }

        try {
            $this->thermorService->reconcileAbsenceMode(!$isOffPeak);
        } catch (ThermorRateLimitedException) {
            // Not applied yet: do not remember the state so the next tick retries.
            return;
        }

        $this->scheduleService->rememberOffPeakState($isOffPeak);
    }
}
