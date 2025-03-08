<?php

namespace App\Command;

use App\Service\ThermorService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:thermor',
    description: 'Add a short description for your command',
)]
class ThermorCommand extends Command
{
    public function __construct(private readonly ThermorService $thermorService)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $this->thermorService->update();
        return Command::SUCCESS;
    }
}
