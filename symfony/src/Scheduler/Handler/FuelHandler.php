<?php

namespace App\Scheduler\Handler;

use App\Entity\FuelStat;
use App\Scheduler\Message\FuelMessage;
use App\Service\FuelService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class FuelHandler
{
    public function __construct(private FuelService $fuelService)
    {
    }

    public function __invoke(FuelMessage $fuelMessage): void
    {
        $this->fuelService->update();
    }
}
