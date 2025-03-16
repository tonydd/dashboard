<?php

namespace App\Command;

use App\Service\WaterQualityService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:water-quality',
    description: 'Add a short description for your command',
)]
class WaterQualityCommand extends Command
{
    public function __construct(private readonly WaterQualityService $waterQualityService)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $this->waterQualityService->update();

        return Command::SUCCESS;
    }
}
