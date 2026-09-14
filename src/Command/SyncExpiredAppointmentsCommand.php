<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\AppointmentReminderManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:appointments:sync-expired',
    description: 'Compatibilité : détecte les rendez-vous passés et crée les rappels administratifs.',
)]
final class SyncExpiredAppointmentsCommand extends Command
{
    public function __construct(private readonly AppointmentReminderManager $appointmentReminderManager)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $result = $this->appointmentReminderManager->refreshReminders();

        $io->success(sprintf(
            '%d rappel(s) créé(s), %d renforcé(s), %d résolu(s).',
            $result['created'],
            $result['updated'],
            $result['resolved'],
        ));

        return Command::SUCCESS;
    }
}
