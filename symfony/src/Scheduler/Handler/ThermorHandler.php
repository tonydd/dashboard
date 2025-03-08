<?php

namespace App\Scheduler\Handler;

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
        $this->thermorService->update();
    }
}