<?php
namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\String\Slugger\SluggerInterface;

#[AsCommand(
    name: 'app:reslug-data',
    description: 'Regénère les slugs des tables ingredient, recipe, tag, unit.',
)]
class ReslugDataCommand extends Command
{
    public function __construct(
        private readonly Connection       $connection,
        private readonly SluggerInterface $slugger
    )
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $tables = ['ingredient' => 'name', 'recipe' => 'name', 'tag' => 'code', 'unit' => 'name'];

        foreach ($tables as $table => $col) {
            $rows = $this->connection->fetchAllAssociative("SELECT id, $col FROM $table");

            foreach ($rows as $row) {
                $slug = $this->slugger->slug($row[$col])->toString();

                $this->connection->executeStatement(
                    "UPDATE $table SET slug = :slug WHERE id = :id",
                    ['slug' => $slug, 'id' => $row['id']]
                );
            }

            $output->writeln("✅ Slugs mis à jour pour <info>$table</info>");
        }

        return Command::SUCCESS;
    }
}
