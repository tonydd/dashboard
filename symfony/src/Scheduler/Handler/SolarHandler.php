<?php

namespace App\Scheduler\Handler;

use App\Scheduler\Message\SolarMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsMessageHandler]
final readonly class SolarHandler
{
    public function __construct()
    {
    }

    public function __invoke(SolarMessage $message): void
    {

    }
}