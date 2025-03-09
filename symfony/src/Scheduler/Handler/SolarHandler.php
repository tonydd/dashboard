<?php

namespace App\Scheduler\Handler;

use App\Scheduler\Message\SolarMessage;
use App\Service\SolarService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SolarHandler
{
    public function __construct(private SolarService $solarService)
    {
    }

    public function __invoke(SolarMessage $message): void
    {
        $this->solarService->update();
    }
}