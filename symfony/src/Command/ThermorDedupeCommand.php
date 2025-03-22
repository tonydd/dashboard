<?php

namespace App\Command;

use App\Entity\SolarStat;
use App\Entity\WaterHeaterStat;
use App\Repository\WaterHeaterStatRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:thermor:dedupe',
    description: 'Add a short description for your command',
)]
class ThermorDedupeCommand extends Command
{
    public function __construct(private readonly WaterHeaterStatRepository $repository)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var WaterHeaterStat[] $entries */
        $entries = $this->repository->findAll();
        $toDeleteIds = [];

        $previousEntry = null;

        foreach ($entries as $entry) {
            if ($previousEntry
                && $previousEntry->getWh() === $entry->getWh()
                && $previousEntry->getWaterTemperature() === $entry->getWaterTemperature()
                && $previousEntry->getAvailable40Degrees() === $entry->getAvailable40Degrees()
                && $previousEntry->isHeatingState() === $entry->isHeatingState()
            ) {
                $toDeleteIds[] = $entry->getId();
            }

            $previousEntry = $entry;
        }

        // dd($toDeleteIds, count($toDeleteIds), count($entries));
        $this->repository->deleteByIds($toDeleteIds);

        return Command::SUCCESS;
    }
}
