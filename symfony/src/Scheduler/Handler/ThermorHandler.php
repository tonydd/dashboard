<?php

namespace App\Scheduler\Handler;

use App\Exception\ThermorRateLimitedException;
use App\Scheduler\Message\ThermorMessage;
use App\Service\ThermorService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ThermorHandler
{
    public function __construct(private ThermorService $thermorService)
    {
    }

    public function __invoke(ThermorMessage $message): void
    {
        try {
            $this->thermorService->update();
        } catch (ThermorRateLimitedException) {
            // Drop this tick: the global API budget is exhausted or a
            // backoff is active. The next scheduled run refreshes the data.
        }
    }
}
