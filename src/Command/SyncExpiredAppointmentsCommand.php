<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\AppointmentScheduler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:appointments:sync-expired',
    description: 'Passe les rendez-vous actifs expirés en statut client pas présenté.',
)]
final class SyncExpiredAppointmentsCommand extends Command
{
    public function __construct(private readonly AppointmentScheduler $appointmentScheduler)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $count = $this->appointmentScheduler->syncExpiredAppointments();

        $io->success(sprintf('%d rendez-vous expiré(s) synchronisé(s).', $count));

        return Command::SUCCESS;
    }
}
