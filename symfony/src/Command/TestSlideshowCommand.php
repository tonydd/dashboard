<?php
namespace App\Command;

use App\Enum\KeyValueKey;
use App\Repository\KeyValueRepository;
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
        private readonly KeyValueRepository $keyValueRepository,
        private readonly string $backgroundPath
    )
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln("Vérification du slideshow...");
        $output->writeln("Chemin des fonds d'écran : <info>{$this->backgroundPath}</info>");

        $storedValue = $this->keyValueRepository->getValue(KeyValueKey::Background);
        $output->writeln('Valeur actuelle du slideshow : <info>' . json_encode($storedValue, JSON_THROW_ON_ERROR) . '</info>');

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
