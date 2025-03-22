<?php

namespace App\Command;

use App\Entity\FuelStat;
use App\Repository\FuelStatRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:fuel:dedupe',
    description: 'Add a short description for your command',
)]
class FuelDedupeCommand extends Command
{
    public function __construct(private readonly FuelStatRepository $repository)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var FuelStat[] $entries */
        $entries = $this->repository->findBy([], ['type' => 'ASC']);
        $toDeleteIds = [];

        $previousEntry = null;

        foreach ($entries as $entry) {
            if ($previousEntry && $previousEntry->getType() === $entry->getType() && $previousEntry->getPrice() === $entry->getPrice()) {
                $toDeleteIds[] = $entry->getId();
            }

            $previousEntry = $entry;
        }

        $this->repository->deleteByIds($toDeleteIds);

        return Command::SUCCESS;
    }
}
