<?php

namespace App\Scheduler\Handler;

use App\Scheduler\Message\WaterQualityMessage;
use App\Service\WaterQualityService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class WaterQualityHandler
{
    public function __construct(private WaterQualityService $waterQualityService)
    {
    }

    public function __invoke(WaterQualityMessage $message): void
    {
        $this->waterQualityService->update();
    }
}