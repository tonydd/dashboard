<?php

namespace App\Command;

use App\Entity\DashboardUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:dashboard:set-password',
    description: 'Create or update the dashboard password',
)]
class SetDashboardPasswordCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $password = $io->askHidden('Mot de passe du tableau de bord');

        if (!is_string($password) || $password === '') {
            $io->error('Le mot de passe ne peut pas être vide.');

            return Command::INVALID;
        }

        $confirmation = $io->askHidden('Confirmez le mot de passe');
        if (!is_string($confirmation) || !hash_equals($password, $confirmation)) {
            $io->error('Les mots de passe ne correspondent pas.');

            return Command::INVALID;
        }

        $repository = $this->entityManager->getRepository(DashboardUser::class);
        $user = $repository->findOneBy(['username' => 'tablet']) ?? new DashboardUser();
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $io->success('Le mot de passe du tableau de bord a été enregistré.');

        return Command::SUCCESS;
    }
}
