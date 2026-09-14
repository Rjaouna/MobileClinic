<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\ProductReservationManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:product-reservations:sync-expired',
    description: 'Annule les réservations boutique expirées et rembourse la fidélité utilisée.',
)]
final class SyncExpiredProductReservationsCommand extends Command
{
    public function __construct(private readonly ProductReservationManager $reservationManager)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $result = $this->reservationManager->expireOverdueReservations();

        $io->success(sprintf(
            '%d réservation(s) boutique expirée(s), %s remboursé(s) sur les cartes fidélité.',
            $result['expired'],
            number_format($result['refunded_cents'] / 100, 2, ',', ' ').' €',
        ));

        return Command::SUCCESS;
    }
}
