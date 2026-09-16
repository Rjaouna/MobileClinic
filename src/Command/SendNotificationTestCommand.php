<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\CustomerNotificationMailer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:notifications:test',
    description: 'Envoie le modèle de test des notifications Mobile Clinic.',
)]
final class SendNotificationTestCommand extends Command
{
    public function __construct(private readonly CustomerNotificationMailer $notificationMailer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('recipient', InputArgument::OPTIONAL, 'Adresse de destination (l’adresse admin est utilisée par défaut).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $recipient = $input->getArgument('recipient');
        $recipient = is_string($recipient) && trim($recipient) !== '' ? trim($recipient) : null;

        if (!$this->notificationMailer->sendTestNotification($recipient)) {
            $io->error('La notification n’a pas pu être envoyée. Consultez les logs Symfony.');

            return Command::FAILURE;
        }

        $io->success('La notification de test a été envoyée.');

        return Command::SUCCESS;
    }
}
