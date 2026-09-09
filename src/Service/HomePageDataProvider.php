<?php

declare(strict_types=1);

namespace App\Service;

final class HomePageDataProvider
{
    public function __construct(private readonly AppointmentScheduler $appointmentScheduler)
    {
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    public function getData(array $overrides = []): array
    {
        return array_replace([
            'devices' => $this->appointmentScheduler->getDeviceChoices(),
            'problems' => $this->appointmentScheduler->getProblemChoices(),
            'slots' => $this->appointmentScheduler->getBookableSlots(),
            'slot_days' => $this->appointmentScheduler->getBookableSlotDays(),
            'services' => [
                [
                    'icon' => 'tool',
                    'title' => 'Réparation rapide',
                    'text' => 'Écran, batterie, connecteur ou diagnostic : choisissez votre créneau en quelques instants.',
                    'href' => '#rendez-vous',
                    'action_label' => 'Réserver',
                ],
                [
                    'icon' => 'bag',
                    'title' => 'Articles en boutique',
                    'text' => 'Mettez un accessoire ou une protection de côté et récupérez-le au moment qui vous arrange.',
                    'href' => '#recherche',
                    'action_label' => 'Voir les articles',
                ],
                [
                    'icon' => 'spark',
                    'title' => 'Fidélité client',
                    'text' => 'Vos avantages restent rattachés à votre espace client après chaque passage.',
                    'href' => '#avantages',
                    'action_label' => 'Découvrir',
                ],
            ],
            'appointment_data' => [],
            'appointment_errors' => [],
            'last_username' => '',
            'login_error' => null,
            'open_modal' => null,
        ], $overrides);
    }
}
