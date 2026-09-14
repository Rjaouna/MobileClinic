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
    name: 'app:appointments:send-reminders',
    description: 'Crée, renforce et résout les rappels administratifs des rendez-vous passés.',
)]
final class SendAppointmentRemindersCommand extends Command
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
            '%d rappel(s) créé(s), %d renforcé(s), %d résolu(s), %d actif(s).',
            $result['created'],
            $result['updated'],
            $result['resolved'],
            $result['active'],
        ));

        return Command::SUCCESS;
    }
}
