<?php

namespace App\Command;

use App\Entity\FuelStat;
use App\Entity\SolarStat;
use App\Repository\FuelStatRepository;
use App\Repository\SolarStatRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:solar:dedupe',
    description: 'Add a short description for your command',
)]
class SolarDedupeCommand extends Command
{
    public function __construct(private readonly SolarStatRepository $repository)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var SolarStat[] $entries */
        $entries = $this->repository->findAll();
        $toDeleteIds = [];

        $previousEntry = null;

        foreach ($entries as $entry) {
            if ($previousEntry && $previousEntry->getProduction() === $entry->getProduction()) {
                $toDeleteIds[] = $entry->getId();
            }

            $previousEntry = $entry;
        }

        //dd($toDeleteIds, count($toDeleteIds), count($entries));
        $this->repository->deleteByIds($toDeleteIds);

        return Command::SUCCESS;
    }
}
