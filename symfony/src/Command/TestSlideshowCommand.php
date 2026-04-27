<?php
namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:check-slideshow',
    description: 'Vérifie l\'état des slideshows.',
)]
class TestSlideshowCommand extends Command
{
    public function __construct(
        private readonly Connection       $connection,
        private readonly string $backgroundPath
    )
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln("Vérification du slideshow...");
        $output->writeln("Chemin des fonds d'écran : <info>{$this->backgroundPath}</info>");

        $statValue = $this->connection->fetchOne("SELECT value FROM current_stat_value WHERE type = 'day_image'");
        $output->writeln("Valeur actuelle du slideshow : <info>$statValue</info>");

        $backgrounds = [];
        foreach (scandir($this->backgroundPath) as $file) {
            if (str_ends_with($file, '.jpg')) {
                $backgrounds[] = $file;
            }
        }

        $output->writeln("Fonds d'écran disponibles : " . count($backgrounds));

        return Command::SUCCESS;
    }
}
